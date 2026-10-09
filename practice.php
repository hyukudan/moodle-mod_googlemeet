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
 * Activity-wide practice: "review my failed questions" and "practise by topic".
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_googlemeet\local\practice_attempts;

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);
$mode = optional_param('mode', '', PARAM_ALPHA);
$topic = optional_param('topic', '', PARAM_TEXT);

$cm = get_coursemodule_from_id('googlemeet', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$googlemeet = $DB->get_record('googlemeet', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/googlemeet:view', $context);

if (!in_array($mode, ['', practice_attempts::MODE_FAILED, practice_attempts::MODE_TOPIC], true)
        || ($mode === practice_attempts::MODE_TOPIC && trim($topic) === '')) {
    $mode = '';
}

$urlparams = ['id' => $cm->id];
if ($mode !== '') {
    $urlparams['mode'] = $mode;
}
if ($mode === practice_attempts::MODE_TOPIC) {
    $urlparams['topic'] = $topic;
}
$landingurl = new moodle_url('/mod/googlemeet/practice.php', ['id' => $cm->id]);
$PAGE->set_url('/mod/googlemeet/practice.php', $urlparams);
$PAGE->set_context($context);
$PAGE->set_title($course->shortname . ': ' . $googlemeet->name . ': ' . get_string('practice_page_title', 'googlemeet'));
$PAGE->set_heading($course->fullname);
$PAGE->set_activity_record($googlemeet);
$PAGE->activityheader->disable();

if ($mode === '') {
    $failedcount = practice_attempts::count_failed_questions($googlemeet, $cm, $context, (int)$USER->id);
    $topics = [];
    foreach (practice_attempts::get_practice_topics($googlemeet, $cm, $context) as $t) {
        $topics[] = [
            'topic' => format_string($t['topic'], true, ['context' => $context]),
            'url' => (new moodle_url('/mod/googlemeet/practice.php',
                ['id' => $cm->id, 'mode' => practice_attempts::MODE_TOPIC, 'topic' => $t['topic']]))->out(false),
            'meta' => get_string('practice_topic_meta', 'googlemeet',
                ['recordings' => $t['recordings'], 'questions' => $t['questions']]),
        ];
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('practice_page_title', 'googlemeet'));
    echo $OUTPUT->render_from_template('mod_googlemeet/practice_page', [
        'backurl' => (new moodle_url('/mod/googlemeet/view.php', ['id' => $cm->id]))->out(false),
        'failedcount' => $failedcount,
        'hasfailed' => $failedcount > 0,
        'failedurl' => (new moodle_url('/mod/googlemeet/practice.php',
            ['id' => $cm->id, 'mode' => practice_attempts::MODE_FAILED]))->out(false),
        'hastopics' => !empty($topics),
        'topics' => $topics,
    ]);
    echo $OUTPUT->footer();
    exit;
}

$title = $mode === practice_attempts::MODE_FAILED
    ? get_string('practice_failed_title', 'googlemeet')
    : get_string('practice_topic_session_title', 'googlemeet', format_string($topic, true, ['context' => $context]));

$PAGE->requires->js_call_amd('mod_googlemeet/practice_session', 'init', [[
    'cmid' => (int)$cm->id,
    'mode' => $mode,
    'topic' => $mode === practice_attempts::MODE_TOPIC ? $topic : '',
    'hubbaseurl' => (new moodle_url('/mod/googlemeet/view.php', ['id' => $cm->id]))->out(false),
]]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('practice_page_title', 'googlemeet'));
echo $OUTPUT->render_from_template('mod_googlemeet/practice_session', [
    'title' => $title,
    'landingurl' => $landingurl->out(false),
]);
echo $OUTPUT->footer();
