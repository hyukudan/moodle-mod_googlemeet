<?php
// This file is part of Moodle - http://moodle.org/
//
// CLI script to automatically extract subtitles from Google Drive recordings
// and process them with Gemini AI for analysis.
//
// Usage: php8.5 process_transcripts.php --googlemeetid=1 [--recordingid=4] [--dry-run]

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

// Back-off schedule in seconds for retry attempts on transient Gemini errors.
// Index = retrycount BEFORE incrementing. After 4 retries (final value 4), give up.
const TRANSIENT_BACKOFF = [
    0 => 2 * 3600,    // primer fallo → reintenta en 2h
    1 => 4 * 3600,    // 2º fallo → 4h
    2 => 8 * 3600,    // 3º fallo → 8h
    3 => 24 * 3600,   // 4º fallo → 24h
];
const MAX_RETRIES = 4;            // tras 4 reintentos transitorios, se rinde
const PERMANENT_RETRYCOUNT = 99;  // sentinela: error permanente, no reintentar nunca

list($options, $unrecognized) = cli_get_params([
    'googlemeetid' => 0,
    'recordingid'  => 0,
    'dry-run'      => false,
    'help'         => false,
    'skip-gemini'  => false,
], [
    'g' => 'googlemeetid',
    'r' => 'recordingid',
    'd' => 'dry-run',
    'h' => 'help',
    's' => 'skip-gemini',
]);

