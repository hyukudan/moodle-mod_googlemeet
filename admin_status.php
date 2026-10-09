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
 * Admin page: Google Meet sync status (OPS-04).
 *
 * Failed/exhausted autosync in the last week, stuck AI analyses, failed Calendar updates and
 * the last sync of every activity, so admins do not need to read cron logs.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use mod_googlemeet\local\ops_status;

admin_externalpage_setup('mod_googlemeet_status');

$PAGE->set_url(new moodle_url('/mod/googlemeet/admin_status.php'));
$PAGE->set_title(get_string('adminstatus', 'googlemeet'));
$PAGE->set_heading(get_string('adminstatus', 'googlemeet'));

/**
 * Link to the activity (or plain name when it no longer exists).
 *
 * @param stdClass $row Has name and cmid.
 * @return string HTML.
 */
function mod_googlemeet_admin_status_activity_link(stdClass $row): string {
    if (empty($row->cmid)) {
        return s($row->name ?? get_string('adminstatus_deleted', 'googlemeet'));
    }
    return html_writer::link(new moodle_url('/mod/googlemeet/view.php', ['id' => $row->cmid]), format_string($row->name));
}

/**
 * Date or "never".
 *
 * @param int|null $time
 * @return string
 */
function mod_googlemeet_admin_status_date(?int $time): string {
    return $time ? userdate($time, get_string('strftimedatetimeshort', 'langconfig')) : get_string('never', 'googlemeet');
}

/**
 * Localised status label.
 *
 * @param string $status
 * @return string
 */
function mod_googlemeet_admin_status_label(string $status): string {
    if ($status === '') {
        return '-';
    }
    $key = 'adminstatus_status_' . $status;
    return get_string_manager()->string_exists($key, 'googlemeet') ? get_string($key, 'googlemeet') : s($status);
}

echo $OUTPUT->header();
echo html_writer::tag('p', get_string('adminstatus_intro', 'googlemeet', ops_status::WINDOW_DAYS));

// 1. Autosync problems.
echo $OUTPUT->heading(get_string('adminstatus_autosync', 'googlemeet', ops_status::WINDOW_DAYS), 3);
$rows = ops_status::autosync_problems();
if (!$rows) {
    echo $OUTPUT->notification(get_string('adminstatus_none', 'googlemeet'), \core\output\notification::NOTIFY_SUCCESS);
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable googlemeet-admin-status';
    $table->head = [
        get_string('course'), get_string('adminstatus_col_activity', 'googlemeet'), get_string('adminstatus_col_creator', 'googlemeet'),
        get_string('adminstatus_failedruns', 'googlemeet'), get_string('adminstatus_exhausted', 'googlemeet'),
        get_string('adminstatus_retrying', 'googlemeet'), get_string('adminstatus_lastfailure', 'googlemeet'),
        get_string('adminstatus_col_lastsync', 'googlemeet'),
    ];
    foreach ($rows as $row) {
        $last = $row->lastfailure ? mod_googlemeet_admin_status_date($row->lastfailure) . ' · '
            . mod_googlemeet_admin_status_label($row->laststatus)
            . ($row->lastmessage !== '' ? html_writer::div(s($row->lastmessage), 'small text-muted') : '') : '-';
        $table->data[] = [
            format_string($row->coursename), mod_googlemeet_admin_status_activity_link($row), s($row->creatoremail),
            $row->failedruns, $row->exhausted, $row->retrying, $last, mod_googlemeet_admin_status_date((int)$row->lastsync),
        ];
    }
    echo html_writer::div(html_writer::table($table), 'table-responsive');
}

// 2. Stuck AI analyses.
echo $OUTPUT->heading(get_string('adminstatus_stuckai', 'googlemeet'), 3);
$rows = ops_status::stuck_ai_analyses();
if (!$rows) {
    echo $OUTPUT->notification(get_string('adminstatus_none', 'googlemeet'), \core\output\notification::NOTIFY_SUCCESS);
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable googlemeet-admin-status';
    $table->head = [
        get_string('course'), get_string('adminstatus_col_activity', 'googlemeet'), get_string('adminstatus_recording', 'googlemeet'),
        get_string('status'), get_string('adminstatus_since', 'googlemeet'), get_string('adminstatus_retries', 'googlemeet'),
        get_string('error'),
    ];
    foreach ($rows as $row) {
        $table->data[] = [
            format_string($row->coursename), mod_googlemeet_admin_status_activity_link($row), s($row->recordingname),
            s($row->status), mod_googlemeet_admin_status_date((int)$row->timemodified), (int)$row->retrycount,
            s(shorten_text((string)$row->error, 200)),
        ];
    }
    echo html_writer::div(html_writer::table($table), 'table-responsive');
}

// 3. Calendar failures.
echo $OUTPUT->heading(get_string('adminstatus_calendar', 'googlemeet', ops_status::WINDOW_DAYS), 3);
$rows = ops_status::calendar_failures();
if (!$rows) {
    echo $OUTPUT->notification(get_string('adminstatus_none', 'googlemeet'), \core\output\notification::NOTIFY_SUCCESS);
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable googlemeet-admin-status';
    $table->head = [get_string('adminstatus_col_activity', 'googlemeet'), get_string('adminstatus_operation', 'googlemeet'),
        get_string('date'), get_string('error')];
    foreach ($rows as $row) {
        $table->data[] = [
            mod_googlemeet_admin_status_activity_link($row) . ' (#' . (int)$row->googlemeetid . ')',
            get_string('adminstatus_kind_' . $row->kind, 'googlemeet'),
            mod_googlemeet_admin_status_date((int)$row->timequeued), s((string)$row->message),
        ];
    }
    echo html_writer::div(html_writer::table($table), 'table-responsive');
}

// 4. Last sync per activity.
echo $OUTPUT->heading(get_string('adminstatus_lastsyncs', 'googlemeet'), 3);
$rows = ops_status::last_syncs();
$table = new html_table();
$table->attributes['class'] = 'generaltable googlemeet-admin-status';
$table->head = [
    get_string('course'), get_string('adminstatus_col_activity', 'googlemeet'), get_string('adminstatus_col_lastsync', 'googlemeet'),
    get_string('adminstatus_autosynchours', 'googlemeet'), get_string('adminstatus_lastmanual', 'googlemeet'),
    get_string('adminstatus_lastauto', 'googlemeet'),
];
foreach ($rows as $row) {
    $table->data[] = [
        format_string($row->coursename), mod_googlemeet_admin_status_activity_link($row),
        mod_googlemeet_admin_status_date((int)$row->lastsync),
        (int)$row->autosynchours > 0 ? (int)$row->autosynchours : get_string('no'),
        $row->manualtime ? mod_googlemeet_admin_status_date($row->manualtime) . ' · '
            . mod_googlemeet_admin_status_label($row->manualstatus) : '-',
        $row->autotime ? mod_googlemeet_admin_status_date($row->autotime) . ' · '
            . mod_googlemeet_admin_status_label($row->autostatus) : '-',
    ];
}
echo html_writer::div(html_writer::table($table), 'table-responsive');
if (count($rows) >= ops_status::LAST_SYNC_LIMIT) {
    echo html_writer::tag('p', get_string('adminstatus_truncated', 'googlemeet', ops_status::LAST_SYNC_LIMIT), ['class' => 'small']);
}

echo $OUTPUT->footer();
