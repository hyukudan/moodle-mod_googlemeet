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

use mod_googlemeet\local\calendar_sync;

/**
 * Adhoc task: delete the Google Calendar event of a deleted activity (DAT-04).
 *
 * Custom data (captured before the record was deleted): googlemeetid, eventid, creatoremail,
 * name, course, editorid. Never throws: failures are logged and the editor is notified.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class calendar_delete_event extends \core\task\adhoc_task {

    /**
     * Task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_calendar_delete_event', 'mod_googlemeet');
    }

    /**
     * Execute.
     *
     * @return void
     */
    public function execute() {
        calendar_sync::apply_delete((array)$this->get_custom_data());
    }
}
