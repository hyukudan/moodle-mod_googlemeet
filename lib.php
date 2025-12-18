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
 * Library of interface functions and constants.
 *
 * @package     mod_googlemeet
 * @copyright   2020 Rone Santos <ronefel@hotmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_googlemeet\client;

/**
 * Return if the plugin supports $feature.
 *
 * @param string $feature Constant representing the feature.
 * @return true | null True if the feature is supported, null otherwise.
 */
function googlemeet_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_ARCHETYPE:
            return MOD_ARCHETYPE_RESOURCE;
        case FEATURE_GROUPS:
            return false;
        case FEATURE_GROUPINGS:
            return false;
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return true;
        case FEATURE_GRADE_HAS_GRADE:
            return false;
        case FEATURE_GRADE_OUTCOMES:
            return false;
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        default:
            return null;
    }
}

/**
 * Saves a new instance of the mod_googlemeet into the database.
 *
 * Given an object containing all the necessary data, (defined by the form
 * in mod_form.php) this function will create a new instance and return the id
 * number of the instance.
 *
 * @param object $googlemeet An object from the form.
 * @param mod_googlemeet_mod_form $mform The form.
 * @return int The id of the newly inserted record.
 */
function googlemeet_add_instance($googlemeet, $mform = null) {
    global $DB, $CFG;
    require_once($CFG->dirroot . '/mod/googlemeet/locallib.php');

    $client = new client();

    // Se não esta logado na conta do Google.
    if (!$client->check_login()) {
        $url = googlemeet_clear_url($googlemeet->url);
        if ($url) {
            $googlemeet->url = $url;
        }
    } else {
        $calendarevent = $client->create_meeting_event($googlemeet);
        $googlemeet->url = $calendarevent->hangoutLink;

        $link = new moodle_url($calendarevent->htmlLink);
        $googlemeet->eventid = $link->get_param('eid');
        $googlemeet->originalname = $calendarevent->summary;
        $googlemeet->creatoremail = $calendarevent->creator->email;
    }

    if (isset($googlemeet->days)) {
        $googlemeet->days = json_encode($googlemeet->days);
    }

    $googlemeet->timemodified = time();

    if (!$googlemeet->id = $DB->insert_record('googlemeet', $googlemeet)) {
        return false;
    }

    if (isset($googlemeet->days)) {
        $googlemeet->days = json_decode($googlemeet->days, true);
    }

    // Save holiday periods.
    googlemeet_save_holidays($googlemeet);

    // Save cancelled dates.
    googlemeet_save_cancelled($googlemeet);

    $events = googlemeet_construct_events_data_for_add($googlemeet);

    googlemeet_set_events($googlemeet, $events);

    return $googlemeet->id;
}

/**
 * Updates an instance of the mod_googlemeet in the database.
 *
 * Given an object containing all the necessary data (defined in mod_form.php),
 * this function will update an existing instance with new data.
 *
 * @param object $googlemeet An object from the form in mod_form.php.
 * @param mod_googlemeet_mod_form $mform The form.
 * @return bool True if successful, false otherwise.
 */
function googlemeet_update_instance($googlemeet, $mform = null) {
    global $DB, $CFG;
    require_once($CFG->dirroot . '/mod/googlemeet/locallib.php');

    $googlemeet->id = $googlemeet->instance;

    if (isset($googlemeet->addmultiply)) {
        if (isset($googlemeet->days)) {
            $googlemeet->days = json_encode($googlemeet->days);
        }
    } else {
        $googlemeet->addmultiply = 0;
        $googlemeet->days = null;
        $googlemeet->eventenddate = $googlemeet->eventdate;
        $googlemeet->period = null;
    }

    if (isset($googlemeet->url)) {
        $url = googlemeet_clear_url($googlemeet->url);
        if ($url) {
            $googlemeet->url = $url;
        }
    }

    $googlemeet->timemodified = time();

    $googlemeetupdated = $DB->update_record('googlemeet', $googlemeet);

    // Save holiday periods.
    googlemeet_save_holidays($googlemeet);

    // Save cancelled dates.
    googlemeet_save_cancelled($googlemeet);

    if (isset($googlemeet->days)) {
        $googlemeet->days = json_decode($googlemeet->days);
    }
    $events = googlemeet_construct_events_data_for_add($googlemeet);

    googlemeet_set_events($googlemeet, $events);

    return $googlemeetupdated;
}

