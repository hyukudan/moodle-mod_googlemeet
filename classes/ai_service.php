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
 * @copyright   2024 Your Name
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_service {

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
     * @return stdClass The analysis record with status 'processing'
     * @throws moodle_exception If queueing fails
     */
    public function generate_analysis(int $recordingid, bool $regenerate = false): stdClass {
        global $DB;

        debugging("AI Service: Starting analysis for recording {$recordingid}, regenerate=" . ($regenerate ? 'true' : 'false'), DEBUG_DEVELOPER);

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

        // If already processing, return current status.
        if ($existing && $existing->status === 'processing' && !$regenerate) {
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

        if ($existing) {
            $analysis->id = $existing->id;
            $DB->update_record('googlemeet_ai_analysis', $analysis);
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
            $analysis->aimodel = $this->client->get_model();
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
        $pending = $this->get_pending_analyses($limit);
        $processed = 0;

        foreach ($pending as $analysis) {
            try {
                $this->generate_analysis($analysis->recordingid, true);
                $processed++;
            } catch (\Exception $e) {
                // Error is already logged in generate_analysis.
                continue;
            }
        }

        return $processed;
    }
}
