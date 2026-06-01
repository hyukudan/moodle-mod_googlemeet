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
 * Manage materials attached to a recording.
 *
 * @package     mod_googlemeet
 * @copyright   2026
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/formslib.php');
require_once($CFG->dirroot . '/repository/lib.php');
require_once(__DIR__ . '/locallib.php');

/**
 * Recording materials form.
 */
class mod_googlemeet_material_form extends moodleform {
    /**
     * Defines form elements.
     */
    public function definition() {
        $mform = $this->_form;
        $data = $this->_customdata['data'];
        $options = $this->_customdata['options'];

        $mform->addElement('hidden', 'id', $data->id);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'recording', $data->recording);
        $mform->setType('recording', PARAM_INT);

        $mform->addElement('filemanager', 'materials', get_string('materials_upload', 'googlemeet'), null, $options);
        $this->add_action_buttons(true, get_string('savechanges'));

        $this->set_data($data);
    }
}

$id = required_param('id', PARAM_INT);
$recordingid = required_param('recording', PARAM_INT);

$cm = get_coursemodule_from_id('googlemeet', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$googlemeet = $DB->get_record('googlemeet', ['id' => $cm->instance], '*', MUST_EXIST);
$recording = $DB->get_record('googlemeet_recordings',
    ['id' => $recordingid, 'googlemeetid' => $googlemeet->id], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, true, $cm);
require_capability('mod/googlemeet:editrecording', $context);

$url = new moodle_url('/mod/googlemeet/material.php', ['id' => $cm->id, 'recording' => $recording->id]);
$redirecturl = new moodle_url('/mod/googlemeet/view.php',
    ['id' => $cm->id, 'recording' => $recording->id, 'tab' => 'materials']);

$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_title($course->shortname . ': ' . $googlemeet->name);
$PAGE->set_heading($course->fullname);
$PAGE->set_activity_record($googlemeet);

$maxbytes = get_user_max_upload_file_size($context, $CFG->maxbytes);
$options = ['subdirs' => 1, 'maxbytes' => $maxbytes, 'maxfiles' => -1, 'accepted_types' => '*'];

$data = new stdClass();
$data->id = $cm->id;
$data->recording = $recording->id;
$draftitemid = file_get_submitted_draft_itemid('materials');
file_prepare_draft_area($draftitemid, $context->id, 'mod_googlemeet', 'recordingmaterial',
    $recording->id, $options);
$data->materials = $draftitemid;

$mform = new mod_googlemeet_material_form(null, ['data' => $data, 'options' => $options]);

if ($mform->is_cancelled()) {
    redirect($redirecturl);
} else if ($formdata = $mform->get_data()) {
    require_sesskey();
    file_save_draft_area_files($formdata->materials, $context->id, 'mod_googlemeet',
        'recordingmaterial', $recording->id, $options);
    redirect($redirecturl, get_string('material_saved', 'googlemeet'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo html_writer::link($redirecturl, '&lsaquo; ' . get_string('back'), ['class' => 'btn btn-link px-0']);
echo $OUTPUT->heading(get_string('materials_upload', 'googlemeet') . ': ' . format_string($recording->name), 3);
$mform->display();
echo $OUTPUT->footer();