if ($options['help']) {
    cli_writeln("
Extract subtitles from Google Drive recordings and process with Gemini AI.

Options:
  -g, --googlemeetid=ID   Google Meet activity ID (required)
  -r, --recordingid=ID    Process only this recording (optional)
  -d, --dry-run           Show what would be done without making changes
  -s, --skip-gemini       Only extract subtitles, skip AI analysis
  -h, --help              Show this help

Example:
  php8.5 process_transcripts.php --googlemeetid=1
  php8.5 process_transcripts.php --googlemeetid=1 --recordingid=4 --dry-run
");
    exit(0);
}

if (empty($options['googlemeetid'])) {
    cli_error('--googlemeetid is required');
}

$dryrun = $options['dry-run'];
$skipgemini = $options['skip-gemini'];
$ytdlp = '/tmp/yt-dlp';

// Check yt-dlp is available.
if (!file_exists($ytdlp) || !is_executable($ytdlp)) {
    cli_error("yt-dlp not found at {$ytdlp}. Install it with: curl -sL https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -o /tmp/yt-dlp && chmod +x /tmp/yt-dlp");
}

// Get recordings to process.
$params = ['googlemeetid' => $options['googlemeetid']];
$where = 'r.googlemeetid = :googlemeetid';

if (!empty($options['recordingid'])) {
    $where .= ' AND r.id = :recordingid';
    $params['recordingid'] = $options['recordingid'];
}

$sql = "SELECT r.id, r.recordingid, r.name, r.webviewlink, r.duration, r.transcripttext,
               a.id as analysisid, a.status as ai_status, a.retrycount, a.nextretry
        FROM {googlemeet_recordings} r
        LEFT JOIN {googlemeet_ai_analysis} a ON a.recordingid = r.id
        WHERE {$where}
        ORDER BY r.createdtime ASC";

$recordings = $DB->get_records_sql($sql, $params);

if (empty($recordings)) {
    cli_writeln("No recordings found for googlemeetid={$options['googlemeetid']}");
    exit(0);
}

$total = count($recordings);
$processed = 0;
$skipped = 0;
$errors = 0;

cli_writeln("Found {$total} recordings. Starting processing...\n");

foreach ($recordings as $recording) {
    $num = $processed + $skipped + $errors + 1;
    cli_writeln("=== [{$num}/{$total}] Recording #{$recording->id}: {$recording->name} ===");

    // Skip if already has completed AI analysis.
    if ($recording->ai_status === 'completed') {
        cli_writeln("  SKIP: Already has completed AI analysis.");
        $skipped++;
        continue;
    }

    // Skip if permanent failure or still within cooldown window.
    $rc = (int)($recording->retrycount ?? 0);
    $nr = (int)($recording->nextretry ?? 0);
    $now = time();

    if ($rc >= MAX_RETRIES || $rc === PERMANENT_RETRYCOUNT) {
        cli_writeln("  SKIP: Marked as permanent failure (retrycount={$rc}). Manual intervention required.");
        $skipped++;
        continue;
    }
    if ($nr > $now) {
        $minutes = ceil(($nr - $now) / 60);
        $when = userdate($nr, '%Y-%m-%d %H:%M');
        cli_writeln("  SKIP: In cooldown until {$when} ({$minutes} min remaining, retrycount={$rc}).");
        $skipped++;
        continue;
    }

    // Step 1: Extract subtitle URL via yt-dlp.
    $driveurl = $recording->webviewlink;
    if (empty($driveurl)) {
        cli_writeln("  ERROR: No webviewlink for this recording.");
        $errors++;
        continue;
    }

    cli_writeln("  Step 1: Extracting subtitle URL from Google Drive...");

    $timedtexturl = extract_timedtext_url($ytdlp, $driveurl);
    if (!$timedtexturl) {
        cli_writeln("  ERROR: Could not extract timedtext URL. Video may not have subtitles.");
        $errors++;
        continue;
    }

    cli_writeln("  Found timedtext URL.");

    // Step 2: Download subtitles.
    cli_writeln("  Step 2: Downloading subtitles...");

    // Get base URL (strip track-specific params).
    $baseurl = preg_replace('/&type=track.*$/', '', $timedtexturl);

    // Download in fmt=1 format (simple XML with timestamps).
    $suburl = $baseurl . '&type=track&lang=es&kind=asr&fmt=1';
    $subxml = download_subtitles($suburl);

    if (empty($subxml)) {
        // Try the named track "1" as fallback.
        $suburl = $baseurl . '&type=track&lang=es&name=1&fmt=1';
        $subxml = download_subtitles($suburl);
    }

    if (empty($subxml)) {
        cli_writeln("  ERROR: Could not download subtitle content.");
        $errors++;
        continue;
    }

    // Step 3: Parse XML into readable transcript.
    $transcript = parse_subtitle_xml($subxml);
    $charcount = strlen($transcript);
    cli_writeln("  Downloaded {$charcount} characters of transcript.");

    if ($charcount < 100) {
        cli_writeln("  ERROR: Transcript too short ({$charcount} chars), skipping.");
        $errors++;
        continue;
    }

    if ($dryrun) {
        cli_writeln("  DRY-RUN: Would save transcript and run AI analysis.");
        cli_writeln("  Preview: " . substr($transcript, 0, 200) . "...");
        $processed++;
        continue;
    }

    // Step 4: Save transcript to googlemeet_ai_analysis table.
    cli_writeln("  Step 3: Saving transcript to database...");

    $now = time();
    if ($recording->analysisid) {
        // Update existing analysis record.
        $DB->update_record('googlemeet_ai_analysis', (object)[
            'id' => $recording->analysisid,
            'transcript' => $transcript,
            'status' => 'pending',
            'timemodified' => $now,
        ]);
        $analysisid = $recording->analysisid;
    } else {
        // Create new analysis record.
        $analysisid = $DB->insert_record('googlemeet_ai_analysis', (object)[
            'recordingid' => $recording->id,
            'transcript' => $transcript,
            'status' => 'pending',
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    // Also save transcript to the recordings table for the fast-path.
    $DB->update_record('googlemeet_recordings', (object)[
        'id' => $recording->id,
        'transcripttext' => $transcript,
    ]);

    cli_writeln("  Transcript saved (analysis #{$analysisid}).");

    // Step 5: Run Gemini analysis.
    if ($skipgemini) {
        cli_writeln("  SKIP-GEMINI: Transcript saved, AI analysis skipped.");
        $processed++;
        continue;
    }

    cli_writeln("  Step 4: Running Gemini AI analysis...");

    try {
        $client = new \mod_googlemeet\gemini_client();
        if (!$client->is_configured()) {
            cli_writeln("  WARNING: Gemini AI not configured. Transcript saved but no analysis.");
            $processed++;
            continue;
        }

        $result = $client->analyze_transcript($transcript, $recording->name, $recording->duration);

        // Save the analysis results.
        $DB->update_record('googlemeet_ai_analysis', (object)[
            'id' => $analysisid,
            'summary' => $result->summary,
            'keypoints' => json_encode($result->keypoints),
            'topics' => json_encode($result->topics),
            'language' => $result->language ?? 'es',
            'status' => 'completed',
            'aimodel' => $client->get_model(),
            'error' => null,
            'retrycount' => 0,
            'nextretry' => 0,
            'timemodified' => time(),
        ]);

        $summarylen = strlen($result->summary);
        $keypointscount = count($result->keypoints);
        $topicscount = count($result->topics);
        cli_writeln("  AI analysis complete: summary={$summarylen} chars, {$keypointscount} key points, {$topicscount} topics.");

    } catch (\mod_googlemeet\gemini_transient_exception $e) {
        // Transient error (rate limit / high demand) → schedule retry with back-off.
        $new_rc = $rc + 1;
        if ($new_rc >= MAX_RETRIES) {
            // Hemos agotado los reintentos, marcamos como permanente.
            $next_unix = 0;
            $new_rc = MAX_RETRIES; // fija en 4, no escala más
            $log_suffix = "Max retries reached, giving up.";
        } else {
            $backoff = TRANSIENT_BACKOFF[$new_rc - 1] ?? 24 * 3600;
            $next_unix = $now + $backoff;
            $log_suffix = sprintf("Will retry at %s (in %d min).", userdate($next_unix, '%Y-%m-%d %H:%M'), (int)($backoff / 60));
        }
        $DB->update_record('googlemeet_ai_analysis', (object)[
            'id' => $analysisid,
            'status' => 'failed',
            'error' => $e->getMessage(),
            'retrycount' => $new_rc,
            'nextretry' => $next_unix,
            'timemodified' => $now,
        ]);
        cli_writeln("  TRANSIENT ERROR (retrycount={$new_rc}): " . $e->getMessage());
        cli_writeln("  " . $log_suffix);
        // No se cuenta como error fatal; el transcript ya está guardado.
    } catch (\moodle_exception $e) {
        // Permanent error → mark and don't retry.
        $DB->update_record('googlemeet_ai_analysis', (object)[
            'id' => $analysisid,
            'status' => 'failed',
            'error' => $e->getMessage(),
            'retrycount' => PERMANENT_RETRYCOUNT,
            'nextretry' => 0,
            'timemodified' => $now,
        ]);
        cli_writeln("  PERMANENT ERROR (no retry): " . $e->getMessage());
        // Don't count as error - transcript was saved successfully.
    }

    $processed++;
    cli_writeln("  DONE.\n");

    // Brief pause between Gemini API calls to avoid rate limiting.
    if (!$skipgemini && $num < $total) {
        sleep(2);
    }
}

cli_writeln("\n========================================");
cli_writeln("Results: {$processed} processed, {$skipped} skipped, {$errors} errors (of {$total} total)");
cli_writeln("========================================");


/**
 * Extract the timedtext URL for a Google Drive video using yt-dlp.
 *
 * @param string $ytdlp Path to yt-dlp binary
 * @param string $driveurl Google Drive video URL
 * @return string|null The timedtext URL or null on failure
 */
function extract_timedtext_url(string $ytdlp, string $driveurl): ?string {
    $cmd = escapeshellarg($ytdlp) . ' -v --write-sub --sub-lang es --skip-download --sub-format srv3'
         . ' -o /dev/null ' . escapeshellarg($driveurl) . ' 2>&1';

    $output = shell_exec($cmd);

    // Extract the timedtext URL from verbose output.
    if (preg_match('/Invoking http downloader on "(https:\/\/drive\.google\.com\/timedtext\?[^"]+)"/', $output, $matches)) {
        return $matches[1];
    }

    return null;
}

/**
 * Download subtitle content from a timedtext URL.
 *
 * @param string $url The timedtext URL with track parameters
 * @return string The subtitle XML content
 */
function download_subtitles(string $url): string {
    global $CFG;
    require_once($CFG->libdir . '/filelib.php');

    $curl = new \curl();
    $curl->setHeader(['Referer: https://youtube.googleapis.com/']);

    $options = [
        'CURLOPT_TIMEOUT' => 30,
        'CURLOPT_CONNECTTIMEOUT' => 10,
        'CURLOPT_FOLLOWLOCATION' => true,
    ];

    return $curl->get($url, [], $options) ?: '';
}

/**
 * Parse subtitle XML (fmt=1 format) into readable transcript with timestamps.
 *
 * @param string $xml The subtitle XML content
 * @return string The parsed transcript with timestamps
 */
function parse_subtitle_xml(string $xml): string {
    // Suppress XML warnings.
    libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml);
    libxml_clear_errors();

    if (!$doc) {
        return '';
    }

    $lines = [];
    $lastminute = -1;

    foreach ($doc->text as $node) {
        $start = (float)$node['start'];
        $text = trim((string)$node);

        if (empty($text)) {
            continue;
        }

        // Convert seconds to MM:SS or H:MM:SS.
        $hours = floor($start / 3600);
        $minutes = floor(($start % 3600) / 60);
        $seconds = floor($start % 60);

        $currentminute = floor($start / 60);

        // Add timestamp marker at each new minute.
        if ($currentminute > $lastminute) {
            if ($hours > 0) {
                $timestamp = sprintf("%d:%02d:%02d", $hours, $minutes, $seconds);
            } else {
                $timestamp = sprintf("%d:%02d", $minutes, $seconds);
            }
            $lines[] = $timestamp;
            $lastminute = $currentminute;
        }

        // Clean up HTML entities.
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines[] = $text;
    }

    return implode("\n", $lines);
}
