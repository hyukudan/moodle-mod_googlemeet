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
 * Serve recording material files.
 *
 * @package  mod_googlemeet
 * @category files
 * @param stdClass $course Course object.
 * @param stdClass $cm Course module object.
 * @param context $context Context object.
 * @param string $filearea File area.
 * @param array $args Extra path arguments.
 * @param bool $forcedownload Whether to force download.
 * @param array $options Additional file serving options.
 * @return bool False if file is not found or access is denied.
 */
function googlemeet_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $DB;

    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }

    require_login($course, true, $cm);
    if (!has_capability('mod/googlemeet:view', $context)) {
        return false;
    }

    // Teacher attachments (files for students to download). Itemid is always 0.
    if ($filearea === 'attachment') {
        $fs = get_file_storage();
        $relativepath = implode('/', $args);
        $fullpath = "/$context->id/mod_googlemeet/attachment/$relativepath";
        if (!$file = $fs->get_file_by_hash(sha1($fullpath)) or $file->is_directory()) {
            return false;
        }
        send_stored_file($file, 86400, 0, $forcedownload, $options);
        return true;
    }

    if ($filearea !== 'recordingmaterial') {
        return false;
    }

    $recordingid = (int)array_shift($args);
    if ($recordingid <= 0) {
        return false;
    }

    $recording = $DB->get_record('googlemeet_recordings',
        ['id' => $recordingid, 'googlemeetid' => $cm->instance], 'id, visible');
    if (!$recording) {
        return false;
    }

    if (empty($recording->visible) && !has_capability('mod/googlemeet:editrecording', $context)) {
        return false;
    }

    $fs = get_file_storage();
    $relativepath = implode('/', $args);
    $fullpath = "/$context->id/mod_googlemeet/recordingmaterial/$recordingid/$relativepath";
    if (!$file = $fs->get_file_by_hash(sha1($fullpath)) or $file->is_directory()) {
        return false;
    }

    send_stored_file($file, 0, 0, $forcedownload, $options);
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

    // originalname is NOT NULL. Only the logged-in branch above sets it (from the calendar
    // event); when creating an activity without a Google login it would be unset and the
    // insert would fail on strict DBs. Default it to the activity name.
    if (empty($googlemeet->originalname)) {
        $googlemeet->originalname = $googlemeet->name;
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

    // Save teacher attachments (files for students to download).
    googlemeet_save_attachments($googlemeet);

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

    // Incremental merge (instead of wipe-and-recreate) so that persisting dates keep their
    // `autosynced` timestamp and their notify_done rows. This prevents re-sending notifications
    // and re-running autosync for sessions that were already processed before this save.
    googlemeet_merge_events($googlemeet, $events);

    // Save teacher attachments (files for students to download).
    googlemeet_save_attachments($googlemeet);

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

    if (!$DB->record_exists('googlemeet', ['id' => $id])) {
        return false;
    }

    googlemeet_delete_events($id);

    // Delete AI analyses for all recordings of this instance.
    $recordingids = $DB->get_fieldset_select('googlemeet_recordings', 'id', 'googlemeetid = ?', [$id]);
    if (!empty($recordingids)) {
        list($insql, $inparams) = $DB->get_in_or_equal($recordingids);
        $DB->delete_records_select('googlemeet_ai_analysis', "recordingid $insql", $inparams);
    }

    $DB->delete_records('googlemeet_recordings', ['googlemeetid' => $id]);
    $DB->delete_records('googlemeet_recording_subs', ['googlemeetid' => $id]);
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
        $sql = "SELECT recordingid, summary, keypoints, topics, status, error, aimodel, timemodified, retrycount, nextretry
                FROM {googlemeet_ai_analysis}
                WHERE recordingid $insql";
        $airecords = $DB->get_records_sql($sql, $inparams);
        foreach ($airecords as $ai) {
            $aidata[$ai->recordingid] = $ai;
        }
    }

    $formattedrecordings = [];
    foreach ($recordings as $recording) {
        $recording->createdtimeformatted = userdate($recording->createdtime);
        // Compact card date chip: lowercase + no trailing dot, so locale abbreviations
        // like "Mié. 27 May." read as "mié 27 may" in the small chip. This normalization
        // is ONLY applied to the chip field, never to full timestamps (createdtimeformatted).
        $recording->createddateshort = googlemeet_format_date_chip(
            userdate($recording->createdtime, get_string('strftimedm', 'googlemeet'))
        );
        $recording->isnew = googlemeet_recording_is_new((int)$recording->createdtime, time(), 7);
        $recording->dategroup = googlemeet_recording_date_group((int)$recording->createdtime);

        $ai = $aidata[$recording->id] ?? null;
        $status = $ai->status ?? null;
        $flags = googlemeet_ai_status_flags($status);
        foreach ($flags as $key => $value) {
            $recording->$key = $value;
        }

        // hasai keeps its existing meaning: a COMPLETED analysis with content.
        $hascontent = $ai && $status === 'completed'
            && (trim((string)$ai->summary) !== '' || ($ai->keypoints ?? '') !== '' || ($ai->topics ?? '') !== '');
        if ($hascontent) {
            $recording->hasai = true;
            $recording->aisummary = $ai->summary;
            $recording->aisummarypreview = googlemeet_truncate_summary((string)$ai->summary, 200);
            $recording->aikeypoints = json_decode($ai->keypoints) ?: [];
            $recording->aikeypointscount = count($recording->aikeypoints);
            $recording->aikeypointslabel = googlemeet_keypoints_label($recording->aikeypointscount);
            $recording->aitopics = json_decode($ai->topics) ?: [];
            $tp = googlemeet_topic_preview($recording->aitopics, 2);
            $recording->aitopicsvisible = $tp['visible'];
            $recording->aitopicsoverflow = $tp['overflow'];
            $recording->aimodel = $ai->aimodel;
            $recording->aidate = userdate($ai->timemodified, get_string('strftimedmy', 'googlemeet'));
        } else {
            $recording->hasai = false;
            $recording->aisummary = '';
            $recording->aisummarypreview = '';
            $recording->aikeypoints = [];
            $recording->aikeypointscount = 0;
            $recording->aikeypointslabel = '';
            $recording->aitopics = [];
            $recording->aitopicsvisible = [];
            $recording->aitopicsoverflow = 0;
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
 * Multibyte-safe truncation for AI summary previews.
 *
 * @param string $text The full summary text.
 * @param int $length Maximum length in characters.
 * @return string Truncated text with an ellipsis if it was cut.
 */
function googlemeet_truncate_summary(string $text, int $length = 200): string {
    if (core_text::strlen($text) <= $length) {
        return $text;
    }
    return core_text::substr($text, 0, $length) . '…';
}

/**
 * Build a localized, correctly pluralized "N key points" label for a recording card.
 *
 * @param int $count Number of key points.
 * @return string Localized label, e.g. "1 punto clave" / "3 puntos clave".
 */
function googlemeet_keypoints_label(int $count): string {
    $key = $count === 1 ? 'ai_keypoints_count' : 'ai_keypoints_count_plural';
    return get_string($key, 'googlemeet', $count);
}

/**
 * Normalize a compact card date chip: lowercase and strip trailing dots.
 *
 * Locale month/weekday abbreviations (e.g. "Mié. 27 May.") read better as "mié 27 may"
 * inside the small card chip. Applied ONLY to the chip field, never to full timestamps.
 *
 * @param string $date Formatted short date string.
 * @return string Lowercased, trailing-dot-trimmed date string.
 */
function googlemeet_format_date_chip(string $date): string {
    $date = core_text::strtolower($date);
    // Remove any trailing dot left by abbreviations like "may." and tidy whitespace.
    $date = trim($date);
    $date = rtrim($date, '.');
    return $date;
}

/**
 * Build template booleans for an AI analysis status.
 *
 * @param string|null $status One of pending|processing|completed|failed, or null when no row exists.
 * @return array Associative array of status flags.
 */
function googlemeet_ai_status_flags(?string $status): array {
    $valid = ['pending', 'processing', 'completed', 'failed'];
    $status = in_array($status, $valid, true) ? $status : null;
    return [
        'aistatus' => $status ?? '',
        'hasanalysisrow' => $status !== null,
        'aistatusiscompleted' => $status === 'completed',
        'aistatusisprocessing' => $status === 'processing',
        'aistatusispending' => $status === 'pending',
        'aistatusisfailed' => $status === 'failed',
    ];
}

/**
 * Whether a recording counts as "new" relative to a reference time.
 *
 * @param int $createdtime Recording creation time.
 * @param int $now Reference "now" timestamp.
 * @param int $thresholddays Age threshold in days.
 * @return bool
 */
function googlemeet_recording_is_new(int $createdtime, int $now, int $thresholddays = 7): bool {
    return ($now - $createdtime) <= ($thresholddays * DAYSECS);
}

/**
 * Localised month/year heading for grouping recordings, e.g. "mayo 2026".
 *
 * @param int $timestamp Recording creation time.
 * @return string
 */
function googlemeet_recording_date_group(int $timestamp): string {
    return userdate($timestamp, get_string('strftimemonthyear', 'langconfig'));
}

/**
 * Filter recordings by a free-text query against name (and AI summary/topics when completed).
 *
 * @param array $recordings Recording stdClass list (post AI-enrichment).
 * @param string $query Raw search query.
 * @return array Filtered recordings, re-indexed.
 */
function googlemeet_filter_recordings_by_query(array $recordings, string $query): array {
    $needle = core_text::strtolower(trim($query));
    if ($needle === '') {
        return $recordings;
    }
    return array_values(array_filter($recordings, static function($r) use ($needle) {
        $haystacks = [(string)($r->name ?? '')];
        if (!empty($r->hasai)) {
            $haystacks[] = (string)($r->aisummary ?? '');
            foreach (($r->aitopics ?? []) as $t) {
                $haystacks[] = (string)$t;
            }
        }
        foreach ($haystacks as $h) {
            if (core_text::strpos(core_text::strtolower($h), $needle) !== false) {
                return true;
            }
        }
        return false;
    }));
}

/**
 * Filter recordings to those tagged with an exact AI topic.
 *
 * @param array $recordings Recording stdClass list.
 * @param string $topic Exact topic text.
 * @return array Filtered recordings, re-indexed.
 */
function googlemeet_filter_recordings_by_topic(array $recordings, string $topic): array {
    $topic = trim($topic);
    if ($topic === '') {
        return $recordings;
    }
    return array_values(array_filter($recordings, static function($r) use ($topic) {
        foreach (($r->aitopics ?? []) as $t) {
            if ((string)$t === $topic) {
                return true;
            }
        }
        return false;
    }));
}

/**
 * Distinct, naturally-sorted list of AI topics across recordings.
 *
 * @param array $recordings Recording stdClass list.
 * @return string[]
 */
function googlemeet_collect_topics(array $recordings): array {
    $seen = [];
    foreach ($recordings as $r) {
        foreach (($r->aitopics ?? []) as $t) {
            $t = (string)$t;
            if ($t !== '') {
                $seen[$t] = true;
            }
        }
    }
    $topics = array_keys($seen);
    sort($topics, SORT_NATURAL | SORT_FLAG_CASE);
    return $topics;
}

/**
 * Split a topic list into a small visible set plus an overflow count for card previews.
 *
 * @param array $topics Full topic list.
 * @param int $max Maximum visible topics.
 * @return array ['visible' => string[], 'overflow' => int]
 */
function googlemeet_topic_preview(array $topics, int $max = 3): array {
    $visible = array_slice(array_values($topics), 0, $max);
    return ['visible' => $visible, 'overflow' => max(0, count($topics) - count($visible))];
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

    // Build lookup maps for O(1) access instead of in_array() O(n).
    $recordingsbyid = [];
    foreach ($googlemeetrecordings as $rec) {
        $recordingsbyid[$rec->recordingid] = $rec;
    }

    $fileidsmap = [];
    $filesbyid = [];
    foreach ($files as $file) {
        if (isset($file->recordingId)) {
            $fileidsmap[$file->recordingId] = true;
            $filesbyid[$file->recordingId] = $file;
        }
    }

    // Existing recordings are never touched on re-sync: Drive metadata (createdtime, duration,
    // webviewlink) is immutable once the recording is finalised, and the transcript is captured
    // at insert time. A manual sync via the UI is the path for re-fetching missing data.
    $insertrecordings = [];
    $deleterecordings = [];

    foreach ($files as $file) {
        if (!isset($file->unprocessed) && !isset($recordingsbyid[$file->recordingId])) {
            $insertrecordings[] = $file;
        }
    }

    $stats = [
        'inserted' => 0,
        'updated' => 0,
        'deleted' => 0,
    ];

    $updatednotes = 0;
    foreach ($googlemeetrecordings as $googlemeetrecording) {
        // O(1) lookup with isset() instead of O(n) in_array().
        if (!isset($fileidsmap[$googlemeetrecording->recordingid])) {
            // Accumulate every orphaned recording id, not just the last one.
            $deleterecordings[] = $googlemeetrecording->id;
            continue;
        }

        // Backfill notes for an existing recording that just received them (Gemini
        // notes are often published after the recording first synced). This is the
        // only field updated on existing rows; all Drive metadata stays immutable.
        if (empty($googlemeetrecording->notestext)
                && !empty($fileidsmap[$googlemeetrecording->recordingid])) {
            $incoming = $filesbyid[$googlemeetrecording->recordingid] ?? null;
            if ($incoming && !empty($incoming->notestext)) {
                $DB->update_record('googlemeet_recordings', (object)[
                    'id' => $googlemeetrecording->id,
                    'notestext' => $incoming->notestext,
                    'notesdocid' => $incoming->notesdocid ?? null,
                    'timemodified' => time(),
                ]);
                $updatednotes++;
            }
        }
    }
    $stats['updated'] = $updatednotes;

    if ($deleterecordings) {
        list($insql, $inparams) = $DB->get_in_or_equal($deleterecordings);
        // Also delete associated AI analysis to avoid orphaned data.
        $DB->delete_records_select('googlemeet_ai_analysis', "recordingid $insql", $inparams);
        $DB->delete_records_select('googlemeet_recordings', "id $insql", $inparams);
        $stats['deleted'] = count($deleterecordings);
    }

    if ($insertrecordings) {
        $newrecordingids = [];
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

            // Add notes if available.
            if (!empty($insertrecording->notestext)) {
                $recording->notestext = $insertrecording->notestext;
                $recording->notesdocid = $insertrecording->notesdocid ?? null;
            }

            $newrecordingids[] = $DB->insert_record('googlemeet_recordings', $recording);
        }
        $stats['inserted'] = count($insertrecordings);

        $aiautogenerate = !empty(get_config('googlemeet', 'ai_autogenerate'));
        if ($aiautogenerate) {
            $aiservice = new \mod_googlemeet\ai_service();
            if ($aiservice->is_available()) {
                foreach ($newrecordingids as $recordingid) {
                    $aiservice->queue_for_analysis($recordingid);
                }
            }
        }

        if ($DB->record_exists('googlemeet_recording_subs', ['googlemeetid' => $googlemeetid])) {
            $task = new \mod_googlemeet\task\notify_new_recordings();
            $task->set_custom_data([
                'googlemeetid' => $googlemeetid,
                'newcount' => $stats['inserted'],
            ]);
            \core\task\manager::queue_adhoc_task($task);
        }
    }

    // Always update lastsync timestamp (single query instead of 3 redundant queries).
    $DB->set_field('googlemeet', 'lastsync', time(), ['id' => $googlemeetid]);

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
    // Get start of day for the timestamp using the user's timezone (not the server's)
    // so the day comparison is consistent for users/courses in another timezone.
    $datestart = usergetmidnight($timestamp);

    foreach ($holidays as $holiday) {
        $holidaystart = usergetmidnight($holiday->startdate);
        $holidayend = usergetmidnight($holiday->enddate);

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
    // Get start of day for the timestamp using the user's timezone (not the server's)
    // so the day comparison is consistent for users/courses in another timezone.
    $datestart = usergetmidnight($timestamp);

    foreach ($cancelleddates as $cancelled) {
        $cancelledstart = usergetmidnight($cancelled->cancelleddate);

        if ($datestart === $cancelledstart) {
            return $cancelled;
        }
    }

    return false;
}
