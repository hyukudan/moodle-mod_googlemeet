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

namespace mod_googlemeet\task;

defined('MOODLE_INTERNAL') || die();

use mod_googlemeet\ai_service;
use mod_googlemeet\client;

/**
 * Enrich newly synced recordings in the background.
 *
 * Manual web sync only inserts/restores Drive metadata. This task does the
 * heavier follow-up work: Drive permissions, transcript discovery/extraction,
 * Gemini notes export, then AI and recording notifications.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_recording_enrichment extends \core\task\adhoc_task {

    /**
     * Get the task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('process_recording_enrichment_task', 'googlemeet');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        global $DB;

        $data = $this->get_custom_data();
        $googlemeetid = (int)($data->googlemeetid ?? 0);
        $recordingids = array_values(array_unique(array_filter(array_map('intval', $data->recordingids ?? []))));
        // AI analysis and "new recording" notifications only apply to inserts;
        // restored recordings are enriched but stay silent (parity with the
        // inline/cron path). Tasks queued before this field existed fall back
        // to treating every id as new.
        $newrecordingids = property_exists($data, 'newrecordingids')
            ? array_values(array_unique(array_filter(array_map('intval', $data->newrecordingids ?? []))))
            : $recordingids;

        if (!$googlemeetid || empty($recordingids)) {
            return;
        }

        $recordingids = $this->filter_active_recordingids($googlemeetid, $recordingids);
        if (empty($recordingids)) {
            return;
        }

        $googlemeet = $DB->get_record('googlemeet', ['id' => $googlemeetid]);
        if (!$googlemeet) {
            mtrace("mod_googlemeet enrichment: activity #{$googlemeetid} no longer exists; skipping.");
            return;
        }

        if (empty($googlemeet->creatoremail)) {
            mtrace("mod_googlemeet enrichment: activity #{$googlemeetid} has no creator email; not retrying.");
            return;
        }

        $creator = $DB->get_record('user', ['email' => $googlemeet->creatoremail, 'deleted' => 0, 'suspended' => 0]);
        if (!$creator) {
            mtrace("mod_googlemeet enrichment: no active Moodle user for {$googlemeet->creatoremail}; not retrying.");
            return;
        }

        $lockfactory = \core\lock\lock_config::get_lock_factory('mod_googlemeet');
        $lock = $lockfactory->get_lock('sync_' . $googlemeetid, 5);
        if (!$lock) {
            throw new \moodle_exception('sync_already_running', 'googlemeet');
        }

        $processedids = [];
        $previoususer = $GLOBALS['USER'] ?? null;
        try {
            \core\session\manager::set_user($creator);

            $client = new client();
            if (!$client->enabled || !$client->check_login()) {
                $message = "mod_googlemeet enrichment: creator {$creator->username} is not logged in to Google.";
                mtrace($message);
                if ($this->get_attempts_available() > 1) {
                    throw new \moodle_exception('sessionexpired', 'googlemeet');
                }
                mtrace('mod_googlemeet enrichment: no task attempts remain; not retrying.');
                return;
            }

            $processedids = $client->enrich_recordings($googlemeet, $recordingids);
        } finally {
            if ($previoususer) {
                \core\session\manager::set_user($previoususer);
            }
            $lock->release();
        }

        $processednewids = array_values(array_intersect($processedids, $newrecordingids));
        $this->queue_ai_analysis($processednewids);
        $this->queue_recording_notification($googlemeetid, $processednewids);
    }

    /**
     * Keep only recordings that still exist and are not deleted.
     *
     * @param int $googlemeetid Activity instance id.
     * @param int[] $recordingids Local recording ids.
     * @return int[] Active recording ids.
     */
    private function filter_active_recordingids(int $googlemeetid, array $recordingids): array {
        global $DB;

        $active = [];
        foreach ($recordingids as $recordingid) {
            $recording = $DB->get_record('googlemeet_recordings', [
                'id' => $recordingid,
                'googlemeetid' => $googlemeetid,
            ], 'id, deleted');

            if (!$recording) {
                mtrace("mod_googlemeet enrichment: recording #{$recordingid} no longer exists; skipping.");
                continue;
            }

            if (!empty($recording->deleted)) {
                mtrace("mod_googlemeet enrichment: recording #{$recordingid} is deleted; skipping.");
                continue;
            }

            $active[] = $recordingid;
        }

        return $active;
    }

    /**
     * Queue AI analysis for recordings after enrichment has had a chance to save transcripts.
     *
     * @param int[] $recordingids Local recording ids.
     */
    private function queue_ai_analysis(array $recordingids): void {
        if (empty($recordingids) || empty(get_config('googlemeet', 'ai_autogenerate'))) {
            return;
        }

        $aiservice = new ai_service();
        if (!$aiservice->is_available()) {
            return;
        }

        foreach ($recordingids as $recordingid) {
            try {
                $aiservice->queue_for_analysis((int)$recordingid);
            } catch (\Throwable $e) {
                mtrace("mod_googlemeet enrichment: failed to queue AI for recording #{$recordingid}: " .
                    $e->getMessage());
            }
        }
    }

    /**
     * Queue subscriber notifications after the enrichment batch completes.
     *
     * @param int $googlemeetid Activity instance id.
     * @param int[] $recordingids Local recording ids processed by this task.
     */
    private function queue_recording_notification(int $googlemeetid, array $recordingids): void {
        global $DB;

        $recordingids = array_values(array_unique(array_filter(array_map('intval', $recordingids))));
        if (empty($recordingids)
                || !$DB->record_exists('googlemeet_recording_subs', ['googlemeetid' => $googlemeetid])) {
            return;
        }

        $task = new notify_new_recordings();
        $task->set_custom_data([
            'googlemeetid' => $googlemeetid,
            'newcount' => count($recordingids),
            'recordingids' => $recordingids,
        ]);
        \core\task\manager::queue_adhoc_task($task, true);
    }
}
