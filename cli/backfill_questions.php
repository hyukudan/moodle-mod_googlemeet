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
 * Backfill draft AI practice questions for recordings with completed analysis.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_googlemeet\ai_service;
use mod_googlemeet\gemini_transient_exception;
use mod_googlemeet\question_service;

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

const GOOGLEMEET_BACKFILL_BACKOFF = [30, 120, 300];

list($options, $unrecognized) = cli_get_params([
    'googlemeetid' => 0,
    'recordingid' => 0,
    'limit' => 0,
    'count' => 10,
    'pause' => 10,
    'dry-run' => false,
    'help' => false,
], [
    'g' => 'googlemeetid',
    'r' => 'recordingid',
    'l' => 'limit',
    'c' => 'count',
    'p' => 'pause',
    'd' => 'dry-run',
    'h' => 'help',
]);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error("Unrecognised options:\n  {$unrecognized}");
}

if (!empty($options['help'])) {
    cli_writeln("Backfill draft AI practice questions for Google Meet recordings.

Options:
  -g, --googlemeetid=ID   Process only one Google Meet activity (optional)
  -r, --recordingid=ID    Process only one recording row id (optional)
  -l, --limit=N           Maximum eligible recordings to process in this run
  -c, --count=N           Questions requested per recording, 1-20 (default: 10)
  -p, --pause=N           Seconds to sleep between Gemini calls (default: 10)
  -d, --dry-run           List eligible recordings without generating questions
  -h, --help              Show this help

Examples:
  php mod/googlemeet/cli/backfill_questions.php --dry-run
  php mod/googlemeet/cli/backfill_questions.php --limit=10
  php mod/googlemeet/cli/backfill_questions.php --googlemeetid=6 --limit=5
");
    exit(0);
}

$dryrun = !empty($options['dry-run']);
$limit = max(0, (int)$options['limit']);
$count = max(1, min(20, (int)$options['count']));
$pause = max(0, (int)$options['pause']);

$admin = get_admin();
if ($admin) {
    \core\session\manager::set_user($admin);
}

$service = new ai_service();
if (!$service->is_available()) {
    cli_error(get_string('ai_not_configured', 'googlemeet'));
}
$questionservice = new question_service();

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
               r.createdtime,
               gm.course
          FROM {googlemeet_recordings} r
          JOIN {googlemeet_ai_analysis} a ON a.recordingid = r.id
          JOIN {googlemeet} gm ON gm.id = r.googlemeetid
         WHERE {$where}
      ORDER BY gm.id ASC, r.createdtime ASC, r.id ASC";
$recordings = $DB->get_records_sql($sql, $params);

if (!$recordings) {
    cli_writeln('No active recordings with completed AI analysis were found.');
    exit(0);
}

$eligible = [];
$skippedwithquestions = 0;
foreach ($recordings as $recording) {
    [$googlemeet, $cm, $context] = googlemeet_backfill_resolve_context($recording);
    $questions = $questionservice->get_questions($googlemeet, $cm, $context, (int)$recording->id, false);
    if (count($questions) > 0) {
        $skippedwithquestions++;
        continue;
    }
    $eligible[] = $recording;
    if ($limit > 0 && count($eligible) >= $limit) {
        break;
    }
}

$totaleligible = count($eligible);
cli_writeln("Eligible recordings with completed analysis and 0 questions: {$totaleligible}");
if ($skippedwithquestions > 0) {
    cli_writeln("Skipped recordings that already have questions: {$skippedwithquestions}");
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
$createdtotal = 0;
$errors = 0;

foreach ($eligible as $index => $recording) {
    $num = $index + 1;
    cli_writeln("[{$num}/{$totaleligible}] Recording {$recording->id} (gmid {$recording->googlemeetid}): {$recording->name}");

    try {
        $created = googlemeet_backfill_generate_with_retries($service, (int)$recording->id, $count);
        $processed++;
        $createdtotal += $created;

        [$googlemeet, $cm, $context] = googlemeet_backfill_resolve_context($recording);
        $counts = googlemeet_backfill_question_counts($questionservice, $googlemeet, $cm, $context, (int)$recording->id);
        cli_writeln("  Created {$created} draft question(s). Current total={$counts['total']}, draft={$counts['draft']}, "
            . "published={$counts['ready']}.");
        if ($counts['ready'] > 0) {
            cli_error("Recording {$recording->id} has published questions after backfill; aborting visibility check.");
        }
    } catch (gemini_transient_exception $e) {
        $errors++;
        cli_writeln('  Transient Gemini limit/error after retries: ' . $e->getMessage());
        googlemeet_backfill_print_resume_command($options);
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
cli_writeln("Backfill finished. Processed recordings: {$processed}. Draft questions created: {$createdtotal}. Errors: {$errors}.");
if ($processed < $totaleligible) {
    cli_writeln('There are eligible recordings left for a later run.');
    googlemeet_backfill_print_resume_command($options);
}

/**
 * Resolve activity, course module and context for a recording row.
 *
 * @param stdClass $recording Recording row.
 * @return array
 */
function googlemeet_backfill_resolve_context(stdClass $recording): array {
    global $DB;

    $googlemeet = $DB->get_record('googlemeet', ['id' => $recording->googlemeetid], '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('googlemeet', $googlemeet->id, $googlemeet->course, false, MUST_EXIST);
    $context = context_module::instance($cm->id);

    return [$googlemeet, $cm, $context];
}

/**
 * Generate questions with bounded backoff for transient Gemini failures.
 *
 * @param ai_service $service AI service.
 * @param int $recordingid Recording id.
 * @param int $count Requested question count.
 * @return int Created question count.
 */
function googlemeet_backfill_generate_with_retries(ai_service $service, int $recordingid, int $count): int {
    $attempt = 0;
    while (true) {
        try {
            return $service->generate_questions_for_recording($recordingid, $count);
        } catch (gemini_transient_exception $e) {
            if (!array_key_exists($attempt, GOOGLEMEET_BACKFILL_BACKOFF)) {
                throw $e;
            }
            $delay = GOOGLEMEET_BACKFILL_BACKOFF[$attempt];
            $attempt++;
            cli_writeln("  Transient Gemini error: {$e->getMessage()}");
            cli_writeln("  Backing off {$delay}s before retry {$attempt}...");
            sleep($delay);
        }
    }
}

/**
 * Count draft and ready questions for one recording.
 *
 * @param question_service $questionservice Question service.
 * @param stdClass $googlemeet Activity.
 * @param stdClass $cm Course module.
 * @param context_module $context Module context.
 * @param int $recordingid Recording id.
 * @return array
 */
function googlemeet_backfill_question_counts(
    question_service $questionservice,
    stdClass $googlemeet,
    stdClass $cm,
    context_module $context,
    int $recordingid
): array {
    $questions = $questionservice->get_questions($googlemeet, $cm, $context, $recordingid, false);
    $counts = ['total' => count($questions), 'draft' => 0, 'ready' => 0];
    foreach ($questions as $question) {
        if (!empty($question['isdraft'])) {
            $counts['draft']++;
        }
        if (!empty($question['isready'])) {
            $counts['ready']++;
        }
    }
    return $counts;
}

/**
 * Print an exact command suitable for continuing the idempotent backfill.
 *
 * @param array $options CLI options.
 * @return void
 */
function googlemeet_backfill_print_resume_command(array $options): void {
    global $CFG;

    $parts = ['php', 'mod/googlemeet/cli/backfill_questions.php'];
    foreach (['googlemeetid', 'recordingid', 'limit', 'count', 'pause'] as $key) {
        if (!empty($options[$key])) {
            $parts[] = '--' . $key . '=' . (int)$options[$key];
        }
    }
    cli_writeln('Continue with: cd ' . escapeshellarg($CFG->dirroot) . ' && ' . implode(' ', $parts));
}
