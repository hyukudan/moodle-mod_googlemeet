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

namespace mod_googlemeet\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_googlemeet\local\ai_review;

/**
 * IA-04: publish (mark as reviewed) the AI summary of one recording, or of every recording of the activity.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class review_ai_analysis extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'coursemoduleid' => new external_value(PARAM_INT, 'Course module ID'),
            'recordingid' => new external_value(PARAM_INT, 'Recording ID; 0 publishes every reviewable summary of the activity',
                VALUE_DEFAULT, 0),
            'seen' => new external_value(PARAM_INT, 'Single: timemodified of the content the teacher reviewed. '
                . 'All: time the teacher loaded the page. 0 = no check (publishes the current content)', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Mark the analysis (or all pending analyses) as reviewed by the current user.
     *
     * @param int $coursemoduleid Course module id.
     * @param int $recordingid Recording id, or 0 for the whole activity.
     * @param int $seen Version check, see execute_parameters().
     * @return array
     */
    public static function execute(int $coursemoduleid, int $recordingid = 0, int $seen = 0): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'coursemoduleid' => $coursemoduleid,
            'recordingid' => $recordingid,
            'seen' => $seen,
        ]);

        $cm = get_coursemodule_from_id('googlemeet', $params['coursemoduleid'], 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability(ai_review::CAPABILITY, $context);

        if (empty($params['recordingid'])) {
            $count = ai_review::mark_all_reviewed((int)$cm->instance, (int)$USER->id, (int)$params['seen']);
            return ['success' => true, 'count' => $count, 'changed' => false];
        }

        // Scope the recording to this activity (prevent IDOR).
        $recording = $DB->get_record('googlemeet_recordings',
            ['id' => $params['recordingid'], 'googlemeetid' => $cm->instance, 'deleted' => 0], 'id', IGNORE_MISSING);
        if (!$recording) {
            throw new \moodle_exception('recordingnotfound', 'googlemeet');
        }
        $analysis = $DB->get_record('googlemeet_ai_analysis', ['recordingid' => $recording->id], 'id, status, timemodified');
        $success = $analysis && ai_review::mark_reviewed((int)$analysis->id, (int)$USER->id, (int)$params['seen']);
        $changed = !$success && $analysis && $params['seen'] > 0 && (int)$analysis->timemodified !== (int)$params['seen'];
        return ['success' => (bool)$success, 'count' => $success ? 1 : 0, 'changed' => (bool)$changed];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the content is now published'),
            'count' => new external_value(PARAM_INT, 'Number of summaries published by this call (or already published)'),
            'changed' => new external_value(PARAM_BOOL, 'The content changed after the teacher loaded it; reload and review again'),
        ]);
    }
}
