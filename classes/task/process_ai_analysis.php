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

use mod_googlemeet\ai_service;

/**
 * Scheduled task to process pending AI analyses.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_ai_analysis extends \core\task\scheduled_task {

    /**
     * Return the task's name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('ai_process_task', 'googlemeet');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        // Acquire a cron lock so that two overlapping runs of this scheduled task
        // (or a run that outlives the 10-minute interval) cannot both drain the
        // same pending rows and enqueue duplicate adhoc tasks (C2 - race condition).
        // A timeout of 0 means: if another run already holds the lock, give up
        // immediately rather than waiting.
        $lockfactory = \core\lock\lock_config::get_lock_factory('mod_googlemeet_ai_analysis');
        $lock = $lockfactory->get_lock('process_ai_analysis', 0);

        if (!$lock) {
            mtrace('Google Meet AI: Another run is already in progress, skipping.');
            return;
        }

        try {
            $aiservice = new ai_service();

            // F-8: rows stuck in 'processing' (a previous run or its adhoc task died mid-flight) are
            // marked failed with a clear message once they exceed the stuck threshold (setting
            // googlemeet/aistuckminutes, default 60 min, which comfortably exceeds the worst-case tier-3
            // video pipeline). The transient-retry bookkeeping retries them automatically while the
            // retry budget lasts. This runs even when AI is disabled so nothing stays "processing" forever.
            $failed = $aiservice->fail_stale_processing();
            if ($failed > 0) {
                mtrace("Google Meet AI: Marked {$failed} stuck 'processing' analyses as failed.");
            }

            // Check if AI features are enabled.
            if (!get_config('googlemeet', 'enableai')) {
                return;
            }
            if (!$aiservice->is_available()) {
                mtrace('Google Meet AI: API not configured, skipping task.');
                return;
            }

            mtrace('Google Meet AI: Processing pending analyses...');

            $processed = $aiservice->process_pending(5);

            mtrace("Google Meet AI: Processed {$processed} analyses.");
        } finally {
            $lock->release();
        }
    }
}
