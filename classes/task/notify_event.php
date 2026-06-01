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

/**
 * Google Meet task - Send notification.
 *
 * @package     mod_googlemeet
 * @copyright   2020 Rone Santos <ronefel@hotmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_googlemeet\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/googlemeet/locallib.php');

/**
 * Send notification about the start of the event.
 *
 * @package     mod_googlemeet
 * @category    external
 * @copyright   2020 Rone Santos <ronefel@hotmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class notify_event extends \core\task\scheduled_task {
    /**
     * Returns localised general event name.
     *
     * @return string
     */
    public function get_name() {
        // Shown in admin screens.
        return get_string('notifytask', 'mod_googlemeet');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        // Guard against overlapping runs (or a run that outlives the 5-minute interval) both
        // selecting the same not-yet-notified user before either records notify_done, which would
        // double-send and then collide on the (eventid, userid) unique index. If another run
        // holds the lock, skip this tick rather than wait.
        $lockfactory = \core\lock\lock_config::get_lock_factory('mod_googlemeet_notify');
        $lock = $lockfactory->get_lock('notify_event', 0);
        if (!$lock) {
            mtrace('mod_googlemeet notify: another run holds the lock; skipping this tick.');
            return;
        }

        try {
            $events = googlemeet_get_future_events();

            if ($events) {
                foreach ($events as $event) {
                    $users = googlemeet_get_users_to_notify($event->id);

                    foreach ($users as $user) {
                        // get_users_to_notify() already excludes users with a notify_done row, and
                        // the lock prevents a concurrent run from selecting the same user, so the
                        // unique (eventid, userid) index is never violated here. Send first, then
                        // record: a crash between the two re-sends next run (a rare duplicate),
                        // which is preferable to recording first and losing the reminder if the
                        // send fails.
                        googlemeet_send_notification($user, $event);

                        googlemeet_notify_done($user->id, $event->id);
                    }
                }
            }

            googlemeet_remove_notify_done_from_old_events();
        } finally {
            $lock->release();
        }
    }
}
