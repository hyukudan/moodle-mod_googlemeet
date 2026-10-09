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
 * Teacher viewing report (ANA-04): students x recordings, inactivity filter, most failed questions.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_googlemeet\local\report_builder;

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);
$groupid = optional_param('group', 0, PARAM_INT);
$inactive = optional_param('inactive', 0, PARAM_BOOL);
$page = optional_param('page', 0, PARAM_INT);
$download = optional_param('download', '', PARAM_ALPHA);

$cm = get_coursemodule_from_id('googlemeet', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$googlemeet = $DB->get_record('googlemeet', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/googlemeet:viewreports', $context);

$perpage = 50;
$builder = new report_builder($googlemeet, $cm, $context);
$groups = $builder->get_allowed_groups($course, (int)$USER->id);
if ($groupid && !isset($groups[$groupid])) {
    $groupid = 0;
}
if (!$groupid && $groups && !has_capability('moodle/site:accessallgroups', $context)
        && (int)$course->groupmode === SEPARATEGROUPS) {
    // Separate groups without access to all: never show students outside the user's groups.
    $groupid = (int)array_key_first($groups);
}

$urlparams = ['id' => $cm->id];
if ($groupid) {
    $urlparams['group'] = $groupid;
}
if ($inactive) {
    $urlparams['inactive'] = 1;
}
$PAGE->set_url('/mod/googlemeet/report.php', $urlparams + ($page ? ['page' => $page] : []));
$PAGE->set_context($context);
$PAGE->set_title($course->shortname . ': ' . $googlemeet->name . ': ' . get_string('report_title', 'googlemeet'));
$PAGE->set_heading($course->fullname);
$PAGE->set_activity_record($googlemeet);
$PAGE->activityheader->disable();

$data = $builder->build($groupid, (bool)$inactive);
$identityfields = $builder->get_identity_fields();

if ($download !== '') {
    $table = report_builder::export_table($data, $identityfields);
    $filename = clean_filename(format_string($googlemeet->name) . '-' . get_string('report_title', 'googlemeet')
        . '-' . userdate(time(), '%Y%m%d'));
    \core\dataformat::download_data($filename, $download, $table['columns'], $table['rows']);
    die();
}

$renderable = new \mod_googlemeet\output\report_page($data, $googlemeet, $cm, $context, [
    'groupid' => $groupid,
    'groups' => $groups,
    'inactive' => $inactive,
    'page' => $page,
    'perpage' => $perpage,
], $identityfields);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('report_title', 'googlemeet'));
echo $OUTPUT->render_from_template('mod_googlemeet/report_page', $renderable->export_for_template($OUTPUT));
if ($data['totalstudents'] > $perpage) {
    echo $OUTPUT->paging_bar($data['totalstudents'], $page, $perpage, new moodle_url('/mod/googlemeet/report.php', $urlparams));
}
if ($data['totalstudents'] > 0) {
    echo $OUTPUT->download_dataformat_selector(get_string('report_download', 'googlemeet'),
        '/mod/googlemeet/report.php', 'download', $urlparams);
}
echo $OUTPUT->footer();
