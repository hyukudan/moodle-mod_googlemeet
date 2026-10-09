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
 * Prints an instance of mod_googlemeet.
 *
 * @package     mod_googlemeet
 * @copyright   2020 Rone Santos <ronefel@hotmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_googlemeet\client;

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/locallib.php');

$config = get_config('googlemeet');

$id = optional_param('id', 0, PARAM_INT);
$g = optional_param('g', 0, PARAM_INT);
$recordingid = optional_param('recording', 0, PARAM_INT);

if ($id) {
    $cm = get_coursemodule_from_id('googlemeet', $id, 0, false, MUST_EXIST);
    $course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
    $googlemeet = $DB->get_record('googlemeet', array('id' => $cm->instance), '*', MUST_EXIST);
} else if ($g) {
    $googlemeet = $DB->get_record('googlemeet', array('id' => $g), '*', MUST_EXIST);
    $course = $DB->get_record('course', array('id' => $googlemeet->course), '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('googlemeet', $googlemeet->id, $course->id, false, MUST_EXIST);
} else {
    throw new moodle_exception('missingidandcmid', 'mod_googlemeet');
}

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/googlemeet:view', $context);

$pageparams = ['id' => $cm->id];
if ($recordingid > 0) {
    $pageparams['recording'] = $recordingid;
}
$PAGE->set_url('/mod/googlemeet/view.php', $pageparams);
$PAGE->set_context($context);

// Process the view write actions (logout / sync). The handler enforces the
// editrecording capability, the sesskey check and (for sync) a POST request.
googlemeet_handle_view_actions($googlemeet, $cm, $course);
googlemeet_handle_subscription_action($googlemeet, $cm);

// Recordings view preference (cards|list). Setting it via the optional `rview`
// GET param only updates the per-user preference, then redirects to a clean URL
// (rview removed) preserving the content state params. No sesskey is required:
// it only changes the user's own view preference and is idempotent. This must run
// before any output is produced so redirect() is legal.
$rview = optional_param('rview', '', PARAM_ALPHA);
if ($rview === 'cards' || $rview === 'list') {
    set_user_preference('mod_googlemeet_recordings_view', $rview);

    $redirectparams = ['id' => $cm->id];
    $rviewpage = optional_param('rpage', 0, PARAM_INT);
    $rvieworder = optional_param('rorder', '', PARAM_ALPHA);
    $rviewquery = optional_param('rq', '', PARAM_TEXT);
    $rviewtopic = optional_param('topic', '', PARAM_TEXT);
    if ($rviewpage > 0) {
        $redirectparams['rpage'] = $rviewpage;
    }
    if ($rvieworder !== '') {
        $redirectparams['rorder'] = $rvieworder;
    }
    if (trim($rviewquery) !== '') {
        $redirectparams['rq'] = $rviewquery;
    }
    if (trim($rviewtopic) !== '') {
        $redirectparams['topic'] = $rviewtopic;
    }
    if (optional_param('rpending', 0, PARAM_BOOL)) {
        $redirectparams['rpending'] = 1;
    }
    redirect(new moodle_url('/mod/googlemeet/view.php', $redirectparams));
}

// Make sure URL exists before generating output - some older sites may contain empty urls
// Do not use PARAM_URL here, it is too strict and does not support general URIs!
// An invalid stored URL must not make the whole activity (recordings included) unusable:
// hide the join CTA and tell only the people who can fix it.
$url = trim((string)$googlemeet->url);
$pattern = "/^https:\/\/meet.google.com\/[-a-zA-Z0-9@:%._\+~#=]{3}-[-a-zA-Z0-9@:%._\+~#=]{4}-[-a-zA-Z0-9@:%._\+~#=]{3}$/";
$hasinvalidmeeturl = ($url !== '' && !preg_match($pattern, $url));
$hasvalidmeeturl = ($url !== '' && !$hasinvalidmeeturl);
unset($url);

// Resolve the requested recording before any output so a stale or trashed link
// can redirect back to the activity instead of showing a raw error.
$recording = null;
if ($recordingid > 0) {
    $recording = googlemeet_get_accessible_recording($googlemeet, $context, $recordingid);
    if (!$recording) {
        redirect(new moodle_url('/mod/googlemeet/view.php', ['id' => $cm->id]),
            get_string('recordingnotfound', 'googlemeet'), null, \core\output\notification::NOTIFY_WARNING);
    }
}

// Completion and trigger events.
googlemeet_view($googlemeet, $course, $cm, $context);

if ($recording) {
    // Recording pages: the activity description (often a raw Meet link) belongs to the
    // activity landing page, not to every lesson. The lesson hub shows its own title.
    $PAGE->activityheader->set_description('');
    $PAGE->add_body_class('googlemeet-hub-page');
}

googlemeet_print_header($googlemeet, $cm, $course);

if ($hasinvalidmeeturl && has_capability('moodle/course:manageactivities', $context)) {
    echo $OUTPUT->notification(get_string('invalidstoredurl_editor', 'googlemeet',
        (new moodle_url('/course/modedit.php', ['update' => $cm->id, 'return' => 1]))->out()),
        \core\output\notification::NOTIFY_WARNING);
}
// Note: In Moodle 4.0+, the activity header automatically displays
// the title and description, so we don't call googlemeet_print_heading
// or googlemeet_print_intro to avoid duplication.

if ($recording) {
    googlemeet_print_recording_hub($googlemeet, $cm, $context, $recording);
    echo $OUTPUT->footer();
    exit;
}

$maxevents = $googlemeet->maxupcomingevents ?? 3;
$upcomingeventscontext = googlemeet_get_upcoming_events($googlemeet->id, $maxevents);
$scheduleeventscontext = googlemeet_get_upcoming_events($googlemeet->id, 0, true);
$herocontext = googlemeet_get_classroom_hero_context(
    $googlemeet,
    $cm,
    $context,
    $upcomingeventscontext,
    $hasvalidmeeturl,
    $scheduleeventscontext
);
// NOT-04: "Add to my calendar" (.ics export + Google Calendar link) in the live-class block.
$herocontext = array_merge($herocontext,
    \mod_googlemeet\local\calendar_export::hero_context($googlemeet, (int)$cm->id, time()));
echo $OUTPUT->render_from_template('mod_googlemeet/hero', $herocontext);

if (has_capability('mod/googlemeet:editrecording', $context)) {
    if ($googlemeet->eventid != null) {
        echo html_writer::link('https://calendar.google.com/calendar/u/0/r/eventedit/'.$googlemeet->eventid,
            get_string('eventdetails', 'googlemeet'),
            ['class' => 'btn btn-outline-primary ms-2', 'target' => '_blank', 'title' => get_string('eventdetails', 'googlemeet')]);
    }
}

// Teacher attachments: files the teacher provided for students to download.
googlemeet_print_attachments($context);

// Get pagination and order parameters.
$recordingspage = optional_param('rpage', 0, PARAM_INT);
$recordingsorder = optional_param('rorder', null, PARAM_ALPHA);
$recordingquery = optional_param('rq', '', PARAM_TEXT);
$recordingtopic = optional_param('topic', '', PARAM_TEXT);
$recordingpending = optional_param('rpending', 0, PARAM_BOOL);

googlemeet_print_recordings($googlemeet, $cm, $context, $recordingspage, $recordingsorder, $recordingquery, $recordingtopic,
    (bool)$recordingpending);

echo $OUTPUT->footer();