/**
 * Removes an instance of the mod_googlemeet from the database.
 *
 * @param int $id Id of the module instance.
 * @return bool True if successful, false on failure.
 */
function googlemeet_delete_instance($id) {
    global $DB, $CFG;
    require_once($CFG->dirroot . '/mod/googlemeet/locallib.php');

    $exists = $DB->get_record('googlemeet', array('id' => $id));
    if (!$exists) {
        return false;
    }

    googlemeet_delete_events($id);

    $DB->delete_records('googlemeet_recordings', ['googlemeetid' => $id]);
    $DB->delete_records('googlemeet_holidays', ['googlemeetid' => $id]);
    $DB->delete_records('googlemeet_cancelled', ['googlemeetid' => $id]);

    $DB->delete_records('googlemeet', ['id' => $id]);

    return true;
}

/**
 * Add a get_coursemodule_info function in case any feedback type wants to add 'extra' information
 * for the course (see resource).
 *
 * Given a course_module object, this function returns any "extra" information that may be needed
 * when printing this activity in a course listing.  See get_array_of_activities() in course/lib.php.
 *
 * @param stdClass $coursemodule The coursemodule object (record).
 * @return cached_cm_info An object on information that the courses
 *                        will know about (most noticeably, an icon).
 */
function googlemeet_get_coursemodule_info($coursemodule) {
    global $CFG, $DB;

    if (!$googlemeet = $DB->get_record(
        'googlemeet',
        ['id' => $coursemodule->instance],
        'id, name, url, intro, introformat'
    )) {
        return null;
    }

    $info = new cached_cm_info();
    $info->name = $googlemeet->name;

    if ($coursemodule->showdescription) {
        // Convert intro to html. Do not filter cached version, filters run at display time.
        $info->content = format_module_intro('googlemeet', $googlemeet, $coursemodule->id, false);
    }

    return $info;
}

/**
 * Mark the activity completed (if required) and trigger the course_module_viewed event.
 *
 * @param  stdClass $googlemeet googlemeet object
 * @param  stdClass $course     course object
 * @param  stdClass $cm         course module object
 * @param  stdClass $context    context object
 * @since Moodle 3.0
 */
function googlemeet_view($googlemeet, $course, $cm, $context) {

    // Trigger course_module_viewed event.
    $params = array(
        'context' => $context,
        'objectid' => $googlemeet->id
    );

    $event = \mod_googlemeet\event\course_module_viewed::create($params);
    $event->add_record_snapshot('course_modules', $cm);
    $event->add_record_snapshot('course', $course);
    $event->add_record_snapshot('googlemeet', $googlemeet);
    $event->trigger();

    // Completion.
    $completion = new completion_info($course);
    $completion->set_module_viewed($cm);
}

/**
 * Returns a list of recordings from Google Meet
 *
 * @param array $params Array of parameters to a query.
 * @param bool $includeai Include AI analysis data.
 * @param string $order Sort order: 'DESC' for newest first, 'ASC' for oldest first.
 * @param int $limit Maximum number of recordings to return (0 for all).
 * @param int $offset Number of recordings to skip.
 * @return stdClass $formattedrecordings    List of recordings
 */
