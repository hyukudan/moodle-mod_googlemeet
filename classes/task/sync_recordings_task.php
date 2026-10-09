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

use mod_googlemeet\local\sync_manager;

/**
 * Adhoc task: teacher-requested "Sync with Google Drive" (PERF-02).
 *
 * Custom data: googlemeetid, userid (teacher whose Google token is used), logid (sync_log row the
 * page polls). When the per-activity lock is busy the task throws so the task API retries it.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_recordings_task extends \core\task\adhoc_task {

    /**
     * Task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_sync_recordings', 'mod_googlemeet');
    }

    /**
     * Execute.
     *
     * @return void
     */
    public function execute() {
        $data = (array)$this->get_custom_data();
        sync_manager::run((int)($data['googlemeetid'] ?? 0), (int)($data['userid'] ?? 0), (int)($data['logid'] ?? 0));
    }
}
