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

namespace mod_googlemeet\local\attendance;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy API parts of real attendance (ANA-03), called from \mod_googlemeet\privacy\provider.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class privacy {

    /**
     * Declare the stored data.
     *
     * @param collection $collection Collection.
     * @return void
     */
    public static function add_metadata(collection $collection): void {
        $collection->add_database_table('googlemeet_attendance', [
            'userid' => 'privacy:metadata:googlemeet_attendance:userid',
            'email' => 'privacy:metadata:googlemeet_attendance:email',
            'displayname' => 'privacy:metadata:googlemeet_attendance:displayname',
            'googleuserid' => 'privacy:metadata:googlemeet_attendance:googleuserid',
            'timejoined' => 'privacy:metadata:googlemeet_attendance:timejoined',
            'timeleft' => 'privacy:metadata:googlemeet_attendance:timeleft',
            'durationseconds' => 'privacy:metadata:googlemeet_attendance:durationseconds',
        ], 'privacy:metadata:googlemeet_attendance');
        $collection->add_external_location_link('googlemeet_meetapi', [
            'displayname' => 'privacy:metadata:googlemeet_meetapi:displayname',
        ], 'privacy:metadata:googlemeet_meetapi');
        $collection->add_user_preference(scope::PREF_GRANTED, 'privacy:metadata:preference:meetscope');
    }

    /**
     * Add the contexts where the user has attendance.
     *
     * @param contextlist $contextlist Context list.
     * @param int $userid User id.
     * @return void
     */
    public static function add_contexts(contextlist $contextlist, int $userid): void {
        $sql = "SELECT c.id
                  FROM {context} c
                  JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {googlemeet_attendance} ga ON ga.googlemeetid = cm.instance
                 WHERE ga.userid = :userid";
        $contextlist->add_from_sql($sql, ['contextlevel' => CONTEXT_MODULE, 'modname' => 'googlemeet', 'userid' => $userid]);
    }

    /**
     * Add the users with attendance in a module context.
     *
     * @param userlist $userlist User list.
     * @return void
     */
    public static function add_users(userlist $userlist): void {
        $sql = "SELECT ga.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {googlemeet_attendance} ga ON ga.googlemeetid = cm.instance
                 WHERE cm.id = :cmid AND ga.userid > 0";
        $userlist->add_from_sql('userid', $sql, ['modname' => 'googlemeet', 'cmid' => $userlist->get_context()->instanceid]);
    }

    /**
     * Export the user's attendance in the approved contexts.
     *
     * @param int $userid User id.
     * @param int[] $contextids Approved context ids.
     * @return void
     */
    public static function export(int $userid, array $contextids): void {
        global $DB;

        if (!$contextids) {
            return;
        }
        [$csql, $cparams] = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED);
        $sql = "SELECT ga.id, cm.id AS cmid, ge.eventdate, ga.displayname, ga.email, ga.timejoined, ga.timeleft,
                       ga.durationseconds, ga.sessions
                  FROM {context} c
                  JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {googlemeet_attendance} ga ON ga.googlemeetid = cm.instance
             LEFT JOIN {googlemeet_events} ge ON ge.id = ga.eventid
                 WHERE c.id {$csql} AND ga.userid = :userid
              ORDER BY cm.id, ga.timejoined";
        $grouped = [];
        foreach ($DB->get_records_sql($sql, ['contextlevel' => CONTEXT_MODULE, 'modname' => 'googlemeet',
                'userid' => $userid] + $cparams) as $row) {
            $grouped[(int)$row->cmid][] = [
                'session' => $row->eventdate ? transform::datetime($row->eventdate) : '',
                'meetname' => $row->displayname,
                'email' => $row->email,
                'timejoined' => transform::datetime($row->timejoined),
                'timeleft' => transform::datetime($row->timeleft),
                'durationseconds' => (int)$row->durationseconds,
                'sessions' => (int)$row->sessions,
            ];
        }
        foreach ($grouped as $cmid => $sessions) {
            writer::with_context(\context_module::instance($cmid))->export_data(
                [get_string('privacy:attendance', 'googlemeet')], (object)['sessions' => $sessions]);
        }
    }

    /**
     * Export the attendance preference.
     *
     * @param int $userid User id.
     * @return void
     */
    public static function export_preferences(int $userid): void {
        $value = get_user_preferences(scope::PREF_GRANTED, null, $userid);
        if ($value !== null) {
            writer::export_user_preference('mod_googlemeet', scope::PREF_GRANTED,
                $value ? transform::datetime((int)$value) : '0',
                get_string('privacy:metadata:preference:meetscope', 'googlemeet'));
        }
    }

    /**
     * Delete attendance of an activity, optionally only for some users.
     *
     * @param int $googlemeetid Activity id.
     * @param int[]|null $userids Users, or null for everyone.
     * @return void
     */
    public static function delete(int $googlemeetid, ?array $userids = null): void {
        global $DB;

        if ($userids === null) {
            $DB->delete_records('googlemeet_attendance', ['googlemeetid' => $googlemeetid]);
            return;
        }
        if (!$userids) {
            return;
        }
        [$usql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['googlemeetid'] = $googlemeetid;
        $DB->delete_records_select('googlemeet_attendance', "googlemeetid = :googlemeetid AND userid $usql", $params);
    }
}