function googlemeet_list_recordings($params, $includeai = false, $order = 'DESC', $limit = 0, $offset = 0) {
    global $DB;

    // Validate order parameter.
    $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

    $recordings = $DB->get_records(
        'googlemeet_recordings',
        $params,
        'createdtime ' . $order,
        'id,googlemeetid,name,createdtime,duration,webviewlink,visible',
        $offset,
        $limit
    );

    // Get all recording IDs for batch AI query.
    $recordingids = array_keys($recordings);

    // Fetch AI analysis data if enabled and there are recordings.
    $aidata = [];
    if ($includeai && !empty($recordingids)) {
        list($insql, $inparams) = $DB->get_in_or_equal($recordingids, SQL_PARAMS_NAMED);
        $sql = "SELECT recordingid, summary, keypoints, topics, status, aimodel, timemodified
                FROM {googlemeet_ai_analysis}
                WHERE recordingid $insql AND status = 'completed'";
        $airecords = $DB->get_records_sql($sql, $inparams);
        foreach ($airecords as $ai) {
            $aidata[$ai->recordingid] = $ai;
        }
    }

    $formattedrecordings = [];
    foreach ($recordings as $recording) {
        $recording->createdtimeformatted = userdate($recording->createdtime);

        // Add AI data if available.
        if (isset($aidata[$recording->id])) {
            $ai = $aidata[$recording->id];
            $recording->hasai = true;
            $recording->aisummary = $ai->summary;
            // Truncate summary for preview (first 200 chars).
            $recording->aisummarypreview = strlen($ai->summary) > 200
                ? substr($ai->summary, 0, 200) . '...'
                : $ai->summary;
            $recording->aikeypoints = json_decode($ai->keypoints) ?: [];
            $recording->aikeypointscount = count($recording->aikeypoints);
            $recording->aitopics = json_decode($ai->topics) ?: [];
            $recording->aimodel = $ai->aimodel;
            $recording->aidate = userdate($ai->timemodified, get_string('strftimedmy', 'googlemeet'));
        } else {
            $recording->hasai = false;
            $recording->aisummary = '';
            $recording->aisummarypreview = '';
            $recording->aikeypoints = [];
            $recording->aikeypointscount = 0;
            $recording->aitopics = [];
            $recording->aimodel = '';
            $recording->aidate = '';
        }

        $formattedrecordings[] = $recording;
    }

    return $formattedrecordings;
}

/**
 * Counts the total number of recordings for a Google Meet instance.
 *
 * @param array $params Array of parameters to a query.
 * @return int Total number of recordings.
 */
function googlemeet_count_recordings($params) {
    global $DB;
    return $DB->count_records('googlemeet_recordings', $params);
}

/**
 * Get icon mapping for font-awesome.
 */
function mod_googlemeet_get_fontawesome_icon_map() {
    return [
        'mod_googlemeet:logout' => 'fa-sign-out',
        'mod_googlemeet:play' => 'fa-play'
    ];
}

/**
 * Synchronizes Google Drive recordings with the database.
 *
 * @param int $googlemeetid the googlemeet ID
 * @param array $files the array of recordings
 * @return array with 'recordings' list and 'stats' (inserted, updated, deleted counts)
 */
