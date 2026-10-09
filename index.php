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
 * Display information about all the mod_googlemeet modules in the requested course.
 *
 * @package     mod_googlemeet
 * @copyright   2020 Rone Santos <ronefel@hotmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__.'/../../config.php');

require_once(__DIR__.'/lib.php');
require_once(__DIR__.'/locallib.php');
require_once($CFG->dirroot.'/course/lib.php');

$id = required_param('id', PARAM_INT);

$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);
require_course_login($course);

$coursecontext = context_course::instance($course->id);

$event = \mod_googlemeet\event\course_module_instance_list_viewed::create([
    'context' => $coursecontext
]);
$event->add_record_snapshot('course', $course);
$event->trigger();

$modulenameplural = get_string('modulenameplural', 'mod_googlemeet');

// Only the activities this user can see (hidden ones stay listed, dimmed, for teachers).
$modinfo = get_fast_modinfo($course);
$cms = [];
foreach ($modinfo->get_instances_of('googlemeet') as $cm) {
    if ($cm->uservisible) {
        $cms[$cm->instance] = $cm;
    }
}

if (empty($cms)) {
    redirect(
        new moodle_url('/course/view.php', ['id' => $course->id]),
        get_string('thereareno', 'moodle', $modulenameplural),
        null,
        \core\output\notification::NOTIFY_INFO
    );
}

$PAGE->set_url('/mod/googlemeet/index.php', ['id' => $id]);
$PAGE->set_title(format_string($course->fullname));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($coursecontext);

echo $OUTPUT->header();
echo $OUTPUT->heading($modulenameplural);

$usesections = course_format_uses_sections($course->format);
$googlemeets = $DB->get_records_list('googlemeet', 'id', array_keys($cms));

// UX-07: next session, number of recordings and, for students, their own progress.
$showprogress = false;
$rows = [];
foreach ($cms as $instanceid => $cm) {
    if (empty($googlemeets[$instanceid])) {
        continue;
    }
    $googlemeet = $googlemeets[$instanceid];
    $context = context_module::instance($cm->id);
    $isteacher = has_capability('mod/googlemeet:editrecording', $context);

    $attrs = $cm->visible ? [] : ['class' => 'dimmed'];
    $link = html_writer::link($cm->url, $cm->get_formatted_name(), $attrs);

    // Next session: the first upcoming (or live) session that is not cancelled.
    $nextsession = get_string('index_nextsession_none', 'mod_googlemeet');
    $upcoming = googlemeet_get_upcoming_events($googlemeet->id, 10);
    foreach ($upcoming['upcomingevents'] as $event) {
        if (!empty($event->iscancelled)) {
            continue;
        }
        $nextsession = !empty($event->islive)
            ? get_string('index_nextsession_live', 'mod_googlemeet')
            : $event->startdate . ' ' . $event->starttime;
        break;
    }

    // Recordings: students only count the visible ones; the trash never counts.
    $countparams = ['googlemeetid' => $googlemeet->id];
    if (!$isteacher) {
        $countparams['visible'] = 1;
    }
    $recordingcount = googlemeet_count_recordings($countparams);

    $progress = '';
    if (!$isteacher && $recordingcount > 0) {
        $showprogress = true;
        $summary = googlemeet_get_progress_summary($googlemeet, $context);
        $progress = get_string('index_myprogress_value', 'mod_googlemeet', (object)[
            'watched' => $summary['watched'],
            'total' => $summary['total'],
            'pct' => $summary['pct'],
        ]);
    }

    $row = [];
    if ($usesections) {
        $row[] = get_section_name($course, $cm->sectionnum);
    }
    $row[] = $link;
    $row[] = $nextsession;
    $row[] = $recordingcount;
    $row['progress'] = $progress;
    $rows[] = $row;
}

$table = new html_table();
$table->attributes['class'] = 'generaltable mod_index';
$table->head = [];
if ($usesections) {
    $table->head[] = get_string('sectionname', 'format_'.$course->format);
}
$table->head[] = get_string('name');
$table->head[] = get_string('index_nextsession', 'mod_googlemeet');
$table->head[] = get_string('index_recordings', 'mod_googlemeet');
if ($showprogress) {
    $table->head[] = get_string('index_myprogress', 'mod_googlemeet');
}
foreach ($rows as $row) {
    $progress = $row['progress'];
    unset($row['progress']);
    if ($showprogress) {
        $row[] = $progress;
    }
    $table->data[] = array_values($row);
}

echo html_writer::table($table);
echo $OUTPUT->footer();
