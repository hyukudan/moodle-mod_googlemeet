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
 * Teacher view of the real attendance read from Google Meet (ANA-03), with CSV export.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_googlemeet\local\attendance\report;
use mod_googlemeet\local\attendance\scope;
use mod_googlemeet\local\attendance\service;

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);
$sessionid = optional_param('session', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$download = optional_param('download', '', PARAM_ALPHA);

$cm = get_coursemodule_from_id('googlemeet', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$googlemeet = $DB->get_record('googlemeet', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/googlemeet:viewreports', $context);

$baseurl = new moodle_url('/mod/googlemeet/attendance.php', ['id' => $cm->id]);
$PAGE->set_url($baseurl, $sessionid ? ['session' => $sessionid] : []);
$PAGE->set_context($context);
$PAGE->set_title($course->shortname . ': ' . $googlemeet->name . ': ' . get_string('attendance_title', 'googlemeet'));
$PAGE->set_heading($course->fullname);
$PAGE->set_activity_record($googlemeet);
$PAGE->activityheader->disable();

$session = null;
if ($sessionid) {
    $session = $DB->get_record('googlemeet_events', ['id' => $sessionid, 'googlemeetid' => $googlemeet->id]);
    if (!$session) {
        redirect($baseurl);
    }
}

// Actions.
if ($action === 'relink') {
    require_sesskey();
    // Only the organiser's link matters for the attendance fetch: nobody else unlinks their account here.
    if (!scope::is_organiser($googlemeet, $USER)) {
        redirect($baseurl);
    }
    scope::reset_link((int)$USER->id);
    redirect($baseurl, get_string('attendance_relink_done', 'googlemeet'), null, \core\output\notification::NOTIFY_INFO);
}
if ($action === 'refetch' && $session) {
    require_sesskey();
    service::request_refetch((int)$googlemeet->id, (int)$session->id);
    redirect(new moodle_url($baseurl, ['session' => $session->id]), get_string('attendance_refetch_queued', 'googlemeet'),
        null, \core\output\notification::NOTIFY_SUCCESS);
}
if ($action === 'link' && $session) {
    require_sesskey();
    $attendanceid = required_param('attendanceid', PARAM_INT);
    $userid = required_param('userid', PARAM_INT);
    try {
        service::link_manually($googlemeet, $attendanceid, $userid);
        $message = get_string($userid ? 'attendance_linked' : 'attendance_unlinked', 'googlemeet');
        $type = \core\output\notification::NOTIFY_SUCCESS;
    } catch (moodle_exception $e) {
        $message = $e->getMessage();
        $type = \core\output\notification::NOTIFY_ERROR;
    }
    redirect(new moodle_url($baseurl, ['session' => $session->id]), $message, null, $type);
}

$rows = $session ? report::session_rows($googlemeet, (int)$session->id) : [];

if ($download !== '' && $session) {
    $table = report::export_table($rows, $context);
    $filename = clean_filename(format_string($googlemeet->name) . '-' . get_string('attendance_title', 'googlemeet')
        . '-' . userdate($session->eventdate, '%Y%m%d-%H%M'));
    \core\dataformat::download_data($filename, $download, $table['columns'], $table['rows']);
    die();
}

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($googlemeet->name) . ': ' . get_string('attendance_title', 'googlemeet'));

if (!scope::site_enabled()) {
    echo $OUTPUT->notification(get_string('attendance_site_disabled', 'googlemeet'), \core\output\notification::NOTIFY_INFO);
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::tag('p', get_string('attendance_intro', 'googlemeet'), ['class' => 'text-muted']);

if (empty($googlemeet->attendanceenabled)) {
    $editurl = new moodle_url('/course/modedit.php', ['update' => $cm->id, 'return' => 1]);
    echo $OUTPUT->notification(get_string('attendance_activity_disabled', 'googlemeet', $editurl->out()),
        \core\output\notification::NOTIFY_INFO);
}

echo scope::relink_notice($googlemeet, $context);

// Organiser without a Google link: offer the login popup here (callback reloads this page).
$isorganiser = scope::is_organiser($googlemeet, $USER);
if ($isorganiser) {
    $client = new \mod_googlemeet\client();
    if ($client->enabled && !$client->check_login()) {
        echo html_writer::div(get_string('attendance_login_needed', 'googlemeet') . $client->print_login_popup(),
            'alert alert-info');
    }
}

$now = time();
$sessions = service::get_sessions((int)$googlemeet->id, $now);
if (!$sessions) {
    echo $OUTPUT->notification(get_string('attendance_no_sessions', 'googlemeet'), \core\output\notification::NOTIFY_INFO);
    echo $OUTPUT->footer();
    exit;
}

// Sessions list.
$table = new html_table();
$table->attributes['class'] = 'generaltable googlemeet-attendance-sessions';
$table->head = [
    get_string('attendance_col_session', 'googlemeet'),
    get_string('attendance_col_status', 'googlemeet'),
    get_string('attendance_col_participants', 'googlemeet'),
    '',
];
foreach ($sessions as $s) {
    $label = userdate($s->eventdate, get_string('strftimedatetimeshort', 'langconfig'));
    $viewurl = new moodle_url($baseurl, ['session' => $s->id]);
    $counts = $s->status === service::STATUS_DONE
        ? get_string('attendance_counts', 'googlemeet', (object)['matched' => (int)$s->matched, 'total' => (int)$s->participants])
        : '';
    $cells = [
        $sessionid == $s->id ? html_writer::tag('strong', $label) : html_writer::link($viewurl, $label),
        report::status_label($s->status),
        $counts,
        html_writer::link($viewurl, get_string('view')),
    ];
    $table->data[] = $cells;
}
echo $OUTPUT->heading(get_string('attendance_sessions', 'googlemeet'), 3);
echo html_writer::table($table);

if ($session) {
    $sync = $DB->get_record('googlemeet_attendance_sync', ['eventid' => $session->id]);
    echo $OUTPUT->heading(get_string('attendance_session_heading', 'googlemeet',
        userdate($session->eventdate, get_string('strftimedatetime', 'langconfig'))), 3);
    echo html_writer::tag('p', report::status_label($sync->status ?? null)
        . ($sync && $sync->status === service::STATUS_ERROR && $sync->message !== null
            ? ' · ' . s(shorten_text($sync->message, 200)) : ''));

    $refetchurl = new moodle_url($baseurl, ['session' => $session->id, 'action' => 'refetch', 'sesskey' => sesskey()]);
    echo html_writer::div(
        html_writer::link($refetchurl, get_string('attendance_refetch', 'googlemeet'), ['class' => 'btn btn-secondary me-2']),
        'mb-3');

    if ($rows) {
        echo $OUTPUT->download_dataformat_selector(get_string('attendance_download', 'googlemeet'),
            new moodle_url('/mod/googlemeet/attendance.php'), 'download', ['id' => $cm->id, 'session' => $session->id]);

        $candidates = service::get_candidates($googlemeet);
        $options = [0 => get_string('attendance_link_choose', 'googlemeet')];
        foreach ($candidates as $user) {
            $options[$user->id] = s(fullname($user));
        }
        core_collator::asort($options);

        $table = new html_table();
        $table->attributes['class'] = 'generaltable googlemeet-attendance-table';
        $table->head = [
            get_string('attendance_col_student', 'googlemeet'),
            get_string('attendance_col_status', 'googlemeet'),
            get_string('attendance_col_meetname', 'googlemeet'),
            get_string('attendance_col_joined', 'googlemeet'),
            get_string('attendance_col_left', 'googlemeet'),
            get_string('attendance_col_minutes', 'googlemeet'),
        ];
        foreach ($rows as $row) {
            $name = s($row['name']);
            if ($row['kind'] === report::ROW_UNMATCHED) {
                $form = html_writer::start_tag('form', ['method' => 'post', 'action' => $baseurl->out(false),
                    'class' => 'd-flex flex-wrap gap-1 mt-1 googlemeet-attendance-link']);
                foreach (['id' => $cm->id, 'session' => $session->id, 'action' => 'link', 'sesskey' => sesskey(),
                        'attendanceid' => $row['attendanceid']] as $k => $v) {
                    $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $k, 'value' => $v]);
                }
                $selectid = 'googlemeet-attendance-link-' . $row['attendanceid'];
                $form .= html_writer::label(get_string('attendance_link_label', 'googlemeet', s($row['meetname'])), $selectid,
                    false, ['class' => 'visually-hidden']);
                $form .= html_writer::select($options, 'userid', 0, false, ['id' => $selectid,
                    'class' => 'form-select form-select-sm w-auto']);
                $form .= html_writer::tag('button', get_string('attendance_link', 'googlemeet'),
                    ['type' => 'submit', 'class' => 'btn btn-sm btn-outline-primary']);
                $form .= html_writer::end_tag('form');
                $name .= $form;
            } else if ($row['kind'] === report::ROW_PRESENT && $row['attendanceid']) {
                // Undo a wrong (automatic or manual) match: the participant goes back to "not matched"
                // and stays so on later fetches.
                $form = html_writer::start_tag('form', ['method' => 'post', 'action' => $baseurl->out(false),
                    'class' => 'd-inline googlemeet-attendance-unlink']);
                foreach (['id' => $cm->id, 'session' => $session->id, 'action' => 'link', 'sesskey' => sesskey(),
                        'attendanceid' => $row['attendanceid'], 'userid' => 0] as $k => $v) {
                    $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $k, 'value' => $v]);
                }
                $form .= html_writer::tag('button', get_string('attendance_unlink', 'googlemeet'),
                    ['type' => 'submit', 'class' => 'btn btn-link btn-sm p-0 ms-2',
                        'title' => get_string('attendance_unlink_title', 'googlemeet', s($row['meetname']))]);
                $form .= html_writer::end_tag('form');
                $name .= $form;
            }
            $status = get_string('attendance_kind_' . $row['kind'], 'googlemeet');
            if ($row['matchedby'] !== '') {
                $status .= html_writer::tag('small', ' (' . get_string('attendance_matchedby_' . $row['matchedby'],
                    'googlemeet') . ')', ['class' => 'text-muted']);
            }
            $table->data[] = [
                $name,
                $status,
                s($row['meetname']),
                $row['timejoined'] ? userdate($row['timejoined'], get_string('strftimetime', 'langconfig')) : '',
                $row['timeleft'] ? userdate($row['timeleft'], get_string('strftimetime', 'langconfig')) : '',
                $row['kind'] === report::ROW_ABSENT ? '' : (int)round($row['durationseconds'] / 60),
            ];
        }
        echo html_writer::div(html_writer::table($table), 'table-responsive');
    }
}

echo $OUTPUT->footer();
