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
use mod_googlemeet\local\room_entry;

/**
 * Log that the user is entering the live room (ANA-02) and return the Meet URL to open.
 *
 * Used by the mobile app (and any client that cannot go through enter.php) right before
 * opening Google Meet.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class log_room_entered extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'coursemoduleid' => new external_value(PARAM_INT, 'Course module ID'),
            'via' => new external_value(PARAM_ALPHA, 'web or mobile', VALUE_DEFAULT, 'mobile'),
        ]);
    }

    /**
     * Trigger room_entered.
     *
     * @param int $coursemoduleid Course module id.
     * @param string $via Client.
     * @return array
     */
    public static function execute(int $coursemoduleid, string $via = 'mobile'): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'coursemoduleid' => $coursemoduleid,
            'via' => $via,
        ]);

        $cm = get_coursemodule_from_id('googlemeet', $params['coursemoduleid'], 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/googlemeet:view', $context);
        $googlemeet = $DB->get_record('googlemeet', ['id' => $cm->instance], '*', MUST_EXIST);

        $url = room_entry::room_url($googlemeet);
        if ($url === null) {
            return ['logged' => false, 'sessionid' => 0, 'url' => ''];
        }
        $sessionid = room_entry::log($googlemeet, $cm, $context, $params['via']);
        return ['logged' => true, 'sessionid' => $sessionid, 'url' => $url];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'logged' => new external_value(PARAM_BOOL, 'Whether the entry was logged'),
            'sessionid' => new external_value(PARAM_INT, 'Session (googlemeet_events) id, 0 if none is live'),
            'url' => new external_value(PARAM_URL, 'Google Meet room URL, empty when the room URL is not valid'),
        ]);
    }
}