function sync_recordings($googlemeetid, $files) {
    global $DB;

    $cm = get_coursemodule_from_instance('googlemeet', $googlemeetid, 0, false, MUST_EXIST);
    $context = context_module::instance($cm->id);
    require_capability('mod/googlemeet:syncgoogledrive', $context);

    $googlemeetrecordings = $DB->get_records('googlemeet_recordings', ['googlemeetid' => $googlemeetid]);

    $recordingids = array_column($googlemeetrecordings, 'recordingid');
    $fileids = array_column($files, 'recordingId');

    $updaterecordings = [];
    $insertrecordings = [];
    $deleterecordings = [];

    foreach ($files as $file) {
        if (!isset($file->unprocessed)) {
            if (in_array($file->recordingId, $recordingids, true)) {
                $updaterecordings[] = $file;
            } else {
                $insertrecordings[] = $file;
            }
        }
    }

    foreach ($googlemeetrecordings as $googlemeetrecording) {
        if (!in_array($googlemeetrecording->recordingid, $fileids)) {
            $deleterecordings['id'] = $googlemeetrecording->id;
        }
    }

    $stats = [
        'inserted' => 0,
        'updated' => 0,
        'deleted' => 0,
    ];

    if ($deleterecordings) {
        // Also delete associated AI analysis to avoid orphaned data.
        $DB->delete_records('googlemeet_ai_analysis', ['recordingid' => $deleterecordings['id']]);
        $DB->delete_records('googlemeet_recordings', $deleterecordings);
        $stats['deleted'] = 1;
    }

    if ($updaterecordings) {
        foreach ($updaterecordings as $updaterecording) {
            $recording = $DB->get_record('googlemeet_recordings', [
                'googlemeetid' => $googlemeetid,
                'recordingid' => $updaterecording->recordingId
            ]);

            $recording->createdtime = $updaterecording->createdTime;
            $recording->duration = $updaterecording->duration;
            $recording->webviewlink = $updaterecording->webViewLink;
            $recording->timemodified = time();

            // Update transcript if available and not already set.
            if (!empty($updaterecording->transcripttext) && empty($recording->transcripttext)) {
                $recording->transcripttext = $updaterecording->transcripttext;
                $recording->transcriptfileid = $updaterecording->transcriptfileid ?? null;
            }

            $DB->update_record('googlemeet_recordings', $recording);
        }
        $stats['updated'] = count($updaterecordings);

        $googlemeetrecord = $DB->get_record('googlemeet', ['id' => $googlemeetid]);
        $googlemeetrecord->lastsync = time();
        $DB->update_record('googlemeet', $googlemeetrecord);
    }

    if ($insertrecordings) {
        foreach ($insertrecordings as $insertrecording) {
            $recording = new stdClass();
            $recording->googlemeetid = $googlemeetid;
            $recording->recordingid = $insertrecording->recordingId;
            $recording->name = $insertrecording->name;
            $recording->createdtime = $insertrecording->createdTime;
            $recording->duration = $insertrecording->duration;
            $recording->webviewlink = $insertrecording->webViewLink;
            $recording->timemodified = time();

            // Add transcript if available.
            if (!empty($insertrecording->transcripttext)) {
                $recording->transcripttext = $insertrecording->transcripttext;
                $recording->transcriptfileid = $insertrecording->transcriptfileid ?? null;
            }

            $DB->insert_record('googlemeet_recordings', $recording);
        }
        $stats['inserted'] = count($insertrecordings);

        $googlemeetrecord = $DB->get_record('googlemeet', ['id' => $googlemeetid]);
        $googlemeetrecord->lastsync = time();

        $DB->update_record('googlemeet', $googlemeetrecord);
    }

    // Always update lastsync even if no changes were made.
    if (!$updaterecordings && !$insertrecordings && !$deleterecordings) {
        $googlemeetrecord = $DB->get_record('googlemeet', ['id' => $googlemeetid]);
        $googlemeetrecord->lastsync = time();
        $DB->update_record('googlemeet', $googlemeetrecord);
    }

    return [
        'recordings' => googlemeet_list_recordings(['googlemeetid' => $googlemeetid]),
        'stats' => $stats,
    ];
}

/**
 * Save holiday/exclusion periods for a googlemeet instance.
 *
 * @param object $googlemeet The googlemeet instance data from the form.
 * @return void
 */
function googlemeet_save_holidays($googlemeet) {
    global $DB;

    // Delete all existing holiday periods for this instance.
    $DB->delete_records('googlemeet_holidays', ['googlemeetid' => $googlemeet->id]);

    // Only save holidays if recurrence is enabled.
    if (empty($googlemeet->addmultiply) || empty($googlemeet->holiday_repeats)) {
        return;
    }

    // Save new holiday periods.
    for ($i = 0; $i < $googlemeet->holiday_repeats; $i++) {
        $startkeyarr = "holidaystartdate";
        $endkeyarr = "holidayenddate";
        $namekeyarr = "holidayname";

        // Get data from the repeat elements (they come as arrays).
        $startdate = $googlemeet->{$startkeyarr}[$i] ?? null;
        $enddate = $googlemeet->{$endkeyarr}[$i] ?? null;
        $name = $googlemeet->{$namekeyarr}[$i] ?? '';

        // Only save if both dates are set and valid.
        if ($startdate && $enddate && $enddate >= $startdate) {
            $holiday = new stdClass();
            $holiday->googlemeetid = $googlemeet->id;
            $holiday->name = $name;
            $holiday->startdate = $startdate;
            $holiday->enddate = $enddate;
            $holiday->timemodified = time();

            $DB->insert_record('googlemeet_holidays', $holiday);
        }
    }
}

