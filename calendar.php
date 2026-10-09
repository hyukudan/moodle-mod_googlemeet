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
 * "Add to my calendar": iCalendar (.ics) export of an activity's upcoming sessions (NOT-04).
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);

$cm = get_coursemodule_from_id('googlemeet', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$googlemeet = $DB->get_record('googlemeet', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/googlemeet:view', $context);

$now = time();
$events = \mod_googlemeet\local\calendar_export::get_upcoming_events((int)$googlemeet->id, $now);
$coursename = format_string($course->fullname, true, ['context' => context_course::instance($course->id)]);
$ics = \mod_googlemeet\local\calendar_export::build_ics($googlemeet, (int)$cm->id, $coursename, $events, $now,
    core_date::get_user_timezone());

$filename = clean_filename(format_string($googlemeet->name, true, ['context' => $context])) ?: 'googlemeet';
send_file($ics, $filename . '.ics', 0, 0, true, true, 'text/calendar');
