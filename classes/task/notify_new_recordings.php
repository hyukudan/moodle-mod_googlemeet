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
        $recordingids = array_values(array_filter(array_map('intval', $data->recordingids ?? [])));

        if (!$googlemeetid || !$newcount) {
            return;
        }

        if (!empty($recordingids)) {
            list($insql, $inparams) = $DB->get_in_or_equal($recordingids, SQL_PARAMS_NAMED);
            $params = $inparams + [
                'googlemeetid' => $googlemeetid,
                'deleted' => 0,
            ];
            $newcount = (int)$DB->count_records_select(
                'googlemeet_recordings',
                "googlemeetid = :googlemeetid AND deleted = :deleted AND id $insql",
                $params
            );
            if ($newcount <= 0) {
                return;
            }
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
                    'user' => !empty($user->firstname) ? $user->firstname : '',
                ];
                // Proper singular/plural (no robotic "(s)").
                $variant = ($newcount == 1) ? '_one' : '_many';
                $subject = get_string('recordingavailable_subject' . $variant, 'googlemeet', $a);
                $body = get_string('recordingavailable_body' . $variant, 'googlemeet', $a);

                $message = new \core\message\message();
                $message->component = 'mod_googlemeet';
                $message->name = 'recordingavailable';
                $message->userfrom = \core_user::get_noreply_user();
                $message->userto = $user;
                $message->subject = $subject;
                $message->fullmessage = $body;
                $message->fullmessageformat = FORMAT_MARKDOWN;
                $message->fullmessagehtml = format_text($body, FORMAT_MARKDOWN);

                // Branded HTML version (shared campus template), consistent with the
                // other transactional notifications. Falls back to the markdown HTML above.
                if (class_exists('\\local_achievements\\email_template')) {
                    try {
                        $fn = !empty($user->firstname) ? s($user->firstname) : '';
                        $isone = ($newcount == 1);
                        $title = $isone
                            ? '🎥 Nueva grabación disponible'
                            : '🎥 ' . (int)$newcount . ' nuevas grabaciones disponibles';
                        $message->fullmessagehtml = self::build_recording_notice_html(
                            $title,
                            (string)$a->name,
                            (int)$newcount,
                            $url->out(false),
                            $fn
                        );
                    } catch (\Throwable $e) {
                        debugging('mod_googlemeet: branded recording notice render failed — ' . $e->getMessage(), DEBUG_DEVELOPER);
                    }
                }
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

    /**
     * Build the branded recording notice HTML without sending.
     *
     * @param string $title Wrapper title.
     * @param string $meetingname Meeting name.
     * @param int $newcount Number of new recordings.
     * @param string $url Activity URL.
     * @param string $firstname Escaped recipient first name, or empty.
     * @return string Full HTML email.
     */
    public static function build_recording_notice_html(
        string $title,
        string $meetingname,
        int $newcount,
        string $url,
        string $firstname = ''
    ): string {
        $et = \local_achievements\email_template::class;
        $isone = ($newcount === 1);
        $hbody  = $et::text(($firstname ? 'Hola ' . $firstname . ',' : 'Hola,'), 'left');
        $hbody .= $et::text($isone
            ? 'Ya tienes disponible una nueva grabación en <strong>' . s($meetingname) . '</strong>.'
            : 'Ya tienes disponibles <strong>' . (int)$newcount . '</strong> nuevas grabaciones en <strong>' . s($meetingname) . '</strong>.');
        $hbody .= $et::button($url, $isone ? 'Ver la grabación' : 'Ver las grabaciones', '#2563eb');
        $hbody .= $et::text('¡A repasar! Revisar las clases es una de las mejores formas de fijar el temario.', 'center');

        return $et::wrap($title, $hbody);
    }
}