/**
 * Get holiday/exclusion periods for a googlemeet instance.
 *
 * @param int $googlemeetid The googlemeet instance ID.
 * @return array Array of holiday period objects.
 */
function googlemeet_get_holidays($googlemeetid) {
    global $DB;
    return $DB->get_records('googlemeet_holidays', ['googlemeetid' => $googlemeetid], 'startdate ASC');
}

/**
 * Check if a given date falls within any holiday period.
 *
 * @param int $timestamp The timestamp to check.
 * @param array $holidays Array of holiday period objects.
 * @return bool True if the date is within a holiday period.
 */
function googlemeet_is_holiday($timestamp, $holidays) {
    // Get start of day for the timestamp.
    $datestart = strtotime('midnight', $timestamp);

    foreach ($holidays as $holiday) {
        $holidaystart = strtotime('midnight', $holiday->startdate);
        $holidayend = strtotime('midnight', $holiday->enddate);

        if ($datestart >= $holidaystart && $datestart <= $holidayend) {
            return true;
        }
    }

    return false;
}

/**
 * Save cancelled dates for a googlemeet instance.
 *
 * @param object $googlemeet The googlemeet instance data from the form.
 * @return void
 */
function googlemeet_save_cancelled($googlemeet) {
    global $DB;

    // Delete all existing cancelled dates for this instance.
    $DB->delete_records('googlemeet_cancelled', ['googlemeetid' => $googlemeet->id]);

    // Only save cancelled dates if recurrence is enabled.
    if (empty($googlemeet->addmultiply) || empty($googlemeet->cancelled_repeats)) {
        return;
    }

    // Save new cancelled dates.
    for ($i = 0; $i < $googlemeet->cancelled_repeats; $i++) {
        $datekeyarr = "cancelleddate";
        $reasonkeyarr = "cancelledreason";

        // Get data from the repeat elements (they come as arrays).
        $cancelleddate = $googlemeet->{$datekeyarr}[$i] ?? null;
        $reason = $googlemeet->{$reasonkeyarr}[$i] ?? '';

        // Only save if date is set.
        if ($cancelleddate) {
            $cancelled = new stdClass();
            $cancelled->googlemeetid = $googlemeet->id;
            $cancelled->cancelleddate = $cancelleddate;
            $cancelled->reason = $reason;
            $cancelled->timemodified = time();

            $DB->insert_record('googlemeet_cancelled', $cancelled);
        }
    }
}

/**
 * Get cancelled dates for a googlemeet instance.
 *
 * @param int $googlemeetid The googlemeet instance ID.
 * @return array Array of cancelled date objects.
 */
function googlemeet_get_cancelled($googlemeetid) {
    global $DB;
    return $DB->get_records('googlemeet_cancelled', ['googlemeetid' => $googlemeetid], 'cancelleddate ASC');
}

/**
 * Check if a given date is in the cancelled list.
 *
 * @param int $timestamp The timestamp to check.
 * @param array $cancelleddates Array of cancelled date objects.
 * @return object|false The cancelled date object if found, false otherwise.
 */
function googlemeet_is_cancelled($timestamp, $cancelleddates) {
    // Get start of day for the timestamp.
    $datestart = strtotime('midnight', $timestamp);

    foreach ($cancelleddates as $cancelled) {
        $cancelledstart = strtotime('midnight', $cancelled->cancelleddate);

        if ($datestart === $cancelledstart) {
            return $cancelled;
        }
    }

    return false;
}
