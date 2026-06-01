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
 * Google Meet external API
 *
 * @package     mod_googlemeet
 * @category    external
 * @copyright   2020 Rone Santos <ronefel@hotmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Support both Moodle 4.2+ (namespaced) and older versions.
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

require_once("$CFG->dirroot/mod/googlemeet/lib.php");

/**
 * Google Meet module external functions.
 *
 * @package     mod_googlemeet
 * @category    external
 * @copyright   2020 Rone Santos <ronefel@hotmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_googlemeet_external extends external_api {

    /**
     * Describes the parameters for recording_edit_name.
     *
     * @return external_function_parameters
     */
    public static function recording_edit_name_parameters() {
        return new external_function_parameters(
            [
                'recordingid' => new external_value(PARAM_INT, ''),
                'name' => new external_value(PARAM_TEXT, ''),
                'coursemoduleid' => new external_value(PARAM_INT, ''),
            ]
        );
    }

    /**
     * Edit the name of the recording
     *
     * @param int $recordingid the recording ID
     * @param string $name the new name of recording
     * @param int $coursemoduleid the course module ID
     * @return object containing the new name of the recording
     */
    public static function recording_edit_name($recordingid, $name, $coursemoduleid) {
        global $DB;

        // Parameter validation.
        // REQUIRED.
        $params = self::validate_parameters(
            self::recording_edit_name_parameters(),
            [
                'recordingid' => $recordingid,
                'name' => $name,
                'coursemoduleid' => $coursemoduleid
            ]
        );

        $cm = get_coursemodule_from_id('googlemeet', $coursemoduleid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/googlemeet:editrecording', $context);

        // Verify the recording belongs to this googlemeet instance (prevent IDOR).
        $recording = $DB->get_record('googlemeet_recordings',
            ['id' => $recordingid, 'googlemeetid' => $cm->instance], '*', MUST_EXIST);

        $recording->name = $name;
        $recording->timemodified = time();

        $DB->update_record('googlemeet_recordings', $recording);

        return (object)[
            'name' => $recording->name
        ];
    }

    /**
     * Describes the recording_edit_name return value.
     *
     * @return external_single_structure
     */
    public static function recording_edit_name_returns() {
        return new external_single_structure(
            [
                'name' => new external_value(PARAM_RAW, 'New recording name'),
            ]
        );
    }

    /**
     * Describes the parameters for showhide_recording.
     *
     * @return external_function_parameters
     */
    public static function showhide_recording_parameters() {
        return new external_function_parameters(
            [
                'recordingid' => new external_value(PARAM_INT, ''),
                'coursemoduleid' => new external_value(PARAM_INT, ''),
            ]
        );
    }

    /**
     * Toggle recording visibility.
     *
     * @param int $recordingid the recording ID
     * @param int $coursemoduleid the course module ID
     * @return object containing the visibility of the recording
     */
    public static function showhide_recording($recordingid, $coursemoduleid) {
        global $DB;

        // Parameter validation.
        // REQUIRED.
        $params = self::validate_parameters(
            self::showhide_recording_parameters(),
            [
                'recordingid' => $recordingid,
                'coursemoduleid' => $coursemoduleid
            ]
        );

        $cm = get_coursemodule_from_id('googlemeet', $coursemoduleid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/googlemeet:editrecording', $context);

        // Verify the recording belongs to this googlemeet instance (prevent IDOR).
        $recording = $DB->get_record('googlemeet_recordings',
            ['id' => $recordingid, 'googlemeetid' => $cm->instance], '*', MUST_EXIST);

        if ($recording->visible) {
            $recording->visible = false;
        } else {
            $recording->visible = true;
        }

        $recording->timemodified = time();

        $DB->update_record('googlemeet_recordings', $recording);

        return (object)[
            'visible' => $recording->visible
        ];
    }

    /**
     * Describes the showhide_recording return value.
     *
     * @return external_single_structure
     */
    public static function showhide_recording_returns() {
        return new external_single_structure(
            [
                'visible' => new external_value(PARAM_RAW, 'Visible or hidden recording'),
            ]
        );
    }

    /**
     * Describes the parameters for delete_all_recordings.
     *
     * @return external_function_parameters
     */
    public static function delete_all_recordings_parameters() {
        return new external_function_parameters(
            [
                'googlemeetid' => new external_value(PARAM_INT, ''),
                'coursemoduleid' => new external_value(PARAM_INT, ''),
            ]
        );
    }

    /**
     * Removes all recordings from Google Meet.
     *
     * @param int $googlemeetid the googlemeet ID
     * @param int $coursemoduleid the course module ID
     * @return array empty
     */
    public static function delete_all_recordings($googlemeetid, $coursemoduleid) {
        global $DB;

        // Parameter validation.
        // REQUIRED.
        $params = self::validate_parameters(
            self::delete_all_recordings_parameters(),
            [
                'googlemeetid' => $googlemeetid,
                'coursemoduleid' => $coursemoduleid
            ]
        );

        $cm = get_coursemodule_from_id('googlemeet', $coursemoduleid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/googlemeet:removerecording', $context);

        // Always operate on the instance bound to the validated course module (prevent IDOR).
        $googlemeetid = $cm->instance;

        // Get recording IDs to delete associated AI analyses.
        $recordingids = $DB->get_fieldset_select('googlemeet_recordings', 'id', 'googlemeetid = ?', [$googlemeetid]);
        if (!empty($recordingids)) {
            list($insql, $inparams) = $DB->get_in_or_equal($recordingids);
            $DB->delete_records_select('googlemeet_ai_analysis', "recordingid $insql", $inparams);
        }

        $DB->delete_records('googlemeet_recordings', ['googlemeetid' => $googlemeetid]);

        // Use set_field instead of get_record + update_record.
        $DB->set_field('googlemeet', 'lastsync', time(), ['id' => $googlemeetid]);

        return [];
    }

    /**
     * Describes the delete_all_recordings return value.
     *
     * @return external_single_structure
     */
    public static function delete_all_recordings_returns() {
        return new external_single_structure([]);
    }

    /**
     * Describes the parameters for generate_ai_analysis.
     *
     * @return external_function_parameters
     */
    public static function generate_ai_analysis_parameters() {
        return new external_function_parameters(
            [
                'recordingid' => new external_value(PARAM_INT, 'The recording ID'),
                'coursemoduleid' => new external_value(PARAM_INT, 'The course module ID'),
                'regenerate' => new external_value(PARAM_BOOL, 'Whether to regenerate existing analysis', VALUE_DEFAULT, false),
                'forcedownload' => new external_value(PARAM_BOOL, 'Allow tier 3: download full video for transcription', VALUE_DEFAULT, false),
            ]
        );
    }

    /**
     * Generate AI analysis for a recording.
     *
     * @param int $recordingid The recording ID
     * @param int $coursemoduleid The course module ID
     * @param bool $regenerate Whether to regenerate existing analysis
     * @param bool $forcedownload Whether to allow tier 3 (full video download)
     * @return array The analysis data
     */
    public static function generate_ai_analysis($recordingid, $coursemoduleid, $regenerate = false, $forcedownload = false) {
        global $DB;

        // Parameter validation.
        $params = self::validate_parameters(
            self::generate_ai_analysis_parameters(),
            [
                'recordingid' => $recordingid,
                'coursemoduleid' => $coursemoduleid,
                'regenerate' => $regenerate,
                'forcedownload' => $forcedownload,
            ]
        );

        $cm = get_coursemodule_from_id('googlemeet', $params['coursemoduleid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/googlemeet:generateai', $context);

        // Verify the recording belongs to this googlemeet instance before delegating (prevent IDOR).
        $DB->get_record('googlemeet_recordings',
            ['id' => $params['recordingid'], 'googlemeetid' => $cm->instance], 'id', MUST_EXIST);

        $aiservice = new \mod_googlemeet\ai_service();

        if (!$aiservice->is_available()) {
            throw new \moodle_exception('ai_not_configured', 'googlemeet');
        }

        try {
            $analysis = $aiservice->generate_analysis($params['recordingid'], $params['regenerate'], $params['forcedownload']);

            return [
                'id' => $analysis->id,
                'recordingid' => $analysis->recordingid,
                'summary' => $analysis->summary ?? '',
                'keypoints' => is_array($analysis->keypoints) ? $analysis->keypoints : [],
                'topics' => is_array($analysis->topics) ? $analysis->topics : [],
                'transcript' => $analysis->transcript ?? '',
                'language' => $analysis->language ?? 'es',
                'status' => $analysis->status,
                'error' => $analysis->error ?? '',
                'aimodel' => $analysis->aimodel ?? '',
                'timecreated' => $analysis->timecreated,
                'timemodified' => $analysis->timemodified,
            ];
        } catch (\Exception $e) {
            // Log the full error server-side, but return a generic message to avoid leaking
            // internal details such as API keys or endpoint URLs to the client.
            debugging('mod_googlemeet generate_ai_analysis failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            throw new \moodle_exception('ai_error_generic', 'googlemeet');
        }
    }

    /**
     * Describes the generate_ai_analysis return value.
     *
     * @return external_single_structure
     */
    public static function generate_ai_analysis_returns() {
        return new external_single_structure(
            [
                'id' => new external_value(PARAM_INT, 'Analysis ID'),
                'recordingid' => new external_value(PARAM_INT, 'Recording ID'),
                'summary' => new external_value(PARAM_RAW, 'AI-generated summary'),
                'keypoints' => new external_multiple_structure(
                    new external_value(PARAM_RAW, 'Key point'),
                    'List of key points'
                ),
                'topics' => new external_multiple_structure(
                    new external_value(PARAM_RAW, 'Topic'),
                    'List of topics'
                ),
                'transcript' => new external_value(PARAM_RAW, 'Transcript'),
                'language' => new external_value(PARAM_TEXT, 'Detected language'),
                'status' => new external_value(PARAM_TEXT, 'Processing status'),
                'error' => new external_value(PARAM_RAW, 'Error message if failed'),
                'aimodel' => new external_value(PARAM_TEXT, 'AI model used'),
                'timecreated' => new external_value(PARAM_INT, 'Time created'),
                'timemodified' => new external_value(PARAM_INT, 'Time modified'),
            ]
        );
    }

    /**
     * Describes the parameters for get_ai_analysis.
     *
     * @return external_function_parameters
     */
    public static function get_ai_analysis_parameters() {
        return new external_function_parameters(
            [
                'recordingid' => new external_value(PARAM_INT, 'The recording ID'),
                'coursemoduleid' => new external_value(PARAM_INT, 'The course module ID'),
            ]
        );
    }

    /**
     * Get AI analysis for a recording.
     *
     * @param int $recordingid The recording ID
     * @param int $coursemoduleid The course module ID
     * @return array The analysis data or empty if not found
     */
    public static function get_ai_analysis($recordingid, $coursemoduleid) {
        global $DB;

        // Parameter validation.
        $params = self::validate_parameters(
            self::get_ai_analysis_parameters(),
            [
                'recordingid' => $recordingid,
                'coursemoduleid' => $coursemoduleid,
            ]
        );

        $cm = get_coursemodule_from_id('googlemeet', $coursemoduleid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/googlemeet:view', $context);

        // The full transcript is only shown in the UI to users who can edit recordings. Mirror
        // that here so web service / mobile clients cannot read the transcript with mere view
        // capability (it can contain participant names/speech).
        $cantranscript = has_capability('mod/googlemeet:editrecording', $context);

        // Verify the recording belongs to this googlemeet instance before delegating (prevent IDOR).
        // Also pull the recording's own transcript so we can fall back to it when the analysis row
        // has no transcript stored (tier-1 analyses generated from an existing recording transcript
        // never copied it into the analysis row, leaving the UI stuck on "loading transcript").
        $recording = $DB->get_record('googlemeet_recordings',
            ['id' => $recordingid, 'googlemeetid' => $cm->instance], 'id, transcripttext', MUST_EXIST);

        $aiservice = new \mod_googlemeet\ai_service();
        $analysis = $aiservice->get_analysis($recordingid);

        if (!$analysis) {
            return [
                'found' => false,
                'id' => 0,
                'recordingid' => $recordingid,
                'summary' => '',
                'keypoints' => [],
                'topics' => [],
                'transcript' => '',
                'language' => '',
                'status' => '',
                'error' => '',
                'aimodel' => '',
                'timecreated' => 0,
                'timemodified' => 0,
            ];
        }

        // Prefer the analysis transcript; fall back to the recording's own transcript when empty.
        $transcript = $analysis->transcript ?? '';
        if (trim((string)$transcript) === '') {
            $transcript = $recording->transcripttext ?? '';
        }

        return [
            'found' => true,
            'id' => $analysis->id,
            'recordingid' => $analysis->recordingid,
            'summary' => $analysis->summary ?? '',
            'keypoints' => is_array($analysis->keypoints) ? $analysis->keypoints : [],
            'topics' => is_array($analysis->topics) ? $analysis->topics : [],
            'transcript' => $cantranscript ? $transcript : '',
            'language' => $analysis->language ?? 'es',
            'status' => $analysis->status,
            'error' => $analysis->error ?? '',
            'aimodel' => $analysis->aimodel ?? '',
            'timecreated' => $analysis->timecreated,
            'timemodified' => $analysis->timemodified,
        ];
    }

    /**
     * Describes the get_ai_analysis return value.
     *
     * @return external_single_structure
     */
    public static function get_ai_analysis_returns() {
        return new external_single_structure(
            [
                'found' => new external_value(PARAM_BOOL, 'Whether analysis was found'),
                'id' => new external_value(PARAM_INT, 'Analysis ID'),
                'recordingid' => new external_value(PARAM_INT, 'Recording ID'),
                'summary' => new external_value(PARAM_RAW, 'AI-generated summary'),
                'keypoints' => new external_multiple_structure(
                    new external_value(PARAM_RAW, 'Key point'),
                    'List of key points'
                ),
                'topics' => new external_multiple_structure(
                    new external_value(PARAM_RAW, 'Topic'),
                    'List of topics'
                ),
                'transcript' => new external_value(PARAM_RAW, 'Transcript'),
                'language' => new external_value(PARAM_TEXT, 'Detected language'),
                'status' => new external_value(PARAM_TEXT, 'Processing status'),
                'error' => new external_value(PARAM_RAW, 'Error message if failed'),
                'aimodel' => new external_value(PARAM_TEXT, 'AI model used'),
                'timecreated' => new external_value(PARAM_INT, 'Time created'),
                'timemodified' => new external_value(PARAM_INT, 'Time modified'),
            ]
        );
    }

    /**
     * Describes the parameters for save_ai_analysis.
     *
     * @return external_function_parameters
     */
    public static function save_ai_analysis_parameters() {
        return new external_function_parameters(
            [
                'recordingid' => new external_value(PARAM_INT, 'The recording ID'),
                'coursemoduleid' => new external_value(PARAM_INT, 'The course module ID'),
                'summary' => new external_value(PARAM_RAW, 'Summary text', VALUE_DEFAULT, ''),
                'keypoints' => new external_value(PARAM_RAW, 'Key points (one per line)', VALUE_DEFAULT, ''),
                'topics' => new external_value(PARAM_RAW, 'Topics (comma separated)', VALUE_DEFAULT, ''),
                'transcript' => new external_value(PARAM_RAW, 'Transcript text', VALUE_DEFAULT, ''),
            ]
        );
    }

    /**
     * Save AI analysis manually for a recording.
     *
     * @param int $recordingid The recording ID
     * @param int $coursemoduleid The course module ID
     * @param string $summary The summary text
     * @param string $keypoints Key points (one per line)
     * @param string $topics Topics (comma separated)
     * @param string $transcript Transcript text
     * @return array The saved analysis data
     */
    public static function save_ai_analysis($recordingid, $coursemoduleid, $summary = '', $keypoints = '', $topics = '', $transcript = '') {
        global $DB;

        // Parameter validation.
        $params = self::validate_parameters(
            self::save_ai_analysis_parameters(),
            [
                'recordingid' => $recordingid,
                'coursemoduleid' => $coursemoduleid,
                'summary' => $summary,
                'keypoints' => $keypoints,
                'topics' => $topics,
                'transcript' => $transcript,
            ]
        );

        $cm = get_coursemodule_from_id('googlemeet', $coursemoduleid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/googlemeet:editrecording', $context);

        // Verify the recording exists and belongs to this googlemeet instance (prevent IDOR).
        $recording = $DB->get_record('googlemeet_recordings',
            ['id' => $recordingid, 'googlemeetid' => $cm->instance], '*', MUST_EXIST);

        // Parse keypoints (one per line).
        $keypointsarray = [];
        if (!empty($keypoints)) {
            $lines = explode("\n", $keypoints);
            foreach ($lines as $line) {
                $line = trim($line);
                if (!empty($line)) {
                    // Remove leading bullet points, dashes, numbers.
                    $line = preg_replace('/^[\-\*\•\d\.]+\s*/', '', $line);
                    if (!empty($line)) {
                        $keypointsarray[] = $line;
                    }
                }
            }
        }

        // Parse topics (comma or newline separated).
        $topicsarray = [];
        if (!empty($topics)) {
            // Split by comma or newline.
            $items = preg_split('/[,\n]+/', $topics);
            foreach ($items as $item) {
                $item = trim($item);
                if (!empty($item)) {
                    $topicsarray[] = $item;
                }
            }
        }

        // Check if analysis exists.
        $analysis = $DB->get_record('googlemeet_ai_analysis', ['recordingid' => $recordingid]);

        $now = time();

        if ($analysis) {
            // Update existing.
            $analysis->summary = $summary;
            $analysis->keypoints = json_encode($keypointsarray);
            $analysis->topics = json_encode($topicsarray);
            $analysis->transcript = $transcript;
            $analysis->status = 'completed';
            $analysis->error = null;
            $analysis->aimodel = 'manual';
            $analysis->timemodified = $now;

            $DB->update_record('googlemeet_ai_analysis', $analysis);
        } else {
            // Insert new.
            $analysis = new \stdClass();
            $analysis->recordingid = $recordingid;
            $analysis->summary = $summary;
            $analysis->keypoints = json_encode($keypointsarray);
            $analysis->topics = json_encode($topicsarray);
            $analysis->transcript = $transcript;
            $analysis->language = 'es';
            $analysis->status = 'completed';
            $analysis->error = null;
            $analysis->aimodel = 'manual';
            $analysis->timecreated = $now;
            $analysis->timemodified = $now;

            $analysis->id = $DB->insert_record('googlemeet_ai_analysis', $analysis);
        }

        return [
            'success' => true,
            'id' => $analysis->id,
            'recordingid' => $recordingid,
            'summary' => $summary,
            'keypoints' => $keypointsarray,
            'topics' => $topicsarray,
            'transcript' => $transcript,
            'aimodel' => 'manual',
            'timemodified' => $now,
        ];
    }

    /**
     * Describes the save_ai_analysis return value.
     *
     * @return external_single_structure
     */
    public static function save_ai_analysis_returns() {
        return new external_single_structure(
            [
                'success' => new external_value(PARAM_BOOL, 'Whether save was successful'),
                'id' => new external_value(PARAM_INT, 'Analysis ID'),
                'recordingid' => new external_value(PARAM_INT, 'Recording ID'),
                'summary' => new external_value(PARAM_RAW, 'Summary text'),
                'keypoints' => new external_multiple_structure(
                    new external_value(PARAM_RAW, 'Key point'),
                    'List of key points'
                ),
                'topics' => new external_multiple_structure(
                    new external_value(PARAM_RAW, 'Topic'),
                    'List of topics'
                ),
                'transcript' => new external_value(PARAM_RAW, 'Transcript'),
                'aimodel' => new external_value(PARAM_TEXT, 'Model used (manual)'),
                'timemodified' => new external_value(PARAM_INT, 'Time modified'),
            ]
        );
    }

    /**
     * Describes the parameters for analyze_transcript.
     *
     * @return external_function_parameters
     */
    public static function analyze_transcript_parameters() {
        return new external_function_parameters(
            [
                'transcript' => new external_value(PARAM_RAW, 'The transcript text to analyze'),
                'recordingid' => new external_value(PARAM_INT, 'The recording ID'),
                'coursemoduleid' => new external_value(PARAM_INT, 'The course module ID'),
            ]
        );
    }

    /**
     * Analyze a pasted transcript using Gemini AI.
     *
     * @param string $transcript The transcript text
     * @param int $recordingid The recording ID
     * @param int $coursemoduleid The course module ID
     * @return array Analysis results
     */
    public static function analyze_transcript($transcript, $recordingid, $coursemoduleid) {
        global $DB;

        $params = self::validate_parameters(self::analyze_transcript_parameters(), [
            'transcript' => $transcript,
            'recordingid' => $recordingid,
            'coursemoduleid' => $coursemoduleid,
        ]);

        $cm = get_coursemodule_from_id('googlemeet', $params['coursemoduleid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/googlemeet:editrecording', $context);

        // Validate recording ID.
        if (empty($params['recordingid']) || $params['recordingid'] <= 0) {
            throw new \moodle_exception('ai_error', 'googlemeet', '', 'Invalid recording ID');
        }

        // Get recording info for context, scoped to this googlemeet instance (prevent IDOR).
        $recording = $DB->get_record('googlemeet_recordings',
            ['id' => $params['recordingid'], 'googlemeetid' => $cm->instance]);
        if (!$recording) {
            throw new \moodle_exception('ai_error', 'googlemeet', '', 'Recording not found (ID: ' . $params['recordingid'] . ')');
        }

        // Call Gemini to analyze the transcript.
        $client = new \mod_googlemeet\gemini_client();

        if (!$client->is_configured()) {
            throw new \moodle_exception('ai_not_configured', 'googlemeet');
        }

        try {
            $result = $client->analyze_transcript($params['transcript'], $recording->name);

            // Save to database.
            $now = time();
            $existing = $DB->get_record('googlemeet_ai_analysis', ['recordingid' => $params['recordingid']]);

            $analysis = new \stdClass();
            $analysis->recordingid = $params['recordingid'];
            $analysis->summary = $result->summary ?? '';
            $analysis->keypoints = json_encode($result->keypoints ?? []);
            $analysis->topics = json_encode($result->topics ?? []);
            $analysis->transcript = $params['transcript'];
            $analysis->language = $result->language ?? 'es';
            $analysis->status = 'completed';
            $analysis->error = null;
            $analysis->aimodel = $client->get_last_used_model() ?? $client->get_model();
            $analysis->timemodified = $now;

            if ($existing) {
                $analysis->id = $existing->id;
                $DB->update_record('googlemeet_ai_analysis', $analysis);
            } else {
                $analysis->timecreated = $now;
                $analysis->id = $DB->insert_record('googlemeet_ai_analysis', $analysis);
            }

            return [
                'success' => true,
                'summary' => $result->summary ?? '',
                'keypoints' => $result->keypoints ?? [],
                'topics' => $result->topics ?? [],
            ];

        } catch (\Exception $e) {
            // Log the real error server-side; return a generic message so internal API
            // details are never leaked to the client.
            debugging('mod_googlemeet analyze_transcript failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return [
                'success' => false,
                'summary' => '',
                'keypoints' => [],
                'topics' => [],
                'error' => get_string('ai_error_generic', 'googlemeet'),
            ];
        }
    }

    /**
     * Describes the analyze_transcript return value.
     *
     * @return external_single_structure
     */
    public static function analyze_transcript_returns() {
        return new external_single_structure(
            [
                'success' => new external_value(PARAM_BOOL, 'Whether analysis was successful'),
                'summary' => new external_value(PARAM_RAW, 'Generated summary'),
                'keypoints' => new external_multiple_structure(
                    new external_value(PARAM_RAW, 'Key point'),
                    'List of key points'
                ),
                'topics' => new external_multiple_structure(
                    new external_value(PARAM_RAW, 'Topic'),
                    'List of topics'
                ),
                'error' => new external_value(PARAM_RAW, 'Error message if failed', VALUE_OPTIONAL),
            ]
        );
    }

    /**
     * Common parameters for question id list actions.
     *
     * @return external_function_parameters
     */
    public static function question_list_action_parameters() {
        return new external_function_parameters([
            'recordingid' => new external_value(PARAM_INT, 'Recording ID'),
            'coursemoduleid' => new external_value(PARAM_INT, 'Course module ID'),
            'questionids' => new external_multiple_structure(new external_value(PARAM_INT, 'Question ID')),
            'sesskey' => new external_value(PARAM_RAW, 'Session key'),
        ]);
    }

    /**
     * Parameters for publish_questions.
     *
     * @return external_function_parameters
     */
    public static function publish_questions_parameters() {
        return self::question_list_action_parameters();
    }

    /**
     * Parameters for unpublish_questions.
     *
     * @return external_function_parameters
     */
    public static function unpublish_questions_parameters() {
        return self::question_list_action_parameters();
    }

    /**
     * Parameters for discard_questions.
     *
     * @return external_function_parameters
     */
    public static function discard_questions_parameters() {
        return self::question_list_action_parameters();
    }

    /**
     * Validate question-management context and IDs.
     *
     * @param array $params Validated params.
     * @return array [googlemeet, cm, context, question rows, question service]
     */
    private static function validate_question_action(array $params): array {
        global $DB;

        if (!confirm_sesskey($params['sesskey'])) {
            throw new \moodle_exception('invalidsesskey');
        }

        $cm = get_coursemodule_from_id('googlemeet', $params['coursemoduleid'], 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/googlemeet:managequestions', $context);
        $googlemeet = $DB->get_record('googlemeet', ['id' => $cm->instance], '*', MUST_EXIST);

        $service = new \mod_googlemeet\question_service();
        $rows = $service->require_questions_for_recording(
            $googlemeet,
            $cm,
            $context,
            (int)$params['recordingid'],
            $params['questionids']
        );

        return [$googlemeet, $cm, $context, $rows, $service];
    }

    /**
     * Publish draft questions.
     *
     * @param int $recordingid Recording ID.
     * @param int $coursemoduleid Course module ID.
     * @param array $questionids Question IDs.
     * @param string $sesskey Session key.
     * @return array
     */
    public static function publish_questions($recordingid, $coursemoduleid, $questionids, $sesskey) {
        $params = self::validate_parameters(self::question_list_action_parameters(), [
            'recordingid' => $recordingid,
            'coursemoduleid' => $coursemoduleid,
            'questionids' => $questionids,
            'sesskey' => $sesskey,
        ]);
        [, , , $rows, $service] = self::validate_question_action($params);
        return ['success' => true, 'count' => $service->set_status(
            $rows,
            \core_question\local\bank\question_version_status::QUESTION_STATUS_READY
        )];
    }

    /**
     * Unpublish ready questions.
     *
     * @param int $recordingid Recording ID.
     * @param int $coursemoduleid Course module ID.
     * @param array $questionids Question IDs.
     * @param string $sesskey Session key.
     * @return array
     */
    public static function unpublish_questions($recordingid, $coursemoduleid, $questionids, $sesskey) {
        $params = self::validate_parameters(self::question_list_action_parameters(), [
            'recordingid' => $recordingid,
            'coursemoduleid' => $coursemoduleid,
            'questionids' => $questionids,
            'sesskey' => $sesskey,
        ]);
        [, , , $rows, $service] = self::validate_question_action($params);
        return ['success' => true, 'count' => $service->set_status(
            $rows,
            \core_question\local\bank\question_version_status::QUESTION_STATUS_DRAFT
        )];
    }

    /**
     * Discard draft questions.
     *
     * @param int $recordingid Recording ID.
     * @param int $coursemoduleid Course module ID.
     * @param array $questionids Question IDs.
     * @param string $sesskey Session key.
     * @return array
     */
    public static function discard_questions($recordingid, $coursemoduleid, $questionids, $sesskey) {
        $params = self::validate_parameters(self::question_list_action_parameters(), [
            'recordingid' => $recordingid,
            'coursemoduleid' => $coursemoduleid,
            'questionids' => $questionids,
            'sesskey' => $sesskey,
        ]);
        [, , , $rows, $service] = self::validate_question_action($params);
        return ['success' => true, 'count' => $service->discard_drafts($rows)];
    }

    /**
     * Common return shape for question write actions.
     *
     * @return external_single_structure
     */
    public static function question_write_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the action succeeded'),
            'count' => new external_value(PARAM_INT, 'Number of affected questions'),
        ]);
    }

    /**
     * Publish return description.
     *
     * @return external_single_structure
     */
    public static function publish_questions_returns() {
        return self::question_write_returns();
    }

    /**
     * Unpublish return description.
     *
     * @return external_single_structure
     */
    public static function unpublish_questions_returns() {
        return self::question_write_returns();
    }

    /**
     * Discard return description.
     *
     * @return external_single_structure
     */
    public static function discard_questions_returns() {
        return self::question_write_returns();
    }

    /**
     * Parameters for update_question.
     *
     * @return external_function_parameters
     */
    public static function update_question_parameters() {
        return new external_function_parameters([
            'recordingid' => new external_value(PARAM_INT, 'Recording ID'),
            'coursemoduleid' => new external_value(PARAM_INT, 'Course module ID'),
            'questionid' => new external_value(PARAM_INT, 'Question ID'),
            'stem' => new external_value(PARAM_RAW, 'Question stem'),
            'options' => new external_multiple_structure(new external_value(PARAM_RAW, 'Option')),
            'correctindex' => new external_value(PARAM_INT, 'Correct option index'),
            'explanation' => new external_value(PARAM_RAW, 'Explanation'),
            'citation' => new external_value(PARAM_RAW, 'Citation', VALUE_DEFAULT, ''),
            'sesskey' => new external_value(PARAM_RAW, 'Session key'),
        ]);
    }

    /**
     * Update one question.
     *
     * @param int $recordingid Recording ID.
     * @param int $coursemoduleid Course module ID.
     * @param int $questionid Question ID.
     * @param string $stem Stem.
     * @param array $options Options.
     * @param int $correctindex Correct index.
     * @param string $explanation Explanation.
     * @param string $citation Citation.
     * @param string $sesskey Session key.
     * @return array
     */
    public static function update_question($recordingid, $coursemoduleid, $questionid, $stem, $options,
            $correctindex, $explanation, $citation, $sesskey) {
        $params = self::validate_parameters(self::update_question_parameters(), [
            'recordingid' => $recordingid,
            'coursemoduleid' => $coursemoduleid,
            'questionid' => $questionid,
            'stem' => $stem,
            'options' => $options,
            'correctindex' => $correctindex,
            'explanation' => $explanation,
            'citation' => $citation,
            'sesskey' => $sesskey,
        ]);
        $actionparams = [
            'recordingid' => $params['recordingid'],
            'coursemoduleid' => $params['coursemoduleid'],
            'questionids' => [$params['questionid']],
            'sesskey' => $params['sesskey'],
        ];
        [, , , $rows, $service] = self::validate_question_action($actionparams);
        $service->update_question($rows, $params['stem'], $params['options'], $params['correctindex'],
            $params['explanation'], $params['citation']);
        return ['success' => true, 'count' => 1];
    }

    /**
     * Return description for update_question.
     *
     * @return external_single_structure
     */
    public static function update_question_returns() {
        return self::question_write_returns();
    }

    /**
     * Parameters for queue_generate_questions.
     *
     * @return external_function_parameters
     */
    public static function queue_generate_questions_parameters() {
        return new external_function_parameters([
            'recordingid' => new external_value(PARAM_INT, 'Recording ID'),
            'coursemoduleid' => new external_value(PARAM_INT, 'Course module ID'),
            'count' => new external_value(PARAM_INT, 'Question count', VALUE_DEFAULT, 10),
            'sesskey' => new external_value(PARAM_RAW, 'Session key'),
        ]);
    }

    /**
     * Queue question generation.
     *
     * @param int $recordingid Recording ID.
     * @param int $coursemoduleid Course module ID.
     * @param int $count Question count.
     * @param string $sesskey Session key.
     * @return array
     */
    public static function queue_generate_questions($recordingid, $coursemoduleid, $count, $sesskey) {
        global $DB, $USER;

        $params = self::validate_parameters(self::queue_generate_questions_parameters(), [
            'recordingid' => $recordingid,
            'coursemoduleid' => $coursemoduleid,
            'count' => $count,
            'sesskey' => $sesskey,
        ]);
        if (!confirm_sesskey($params['sesskey'])) {
            throw new \moodle_exception('invalidsesskey');
        }

        $cm = get_coursemodule_from_id('googlemeet', $params['coursemoduleid'], 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/googlemeet:managequestions', $context);
        $DB->get_record('googlemeet_recordings',
            ['id' => $params['recordingid'], 'googlemeetid' => $cm->instance], 'id', MUST_EXIST);

        $service = new \mod_googlemeet\ai_service();
        if (!$service->is_available()) {
            throw new \moodle_exception('ai_not_configured', 'googlemeet');
        }
        $service->queue_question_generation($params['recordingid'], $cm->id, $params['count'], $USER->id);

        return ['success' => true, 'count' => 0];
    }

    /**
     * Return description for queue_generate_questions.
     *
     * @return external_single_structure
     */
    public static function queue_generate_questions_returns() {
        return self::question_write_returns();
    }

    /**
     * Parameters for get_questions.
     *
     * @return external_function_parameters
     */
    public static function get_questions_parameters() {
        return new external_function_parameters([
            'recordingid' => new external_value(PARAM_INT, 'Recording ID'),
            'coursemoduleid' => new external_value(PARAM_INT, 'Course module ID'),
        ]);
    }

    /**
     * Return teacher question list.
     *
     * @param int $recordingid Recording ID.
     * @param int $coursemoduleid Course module ID.
     * @return array
     */
    public static function get_questions($recordingid, $coursemoduleid) {
        global $DB;

        $params = self::validate_parameters(self::get_questions_parameters(), [
            'recordingid' => $recordingid,
            'coursemoduleid' => $coursemoduleid,
        ]);
        $cm = get_coursemodule_from_id('googlemeet', $params['coursemoduleid'], 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/googlemeet:managequestions', $context);
        $googlemeet = $DB->get_record('googlemeet', ['id' => $cm->instance], '*', MUST_EXIST);
        $DB->get_record('googlemeet_recordings',
            ['id' => $params['recordingid'], 'googlemeetid' => $cm->instance], 'id', MUST_EXIST);

        $service = new \mod_googlemeet\question_service();
        return ['questions' => $service->get_questions($googlemeet, $cm, $context, $params['recordingid'], false)];
    }

    /**
     * Return description for get_questions.
     *
     * @return external_single_structure
     */
    public static function get_questions_returns() {
        return new external_single_structure([
            'questions' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Question ID'),
                'name' => new external_value(PARAM_RAW, 'Question name'),
                'stem' => new external_value(PARAM_RAW, 'Question stem HTML'),
                'stemplain' => new external_value(PARAM_RAW, 'Question stem plain text'),
                'generalfeedback' => new external_value(PARAM_RAW, 'General feedback HTML'),
                'generalfeedbackplain' => new external_value(PARAM_RAW, 'General feedback plain text'),
                'correctindex' => new external_value(PARAM_INT, 'Correct answer index'),
                'status' => new external_value(PARAM_TEXT, 'Question status'),
                'isdraft' => new external_value(PARAM_BOOL, 'Draft'),
                'isready' => new external_value(PARAM_BOOL, 'Ready'),
                'statuslabel' => new external_value(PARAM_TEXT, 'Status label'),
                'advancedurl' => new external_value(PARAM_RAW, 'Advanced edit URL'),
                'answers' => new external_multiple_structure(new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Answer ID'),
                    'index' => new external_value(PARAM_INT, 'Answer index'),
                    'text' => new external_value(PARAM_RAW, 'Answer HTML'),
                    'plain' => new external_value(PARAM_RAW, 'Answer plain text'),
                    'correct' => new external_value(PARAM_BOOL, 'Whether this answer is correct'),
                ])),
            ])),
        ]);
    }

    /**
     * Parameters for get_practice_questions.
     *
     * @return external_function_parameters
     */
    public static function get_practice_questions_parameters() {
        return new external_function_parameters([
            'recordingid' => new external_value(PARAM_INT, 'Recording ID'),
            'coursemoduleid' => new external_value(PARAM_INT, 'Course module ID'),
        ]);
    }

    /**
     * Return ready practice questions without correctness information.
     *
     * @param int $recordingid Recording ID.
     * @param int $coursemoduleid Course module ID.
     * @return array
     */
    public static function get_practice_questions($recordingid, $coursemoduleid) {
        global $DB;

        $params = self::validate_parameters(self::get_practice_questions_parameters(), [
            'recordingid' => $recordingid,
            'coursemoduleid' => $coursemoduleid,
        ]);

        $cm = get_coursemodule_from_id('googlemeet', $params['coursemoduleid'], 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/googlemeet:view', $context);

        $googlemeet = $DB->get_record('googlemeet', ['id' => $cm->instance], '*', MUST_EXIST);
        $recording = $DB->get_record('googlemeet_recordings',
            ['id' => $params['recordingid'], 'googlemeetid' => $googlemeet->id], 'id,visible', MUST_EXIST);
        if (empty($recording->visible) && !has_capability('mod/googlemeet:editrecording', $context)) {
            throw new \moodle_exception('invalidrecord', 'error');
        }

        $service = new \mod_googlemeet\question_service();
        return [
            'questions' => $service->get_ready_practice_questions($googlemeet, $cm, $context, $params['recordingid']),
        ];
    }

    /**
     * Return description for get_practice_questions.
     *
     * @return external_single_structure
     */
    public static function get_practice_questions_returns() {
        return new external_single_structure([
            'questions' => new external_multiple_structure(new external_single_structure([
                'questionid' => new external_value(PARAM_INT, 'Question ID'),
                'stem' => new external_value(PARAM_RAW, 'Formatted question stem HTML'),
                'options' => new external_multiple_structure(new external_single_structure([
                    'answerid' => new external_value(PARAM_INT, 'Answer ID'),
                    'text' => new external_value(PARAM_RAW, 'Formatted answer HTML'),
                ])),
            ])),
        ]);
    }

    /**
     * Parameters for check_practice_answer.
     *
     * @return external_function_parameters
     */
    public static function check_practice_answer_parameters() {
        return new external_function_parameters([
            'recordingid' => new external_value(PARAM_INT, 'Recording ID'),
            'coursemoduleid' => new external_value(PARAM_INT, 'Course module ID'),
            'questionid' => new external_value(PARAM_INT, 'Question ID'),
            'answerid' => new external_value(PARAM_INT, 'Answer ID'),
        ]);
    }

    /**
     * Check one formative practice answer.
     *
     * @param int $recordingid Recording ID.
     * @param int $coursemoduleid Course module ID.
     * @param int $questionid Question ID.
     * @param int $answerid Answer ID.
     * @return array
     */
    public static function check_practice_answer($recordingid, $coursemoduleid, $questionid, $answerid) {
        global $DB;

        $params = self::validate_parameters(self::check_practice_answer_parameters(), [
            'recordingid' => $recordingid,
            'coursemoduleid' => $coursemoduleid,
            'questionid' => $questionid,
            'answerid' => $answerid,
        ]);

        $cm = get_coursemodule_from_id('googlemeet', $params['coursemoduleid'], 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/googlemeet:view', $context);

        $googlemeet = $DB->get_record('googlemeet', ['id' => $cm->instance], '*', MUST_EXIST);
        $recording = $DB->get_record('googlemeet_recordings',
            ['id' => $params['recordingid'], 'googlemeetid' => $googlemeet->id], 'id,visible', MUST_EXIST);
        if (empty($recording->visible) && !has_capability('mod/googlemeet:editrecording', $context)) {
            throw new \moodle_exception('invalidrecord', 'error');
        }

        $service = new \mod_googlemeet\question_service();
        return $service->validate_practice_answer(
            $googlemeet,
            $cm,
            $context,
            $params['recordingid'],
            $params['questionid'],
            $params['answerid']
        );
    }

    /**
     * Return description for check_practice_answer.
     *
     * @return external_single_structure
     */
    public static function check_practice_answer_returns() {
        return new external_single_structure([
            'correct' => new external_value(PARAM_BOOL, 'Whether the selected answer is correct'),
            'correctanswerid' => new external_value(PARAM_INT, 'Correct answer ID'),
            'explanation' => new external_value(PARAM_RAW, 'Formatted general feedback HTML'),
        ]);
    }
}
