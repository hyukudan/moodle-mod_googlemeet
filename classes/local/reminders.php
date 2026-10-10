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

namespace mod_googlemeet\local;

use stdClass;

/**
 * Pre-session reminders (NOT-02, NOT-03, NOT-04).
 *
 * Two independent reminders per session, each deduplicated in googlemeet_notify_done by its kind:
 *  - KIND_MINUTES: "minutesbefore" minutes before the start (the historical reminder);
 *  - KIND_HOURS: "notifyhoursbefore" hours before the start (0 = off).
 *
 * The send window is tolerant to cron gaps: a reminder is due from its lead time until the
 * session starts, so a run that comes back late still sends it as long as the session has not
 * started. The hours reminder is skipped once the minutes reminder is already due, so a late
 * run never sends both at once.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reminders {

    /** @var int Reminder "minutesbefore" minutes before the session. */
    const KIND_MINUTES = 0;

    /** @var int Reminder "notifyhoursbefore" hours before the session. */
    const KIND_HOURS = 1;

    /**
     * Options for the hours-before reminder select (site default and activity form).
     *
     * @return array hours => label
     */
    public static function hours_options(): array {
        $options = [0 => get_string('notifyhoursbefore_off', 'googlemeet')];
        foreach ([1, 2, 3, 6, 12, 24, 48, 72] as $hours) {
            $options[$hours] = get_string('numhours', 'moodle', $hours);
        }
        return $options;
    }

    /**
     * Sessions with a reminder due at $now, one entry per (session, reminder kind).
     *
     * Each entry carries: id (event id), kind, eventdate, duration, googlemeetid, googlemeetname,
     * url, cmid, courseid, coursename. Cancelled sessions are left out.
     *
     * @param int $now Reference timestamp.
     * @return stdClass[] Due reminders.
     */
    public static function get_due_events(int $now): array {
        global $DB;
        self::require_libs();

        $select = "SELECT me.id,
                          me.eventdate,
                          me.duration,
                          m.id AS googlemeetid,
                          m.name AS googlemeetname,
                          m.url,
                          cm.id AS cmid,
                          c.id AS courseid,
                          c.fullname AS coursename
                     FROM {googlemeet_events} me
               INNER JOIN {googlemeet} m ON m.id = me.googlemeetid
               INNER JOIN {course_modules} cm
                       ON (cm.instance = m.id AND cm.visible = 1 AND cm.deletioninprogress = 0)
               INNER JOIN {modules} md ON (md.id = cm.module AND md.name = :modname)
               INNER JOIN {course} c ON (c.id = cm.course AND c.visible = 1)
                    WHERE m.notify = 1
                      AND me.eventdate > :now1";

        // Minutes reminder: due from (start - minutesbefore) until the session starts.
        $minutessql = $select . "
                      AND m.minutesbefore > 0
                      AND me.eventdate - m.minutesbefore * 60 <= :now2
                 ORDER BY me.eventdate, me.id";
        // Hours reminder: due from (start - notifyhoursbefore) until the minutes reminder is due.
        $hourssql = $select . "
                      AND m.notifyhoursbefore > 0
                      AND me.eventdate - m.notifyhoursbefore * 3600 <= :now2
                      AND me.eventdate - m.minutesbefore * 60 > :now3
                 ORDER BY me.eventdate, me.id";
        $params = ['modname' => 'googlemeet', 'now1' => $now, 'now2' => $now];
        $queries = [
            self::KIND_MINUTES => [$minutessql, $params],
            self::KIND_HOURS => [$hourssql, $params + ['now3' => $now]],
        ];

        $due = [];
        $cancelled = [];
        foreach ($queries as $kind => [$sql, $kindparams]) {
            $rs = $DB->get_recordset_sql($sql, $kindparams);
            foreach ($rs as $event) {
                $gid = (int)$event->googlemeetid;
                if (!array_key_exists($gid, $cancelled)) {
                    $cancelled[$gid] = \googlemeet_get_cancelled($gid);
                }
                if ($cancelled[$gid] && \googlemeet_is_cancelled((int)$event->eventdate, $cancelled[$gid]) !== false) {
                    continue;
                }
                $event->kind = $kind;
                $due[] = $event;
            }
            $rs->close();
        }
        return $due;
    }

    /**
     * Send every due reminder once per user and kind.
     *
     * @param int $now Reference timestamp.
     * @return int Number of reminders sent.
     */
    public static function run(int $now): int {
        self::require_libs();
        $sent = 0;
        foreach (self::get_due_events($now) as $event) {
            $users = \googlemeet_get_users_to_notify((int)$event->id, (int)$event->kind);
            foreach ($users as $user) {
                // Send first, then record: a crash between the two re-sends next run (a rare
                // duplicate), preferable to recording first and losing the reminder.
                try {
                    self::send($user, $event);
                } catch (\Throwable $e) {
                    mtrace('mod_googlemeet reminder failed for user ' . $user->id . ': ' . $e->getMessage());
                    continue;
                }
                try {
                    \googlemeet_notify_done($user->id, $event->id, (int)$event->kind);
                } catch (\dml_write_exception $e) {
                    // Already recorded by an overlapping run: keep going with the other users.
                    mtrace('mod_googlemeet reminder already recorded for user ' . $user->id . ': ' . $e->getMessage());
                }
                $sent++;
            }
        }
        return $sent;
    }

    /**
     * Build the reminder message for a user (in the user's language).
     *
     * @param stdClass $user Recipient.
     * @param stdClass $event Due reminder row (see get_due_events()).
     * @return \core\message\message
     */
    public static function build_message(stdClass $user, stdClass $event): \core\message\message {
        self::require_libs();
        $previouslang = null;
        if (!empty($user->lang) && get_string_manager()->translation_exists($user->lang, false)) {
            $previouslang = force_current_language($user->lang);
        }
        try {
            $kind = (int)($event->kind ?? self::KIND_MINUTES);
            $a = (object)[
                // Plain-text subject: no HTML escaping (would show "Q&amp;A").
                'name' => format_string($event->googlemeetname, true,
                    ['context' => \context_module::instance($event->cmid), 'escape' => false]),
                'date' => userdate($event->eventdate, get_string('strftimedmy', 'googlemeet'), $user->timezone),
                'start' => userdate($event->eventdate, get_string('strftimehm', 'googlemeet'), $user->timezone),
                'end' => userdate($event->eventdate + $event->duration, get_string('strftimehm', 'googlemeet'),
                    $user->timezone),
                'timezone' => usertimezone($user->timezone),
            ];
            $subjectkey = $kind === self::KIND_HOURS ? 'reminder_subject_hours' : 'reminder_subject_minutes';
            $subject = get_string($subjectkey, 'googlemeet', $a);
            $url = new \moodle_url('/mod/googlemeet/view.php', ['id' => $event->cmid]);
            $html = \googlemeet_get_messagehtml($user, $event);

            $message = new \core\message\message();
            $message->component = 'mod_googlemeet';
            $message->name = 'notification';
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = $user;
            $message->subject = $subject;
            $message->fullmessage = html_to_text($html);
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = $html;
            $message->smallmessage = $subject;
            $message->notification = 1;
            $message->contexturl = $url->out(false);
            $message->contexturlname = $a->name;
            $message->courseid = $event->courseid;
            // NOT-03: lets the Moodle app open the activity from the push notification.
            $message->customdata = self::app_customdata((int)$event->cmid, (int)$event->courseid, $url);
            return $message;
        } finally {
            if ($previouslang !== null) {
                force_current_language($previouslang);
            }
        }
    }

    /**
     * Send one reminder.
     *
     * @param stdClass $user Recipient.
     * @param stdClass $event Due reminder row.
     * @return void
     */
    public static function send(stdClass $user, stdClass $event): void {
        message_send(self::build_message($user, $event));
    }

    /**
     * Custom data the Moodle app uses to open the activity from a push notification.
     *
     * @param int $cmid Course module id.
     * @param int $courseid Course id.
     * @param \moodle_url $url Activity URL.
     * @return array
     */
    public static function app_customdata(int $cmid, int $courseid, \moodle_url $url): array {
        return [
            'cmid' => $cmid,
            'courseid' => $courseid,
            'appurl' => $url->out(false),
        ];
    }

    /**
     * Load the plugin libraries the reminder helpers rely on.
     *
     * @return void
     */
    private static function require_libs(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/googlemeet/lib.php');
        require_once($CFG->dirroot . '/mod/googlemeet/locallib.php');
    }
}
