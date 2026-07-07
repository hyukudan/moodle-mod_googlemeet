<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Backfill timestamped AI chapters for recordings with completed analysis.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_googlemeet\ai_service;
use mod_googlemeet\gemini_transient_exception;

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

const GOOGLEMEET_BACKFILL_CHAPTERS_BACKOFF = [30, 120, 300];

list($options, $unrecognized) = cli_get_params([
    'googlemeetid' => 0,
    'recordingid' => 0,
    'limit' => 0,
    'pause' => 10,
    'force' => false,
    'dry-run' => false,
    'help' => false,
], [
    'g' => 'googlemeetid',
    'r' => 'recordingid',
    'l' => 'limit',
    'p' => 'pause',
    'f' => 'force',
    'd' => 'dry-run',
    'h' => 'help',
]);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error("Unrecognised options:\n  {$unrecognized}");
}

if (!empty($options['help'])) {
    cli_writeln("Backfill timestamped AI chapters for Google Meet recordings.

Options:
  -g, --googlemeetid=ID   Process only one Google Meet activity (optional)
  -r, --recordingid=ID    Process only one recording row id (optional)
  -l, --limit=N           Maximum eligible recordings to process in this run
  -p, --pause=N           Seconds to sleep between Gemini calls (default: 10)
  -f, --force             Regenerate even when chapters already exist
  -d, --dry-run           List eligible recordings without calling Gemini
  -h, --help              Show this help

Examples:
  php mod/googlemeet/cli/backfill_chapters.php --dry-run
  php mod/googlemeet/cli/backfill_chapters.php --limit=10
  php mod/googlemeet/cli/backfill_chapters.php --googlemeetid=6 --limit=5
");
    exit(0);
}

$dbman = $DB->get_manager();
$table = new xmldb_table('googlemeet_ai_analysis');
$field = new xmldb_field('chapters');
if (!$dbman->field_exists($table, $field)) {
    cli_error('The googlemeet_ai_analysis.chapters column is not available yet. Run this only after the F3 upgrade.');
}

$dryrun = !empty($options['dry-run']);
$force = !empty($options['force']);
$limit = max(0, (int)$options['limit']);
$pause = max(0, (int)$options['pause']);

$admin = get_admin();
if ($admin) {
    \core\session\manager::set_user($admin);
}

$service = new ai_service();
if (!$service->is_available()) {
    cli_error(get_string('ai_not_configured', 'googlemeet'));
}

$where = 'r.deleted = 0 AND a.status = :status';
$params = ['status' => 'completed'];
if (!empty($options['googlemeetid'])) {
    $where .= ' AND r.googlemeetid = :googlemeetid';
    $params['googlemeetid'] = (int)$options['googlemeetid'];
}
if (!empty($options['recordingid'])) {
    $where .= ' AND r.id = :recordingid';
    $params['recordingid'] = (int)$options['recordingid'];
}

$sql = "SELECT r.id,
               r.googlemeetid,
               r.name,
               r.duration,
               r.createdtime,
               r.transcripttext,
               a.transcript,
               a.chapters,
               a.language
          FROM {googlemeet_recordings} r
          JOIN {googlemeet_ai_analysis} a ON a.recordingid = r.id
         WHERE {$where}
      ORDER BY r.googlemeetid ASC, r.createdtime ASC, r.id ASC";
$recordings = $DB->get_records_sql($sql, $params);

if (!$recordings) {
    cli_writeln('No active recordings with completed AI analysis were found.');
    exit(0);
}

$eligible = [];
$skippedwithchapters = 0;
$skippedwithouttranscript = 0;
foreach ($recordings as $recording) {
    if (!$force && googlemeet_backfill_chapters_has_chapters($recording->chapters ?? '')) {
        $skippedwithchapters++;
        continue;
    }

    $transcript = googlemeet_backfill_chapters_transcript($recording);
    if ($transcript === '') {
        $skippedwithouttranscript++;
        continue;
    }

    $eligible[] = $recording;
    if ($limit > 0 && count($eligible) >= $limit) {
        break;
    }
}

$totaleligible = count($eligible);
cli_writeln("Eligible recordings for chapter generation: {$totaleligible}");
if ($skippedwithchapters > 0) {
    cli_writeln("Skipped recordings that already have chapters: {$skippedwithchapters}");
}
if ($skippedwithouttranscript > 0) {
    cli_writeln("Skipped recordings without usable transcript: {$skippedwithouttranscript}");
}

if ($totaleligible === 0) {
    exit(0);
}

if ($dryrun) {
    foreach ($eligible as $index => $recording) {
        $num = $index + 1;
        cli_writeln("DRY-RUN [{$num}/{$totaleligible}] recording {$recording->id} (gmid {$recording->googlemeetid}): "
            . $recording->name);
    }
    exit(0);
}

$processed = 0;
$chaptertotal = 0;
$errors = 0;

foreach ($eligible as $index => $recording) {
    $num = $index + 1;
    cli_writeln("[{$num}/{$totaleligible}] Recording {$recording->id} (gmid {$recording->googlemeetid}): {$recording->name}");

    try {
        $chapters = googlemeet_backfill_chapters_generate_with_retries($service, (int)$recording->id);
        $processed++;
        $chaptertotal += count($chapters);
        cli_writeln('  Saved ' . count($chapters) . ' chapter(s).');
    } catch (gemini_transient_exception $e) {
        $errors++;
        cli_writeln('  Transient Gemini limit/error after retries: ' . $e->getMessage());
        googlemeet_backfill_chapters_print_resume_command($options);
        break;
    } catch (Throwable $e) {
        $errors++;
        cli_writeln('  ERROR: ' . $e->getMessage());
    }

    if ($pause > 0 && $num < $totaleligible) {
        cli_writeln("  Sleeping {$pause}s before the next Gemini call...");
        sleep($pause);
    }
}

cli_writeln('');
cli_writeln("Chapter backfill finished. Processed recordings: {$processed}. Chapters saved: {$chaptertotal}. Errors: {$errors}.");
if ($processed < $totaleligible) {
    cli_writeln('There are eligible recordings left for a later run.');
    googlemeet_backfill_chapters_print_resume_command($options);
}

/**
 * Whether a stored chapters value already contains usable chapters.
 *
 * @param string|null $raw Stored JSON.
 * @return bool
 */
function googlemeet_backfill_chapters_has_chapters(?string $raw): bool {
    $raw = trim((string)$raw);
    if ($raw === '') {
        return false;
    }
    $decoded = json_decode($raw);
    if (is_object($decoded) && isset($decoded->chapters)) {
        $decoded = $decoded->chapters;
    }
    return is_array($decoded);
}

/**
 * Pick the best transcript source for a row.
 *
 * @param stdClass $recording Recording/analysis row.
 * @return string Transcript text.
 */
function googlemeet_backfill_chapters_transcript(stdClass $recording): string {
    $transcript = trim((string)($recording->transcripttext ?? ''));
    if ($transcript === '') {
        $transcript = trim((string)($recording->transcript ?? ''));
    }

    if ($transcript === '') {
        return '';
    }

    return $transcript;
}

/**
 * Generate chapters with bounded backoff for transient Gemini failures.
 *
 * @param ai_service $service AI service.
 * @param int $recordingid Recording id.
 * @return array Generated chapters.
 */
function googlemeet_backfill_chapters_generate_with_retries(ai_service $service, int $recordingid): array {
    $attempt = 0;
    while (true) {
        try {
            return $service->generate_chapters_for_recording($recordingid);
        } catch (gemini_transient_exception $e) {
            if (!array_key_exists($attempt, GOOGLEMEET_BACKFILL_CHAPTERS_BACKOFF)) {
                throw $e;
            }
            $delay = GOOGLEMEET_BACKFILL_CHAPTERS_BACKOFF[$attempt];
            $attempt++;
            cli_writeln("  Transient Gemini error: {$e->getMessage()}");
            cli_writeln("  Backing off {$delay}s before retry {$attempt}...");
            sleep($delay);
        }
    }
}

/**
 * Print an exact command suitable for continuing the idempotent backfill.
 *
 * @param array $options CLI options.
 * @return void
 */
function googlemeet_backfill_chapters_print_resume_command(array $options): void {
    $parts = ['php', 'mod/googlemeet/cli/backfill_chapters.php'];
    foreach (['googlemeetid', 'recordingid', 'limit', 'pause'] as $key) {
        if (!empty($options[$key])) {
            $parts[] = '--' . $key . '=' . (int)$options[$key];
        }
    }
    if (!empty($options['force'])) {
        $parts[] = '--force';
    }
    cli_writeln('Continue with: cd /path/to/moodle/public && ' . implode(' ', $parts));
}
