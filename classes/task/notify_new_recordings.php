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

/**
 * Send notifications when new recordings are synced.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class notify_new_recordings extends \core\task\adhoc_task {

    /**
     * Execute the task.
     */
    public function execute() {
        global $CFG, $DB;

        $data = $this->get_custom_data();
        $googlemeetid = (int)($data->googlemeetid ?? 0);
        $newcount = (int)($data->newcount ?? 0);

        if (!$googlemeetid || !$newcount) {
            return;
        }

        $sql = "SELECT g.id,
                       g.name,
                       g.course,
                       cm.id AS cmid
                  FROM {googlemeet} g
            INNER JOIN {course_modules} cm ON cm.instance = g.id
            INNER JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                 WHERE g.id = :googlemeetid";
        $googlemeet = $DB->get_record_sql($sql, ['googlemeetid' => $googlemeetid, 'modname' => 'googlemeet']);

        if (!$googlemeet) {
            return;
        }

        $context = \context_module::instance($googlemeet->cmid);
        $url = new \moodle_url('/mod/googlemeet/view.php', ['id' => $googlemeet->cmid]);
        $subscribers = $DB->get_records('googlemeet_recording_subs', ['googlemeetid' => $googlemeetid]);

        foreach ($subscribers as $subscriber) {
            try {
                $user = $DB->get_record('user', ['id' => $subscriber->userid, 'deleted' => 0], '*', IGNORE_MISSING);
                if (!$user || !is_enrolled($context, $user, 'mod/googlemeet:view', true) ||
                        !has_capability('mod/googlemeet:view', $context, $user)) {
                    continue;
                }

                $a = (object)[
                    'name' => format_string($googlemeet->name, true, ['context' => $context]),
                    'count' => $newcount,
                    'url' => $url->out(false),
                ];
                $subject = get_string('recordingavailable_subject', 'googlemeet', $a);
                $body = get_string('recordingavailable_body', 'googlemeet', $a);

                $message = new \core\message\message();
                $message->component = 'mod_googlemeet';
                $message->name = 'recordingavailable';
                $message->userfrom = \core_user::get_noreply_user();
                $message->userto = $user;
                $message->subject = $subject;
                $message->fullmessage = $body;
                $message->fullmessageformat = FORMAT_MARKDOWN;
                $message->fullmessagehtml = format_text($body, FORMAT_MARKDOWN);
                $message->smallmessage = $subject;
                $message->notification = 1;
                $message->contexturl = $url->out(false);
                $message->contexturlname = $googlemeet->name;
                $message->courseid = $googlemeet->course;

                message_send($message);
            } catch (\Throwable $e) {
                mtrace('mod_googlemeet recording notification failed for user ' . $subscriber->userid . ': ' .
                    $e->getMessage());
            }
        }
    }
}
