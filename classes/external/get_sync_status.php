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
namespace mod_googlemeet\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_googlemeet\local\sync_manager;

/**
 * Status of the background "Sync with Google Drive" of an activity (PERF-02).
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_sync_status extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'coursemoduleid' => new external_value(PARAM_INT, 'Course module ID'),
        ]);
    }

    /**
     * Return the latest manual sync status.
     *
     * @param int $coursemoduleid
     * @return array
     */
    public static function execute(int $coursemoduleid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), ['coursemoduleid' => $coursemoduleid]);
        $cm = get_coursemodule_from_id('googlemeet', $params['coursemoduleid'], 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/googlemeet:editrecording', $context);
        $googlemeet = $DB->get_record('googlemeet', ['id' => $cm->instance], '*', MUST_EXIST);

        return sync_manager::get_status($googlemeet);
    }

    /**
     * Return description (shared with request_sync).
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'none, queued, running, success or error'),
            'active' => new external_value(PARAM_BOOL, 'Queued or running'),
            'message' => new external_value(PARAM_TEXT, 'Human readable status/result'),
            'timequeued' => new external_value(PARAM_INT, 'When the sync was requested'),
            'timefinished' => new external_value(PARAM_INT, 'When it finished (0 while active)'),
            'lastsync' => new external_value(PARAM_TEXT, 'Formatted last successful sync time'),
            'haschanges' => new external_value(PARAM_BOOL, 'Recordings were added/removed: reload to see them'),
        ]);
    }
}
