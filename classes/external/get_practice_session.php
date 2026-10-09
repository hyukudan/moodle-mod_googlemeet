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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_googlemeet\local\practice_attempts;

/**
 * Activity-wide practice session: "review my failed questions" or "practice by topic".
 *
 * Answers are checked with mod_googlemeet_check_practice_answer (per recording), which also
 * persists the attempt.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_practice_session extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'coursemoduleid' => new external_value(PARAM_INT, 'Course module ID'),
            'mode' => new external_value(PARAM_ALPHA, 'failed or topic'),
            'topic' => new external_value(PARAM_TEXT, 'Topic (topic mode)', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Return the session questions, without correctness information.
     *
     * @param int $coursemoduleid Course module id.
     * @param string $mode Practice mode.
     * @param string $topic Topic.
     * @return array
     */
    public static function execute(int $coursemoduleid, string $mode, string $topic = ''): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'coursemoduleid' => $coursemoduleid,
            'mode' => $mode,
            'topic' => $topic,
        ]);
        if (!in_array($params['mode'], [practice_attempts::MODE_FAILED, practice_attempts::MODE_TOPIC], true)) {
            throw new \invalid_parameter_exception('mode');
        }

        $cm = get_coursemodule_from_id('googlemeet', $params['coursemoduleid'], 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/googlemeet:view', $context);
        $googlemeet = $DB->get_record('googlemeet', ['id' => $cm->instance], '*', MUST_EXIST);

        $questions = practice_attempts::get_session_questions($googlemeet, $cm, $context, (int)$USER->id,
            $params['mode'], $params['topic'], 0);
        if ($params['mode'] === practice_attempts::MODE_TOPIC) {
            shuffle($questions);
        }
        $questions = array_slice($questions, 0, practice_attempts::SESSION_LIMIT);

        return ['questions' => $questions];
    }

    /**
     * Return description.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'questions' => new external_multiple_structure(new external_single_structure([
                'questionid' => new external_value(PARAM_INT, 'Question ID'),
                'recordingid' => new external_value(PARAM_INT, 'Recording ID the question belongs to'),
                'recordingname' => new external_value(PARAM_TEXT, 'Recording display name'),
                'stem' => new external_value(PARAM_RAW, 'Formatted question stem HTML'),
                'options' => new external_multiple_structure(new external_single_structure([
                    'answerid' => new external_value(PARAM_INT, 'Answer ID'),
                    'text' => new external_value(PARAM_RAW, 'Formatted answer HTML'),
                ])),
            ])),
        ]);
    }
}
