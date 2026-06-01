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

namespace mod_googlemeet;

use stdClass;
use moodle_exception;

/**
 * AI Service for mod_googlemeet.
 *
 * Handles the business logic for AI-powered video analysis,
 * including creating, updating, and retrieving analysis records.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_service {

    /** @var int Max transient retries before giving up (mirrors cli/process_transcripts.php). */
    const MAX_TRANSIENT_RETRIES = 4;

    /** @var int retrycount sentinel meaning "permanent failure, never retry". */
    const PERMANENT_RETRYCOUNT = 99;

    /** @var int[] Back-off schedule in seconds, indexed by (retrycount - 1): 2h, 4h, 8h, 24h. */
    const TRANSIENT_BACKOFF = [7200, 14400, 28800, 86400];

    /** @var gemini_client The Gemini API client */
    private $client;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->client = new gemini_client();
    }

    /**
     * Check if AI features are available.
     *
     * @return bool
     */
    public function is_available(): bool {
        return $this->client->is_configured();
    }

    /**
     * Get the analysis for a recording.
     *
     * @param int $recordingid The recording ID
     * @return stdClass|null The analysis record or null if not found
     */
    public function get_analysis(int $recordingid): ?stdClass {
        global $DB;

        $analysis = $DB->get_record('googlemeet_ai_analysis', ['recordingid' => $recordingid]);

        if ($analysis) {
            // Decode JSON fields.
            $analysis->keypoints = json_decode($analysis->keypoints) ?: [];
            $analysis->topics = json_decode($analysis->topics) ?: [];
        }

        return $analysis ?: null;
    }

    /**
     * Start AI analysis for a recording (queues background task).
     *
     * @param int $recordingid The recording ID
     * @param bool $regenerate Whether to regenerate if analysis exists
     * @param bool $forcedownload Whether to allow falling back to full-video download (tier 3)
     * @param bool $alreadyclaimed True when the caller (the cron) has already atomically
     *                             flipped this row pending->processing via claim_pending();
     *                             in that case we must not re-evaluate/skip on status and
     *                             we just (re)enqueue the adhoc task for the claimed row.
     * @return stdClass The analysis record with status 'processing'
     * @throws moodle_exception If queueing fails
     */
    public function generate_analysis(int $recordingid, bool $regenerate = false, bool $forcedownload = false,
            bool $alreadyclaimed = false): stdClass {
        global $DB;

        debugging("AI Service: Starting analysis for recording {$recordingid}, regenerate=" . ($regenerate ? 'true' : 'false')
            . ", forcedownload=" . ($forcedownload ? 'true' : 'false')
            . ", alreadyclaimed=" . ($alreadyclaimed ? 'true' : 'false'), DEBUG_DEVELOPER);

        // Get the recording.
        $recording = $DB->get_record('googlemeet_recordings', ['id' => $recordingid], '*', MUST_EXIST);
        debugging("AI Service: Found recording '{$recording->name}'", DEBUG_DEVELOPER);

        // Check if analysis already exists.
        $existing = $DB->get_record('googlemeet_ai_analysis', ['recordingid' => $recordingid]);

        // If exists and completed and not regenerating, return it.
        if ($existing && !$regenerate && $existing->status === 'completed') {
            $existing->keypoints = json_decode($existing->keypoints) ?: [];
            $existing->topics = json_decode($existing->topics) ?: [];
            return $existing;
        }

        // If already processing, return current status without re-enqueuing.
        //
        // This guard now also covers the regenerate path: if another cron run (or a
        // user-triggered call) already owns this row as 'processing', we must NOT
        // re-update it and re-queue a second adhoc task, even when regenerate=true
        // (C2 - race condition). The only legitimate way to act on a 'processing'
        // row is to have just claimed it ourselves, signalled by $alreadyclaimed.
        if ($existing && $existing->status === 'processing' && !$alreadyclaimed) {
            $existing->keypoints = [];
            $existing->topics = [];
            return $existing;
        }

        // Create or update the analysis record with processing status.
        $analysis = new stdClass();
        $analysis->recordingid = $recordingid;
        $analysis->status = 'processing';
        $analysis->error = null;
        $analysis->timemodified = time();
        // A fresh (re)generation request (not an automatic claimed retry) resets the retry
        // budget so the row gets the full transient-retry allowance again. The claimed-retry
        // path below must NOT reset it, so it can keep counting across automatic retries.
        if (!$alreadyclaimed) {
            $analysis->retrycount = 0;
            $analysis->nextretry = 0;
        }

        if ($existing) {
            $analysis->id = $existing->id;
            // When already claimed, the row is already 'processing'; keep it that way
            // rather than rewriting it, but still proceed to (re)enqueue below.
            if (!$alreadyclaimed) {
                $DB->update_record('googlemeet_ai_analysis', $analysis);
            } else {
                $analysis->timemodified = $existing->timemodified;
            }
        } else {
            $analysis->timecreated = time();
            $analysis->id = $DB->insert_record('googlemeet_ai_analysis', $analysis);
        }

        debugging("AI Service: Queueing background task for analysis {$analysis->id}", DEBUG_DEVELOPER);

        // Queue the adhoc task to process in background.
        $task = new \mod_googlemeet\task\process_video_analysis();
        $task->set_custom_data([
            'recordingid' => $recordingid,
            'analysisid' => $analysis->id,
            'forcedownload' => $forcedownload,
        ]);
        \core\task\manager::queue_adhoc_task($task, true); // true = check for duplicates.

        debugging("AI Service: Background task queued successfully", DEBUG_DEVELOPER);

        // Return the analysis with processing status.
        $analysis->keypoints = [];
        $analysis->topics = [];
        $analysis->summary = '';
        $analysis->transcript = '';

        return $analysis;
    }

    /**
     * Generate AI analysis synchronously (for simple text-based analysis).
     * This is a fallback method that doesn't use the File API.
     *
     * @param int $recordingid The recording ID
     * @param bool $regenerate Whether to regenerate if analysis exists
     * @return stdClass The analysis record
     * @throws moodle_exception If generation fails
     */
    public function generate_analysis_sync(int $recordingid, bool $regenerate = false): stdClass {
        global $DB;

        // Get the recording.
        $recording = $DB->get_record('googlemeet_recordings', ['id' => $recordingid], '*', MUST_EXIST);

        // Check if analysis already exists.
        $existing = $DB->get_record('googlemeet_ai_analysis', ['recordingid' => $recordingid]);

        if ($existing && !$regenerate) {
            $existing->keypoints = json_decode($existing->keypoints) ?: [];
            $existing->topics = json_decode($existing->topics) ?: [];
            return $existing;
        }

        // Create or update the analysis record.
        $analysis = new stdClass();
        $analysis->recordingid = $recordingid;
        $analysis->status = 'processing';
        $analysis->timemodified = time();

        if ($existing) {
            $analysis->id = $existing->id;
            $DB->update_record('googlemeet_ai_analysis', $analysis);
        } else {
            $analysis->timecreated = time();
            $analysis->id = $DB->insert_record('googlemeet_ai_analysis', $analysis);
        }

        try {
            // Call the Gemini API (text-only, won't actually analyze video content).
            $result = $this->client->analyze_video(
                $recording->webviewlink,
                $recording->name,
                $recording->duration
            );

            // Update the analysis with results.
            $analysis->summary = $result->summary;
            $analysis->keypoints = json_encode($result->keypoints);
            $analysis->topics = json_encode($result->topics);
            $analysis->transcript = $result->transcript;
            $analysis->language = $result->language;
            $analysis->status = 'completed';
            $analysis->error = null;
            $analysis->aimodel = $this->client->get_last_used_model() ?? $this->client->get_model();
            $analysis->timemodified = time();

            $DB->update_record('googlemeet_ai_analysis', $analysis);

            // Return with decoded arrays.
            $analysis->keypoints = $result->keypoints;
            $analysis->topics = $result->topics;

            return $analysis;

        } catch (\Exception $e) {
            $analysis->status = 'failed';
            $analysis->error = $e->getMessage();
            $analysis->timemodified = time();
            $DB->update_record('googlemeet_ai_analysis', $analysis);

            throw $e;
        }
    }

    /**
     * Delete the analysis for a recording.
     *
     * @param int $recordingid The recording ID
     * @return bool True if deleted
     */
    public function delete_analysis(int $recordingid): bool {
        global $DB;

        return $DB->delete_records('googlemeet_ai_analysis', ['recordingid' => $recordingid]);
    }

    /**
     * Get all pending analyses that need processing.
     *
     * @param int $limit Maximum number to return
     * @return array Array of analysis records
     */
    public function get_pending_analyses(int $limit = 10): array {
        global $DB;

        return $DB->get_records('googlemeet_ai_analysis', ['status' => 'pending'], 'timecreated ASC', '*', 0, $limit);
    }

    /**
     * Atomically claim a pending analysis for processing.
     *
     * Flips a single row from 'pending' to 'processing' with a conditional UPDATE
     * (WHERE id = :id AND status = 'pending'). This is the concurrency guard that
     * ensures only one cron run can own a given row: if another run already claimed
     * it (status is no longer 'pending'), the UPDATE matches zero rows and this
     * method returns false so the caller skips it (C2 - race condition).
     *
     * @param int $analysisid The analysis record ID to claim
     * @return bool True if THIS call won the claim, false if it was already taken
     */
    public function claim_pending(int $analysisid): bool {
        global $DB;

        $now = time();

        // Conditional update: succeeds while the row is still 'pending', OR while it is a
        // transient-failure row whose back-off has elapsed (status 'failed', 0 < retrycount <
        // MAX, nextretry due). $DB->execute() does not report affected rows portably, so we
        // issue the guarded UPDATE and then re-read the row to confirm WE are the owner.
        $sql = "UPDATE {googlemeet_ai_analysis}
                   SET status = 'processing', timemodified = :now
                 WHERE id = :id
                   AND (status = 'pending'
                        OR (status = 'failed'
                            AND retrycount > 0
                            AND retrycount < :maxretries
                            AND nextretry > 0
                            AND nextretry <= :now2))";
        $DB->execute($sql, [
            'now' => $now,
            'id' => $analysisid,
            'maxretries' => self::MAX_TRANSIENT_RETRIES,
            'now2' => $now,
        ]);

        // Re-read and verify. If the row now reads 'processing' with the timestamp we just
        // wrote, the claim was ours. NOTE: this verify-by-timestamp is correct only because the
        // sole caller (process_pending) runs under the 'mod_googlemeet_ai_analysis' cron lock,
        // so two claims can never race within the same second. Do not call claim_pending() from
        // an unlocked context without strengthening this ownership token.
        $row = $DB->get_record('googlemeet_ai_analysis', ['id' => $analysisid], 'id, status, timemodified');
        if (!$row) {
            return false;
        }

        return $row->status === 'processing' && (int) $row->timemodified === $now;
    }

    /**
     * Record a transient (retryable) failure with bounded back-off.
     *
     * Used by the background AI pipeline so that rate limits / overloads are retried instead of
     * becoming permanent failures. Mirrors the policy in cli/process_transcripts.php: after
     * {@see self::MAX_TRANSIENT_RETRIES} attempts the row is left 'failed' with no further
     * nextretry (so the due-selection no longer picks it up).
     *
     * @param int $analysisid The analysis row id.
     * @param int $previousretrycount The retrycount BEFORE this attempt.
     * @param string $message The error message to store.
     * @return void
     */
    public function record_transient_failure(int $analysisid, int $previousretrycount, string $message): void {
        global $DB;

        $now = time();
        $newrc = $previousretrycount + 1;

        if ($newrc >= self::MAX_TRANSIENT_RETRIES) {
            // Retries exhausted. Cap retrycount and clear nextretry so it is not retried again.
            $newrc = self::MAX_TRANSIENT_RETRIES;
            $nextretry = 0;
        } else {
            $backoff = self::TRANSIENT_BACKOFF[$newrc - 1] ?? 86400;
            $nextretry = $now + $backoff;
        }

        $DB->update_record('googlemeet_ai_analysis', (object) [
            'id' => $analysisid,
            'status' => 'failed',
            'error' => $message,
            'retrycount' => $newrc,
            'nextretry' => $nextretry,
            'timemodified' => $now,
        ]);
    }

    /**
     * Record a permanent (non-retryable) failure.
     *
     * @param int $analysisid The analysis row id.
     * @param string $message The error message to store.
     * @return void
     */
    public function record_permanent_failure(int $analysisid, string $message): void {
        global $DB;

        $DB->update_record('googlemeet_ai_analysis', (object) [
            'id' => $analysisid,
            'status' => 'failed',
            'error' => $message,
            'retrycount' => self::PERMANENT_RETRYCOUNT,
            'nextretry' => 0,
            'timemodified' => time(),
        ]);
    }

    /**
     * Get analyses that are due for processing: fresh 'pending' rows plus transient-failure
     * rows whose back-off has elapsed.
     *
     * @param int $limit Maximum number to return.
     * @return array Array of analysis records.
     */
    public function get_due_analyses(int $limit = 10): array {
        global $DB;

        $now = time();
        $sql = "SELECT *
                  FROM {googlemeet_ai_analysis}
                 WHERE status = :pending
                    OR (status = :failed
                        AND retrycount > 0
                        AND retrycount < :maxretries
                        AND nextretry > 0
                        AND nextretry <= :now)
              ORDER BY timecreated ASC";
        $params = [
            'pending' => 'pending',
            'failed' => 'failed',
            'maxretries' => self::MAX_TRANSIENT_RETRIES,
            'now' => $now,
        ];

        return $DB->get_records_sql($sql, $params, 0, $limit);
    }

    /**
     * Reset analyses that have been stuck in 'processing' for too long.
     *
     * If a cron run or its adhoc task dies mid-flight, a row can be left in
     * 'processing' forever and would never be retried (the cron only picks up
     * 'pending' rows). This reverts any 'processing' row older than $maxage back
     * to 'pending' so it is reconsidered on the next run.
     *
     * @param int $maxage Maximum allowed age in 'processing' state, in seconds
     * @return int Number of rows reset
     */
    public function reset_stale_processing(int $maxage = 3600): int {
        global $DB;

        $threshold = time() - $maxage;

        $stale = $DB->get_records_select(
            'googlemeet_ai_analysis',
            "status = 'processing' AND timemodified < :threshold",
            ['threshold' => $threshold],
            '',
            'id'
        );

        if (empty($stale)) {
            return 0;
        }

        $now = time();
        list($insql, $inparams) = $DB->get_in_or_equal(array_keys($stale), SQL_PARAMS_NAMED);
        $params = $inparams + ['now' => $now];

        $sql = "UPDATE {googlemeet_ai_analysis}
                   SET status = 'pending', timemodified = :now
                 WHERE id $insql AND status = 'processing'";
        $DB->execute($sql, $params);

        return count($stale);
    }

    /**
     * Queue a recording for analysis.
     *
     * @param int $recordingid The recording ID
     * @return stdClass The created analysis record
     */
    public function queue_for_analysis(int $recordingid): stdClass {
        global $DB;

        // Check if already exists.
        $existing = $DB->get_record('googlemeet_ai_analysis', ['recordingid' => $recordingid]);
        if ($existing) {
            return $existing;
        }

        $analysis = new stdClass();
        $analysis->recordingid = $recordingid;
        $analysis->status = 'pending';
        $analysis->timecreated = time();
        $analysis->timemodified = time();

        $analysis->id = $DB->insert_record('googlemeet_ai_analysis', $analysis);

        return $analysis;
    }

    /**
     * Get analysis status counts for a googlemeet instance.
     *
     * @param int $googlemeetid The googlemeet ID
     * @return stdClass Object with counts for each status
     */
    public function get_status_counts(int $googlemeetid): stdClass {
        global $DB;

        $sql = "SELECT aa.status, COUNT(*) as count
                FROM {googlemeet_ai_analysis} aa
                JOIN {googlemeet_recordings} r ON r.id = aa.recordingid
                WHERE r.googlemeetid = :googlemeetid
                GROUP BY aa.status";

        $records = $DB->get_records_sql($sql, ['googlemeetid' => $googlemeetid]);

        $counts = new stdClass();
        $counts->pending = 0;
        $counts->processing = 0;
        $counts->completed = 0;
        $counts->failed = 0;

        foreach ($records as $record) {
            $status = $record->status;
            $counts->$status = (int) $record->count;
        }

        return $counts;
    }

    /**
     * Process a batch of pending analyses.
     *
     * @param int $limit Maximum number to process
     * @return int Number of analyses processed
     */
    public function process_pending(int $limit = 5): int {
        global $DB;

        $pending = $this->get_due_analyses($limit);
        $processed = 0;

        foreach ($pending as $analysis) {
            // Atomically claim this row (pending -> processing). If another concurrent
            // run already took it, claim_pending() returns false and we skip it so we
            // never process / enqueue the same recording twice (C2 - race condition).
            if (!$this->claim_pending($analysis->id)) {
                continue;
            }

            try {
                // Background runner must never escalate to tier 3 (video download) automatically.
                // $alreadyclaimed=true: the row is already ours in 'processing', so just enqueue.
                $this->generate_analysis($analysis->recordingid, true, false, true);
                $processed++;
            } catch (\Exception $e) {
                // Make sure a row we claimed never gets stuck in 'processing'. Enqueuing failed,
                // which is not a transient API condition, so record it as a permanent failure
                // (retrycount=99, nextretry=0). Using record_permanent_failure() — rather than only
                // flipping status — is important: a row that was a due transient retry would
                // otherwise keep retrycount>0 and a due nextretry, and get reselected every tick.
                $this->record_permanent_failure($analysis->id, $e->getMessage());
                continue;
            }
        }

        return $processed;
    }
}
