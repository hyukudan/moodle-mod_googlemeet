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
 * @copyright   2024 Your Name
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
        // Check if AI features are enabled.
        if (!get_config('googlemeet', 'enableai')) {
            return;
        }

        $aiservice = new ai_service();

        if (!$aiservice->is_available()) {
            mtrace('Google Meet AI: API not configured, skipping task.');
            return;
        }

        mtrace('Google Meet AI: Processing pending analyses...');

        $processed = $aiservice->process_pending(5);

        mtrace("Google Meet AI: Processed {$processed} analyses.");
    }
}
