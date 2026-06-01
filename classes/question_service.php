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

namespace mod_googlemeet;

use core_question\local\bank\question_version_status;
use moodle_exception;
use moodle_url;
use question_bank;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/engine/bank.php');

/**
 * Question-bank integration for per-recording AI practice questions.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_service {

    /**
     * Build the deterministic tag used to bind questions to one recording.
     *
     * @param int $recordingid Recording id.
     * @return string
     */
    public static function tag_for_recording(int $recordingid): string {
        return 'googlemeet-rec-' . $recordingid;
    }

    /**
     * Get or create this activity's module-context question category.
     *
     * @param stdClass $googlemeet Activity record.
     * @param stdClass $cm Course-module record.
     * @param \context_module $context Module context.
     * @param bool $create Create when missing.
     * @return stdClass|null
     */
    public function get_category(stdClass $googlemeet, stdClass $cm, \context_module $context, bool $create): ?stdClass {
        global $DB;

        $idnumber = 'googlemeet_cm_' . $cm->id;
        $category = $DB->get_record('question_categories', [
            'contextid' => $context->id,
            'idnumber' => $idnumber,
        ]);
        if ($category || !$create) {
            return $category ?: null;
        }

        $topcategory = question_get_top_category($context->id, true);
        if (!$topcategory) {
            throw new moodle_exception('error');
        }

        $category = new stdClass();
        $category->parent = $topcategory->id;
        $category->contextid = $context->id;
        $category->name = shorten_text(get_string('question_category_name', 'googlemeet', format_string($googlemeet->name)), 1333);
        $category->info = '';
        $category->infoformat = FORMAT_HTML;
        $category->sortorder = 999;
        $category->stamp = make_unique_id_code();
        $category->idnumber = $idnumber;
        $category->id = $DB->insert_record('question_categories', $category);

        $event = \core\event\question_category_created::create_from_question_category_instance((object) [
            'id' => $category->id,
            'contextid' => $context->id,
        ]);
        $event->trigger();

        return $category;
    }

    /**
     * Create one draft multichoice question and tag it for the recording.
     *
     * @param stdClass $googlemeet Activity record.
     * @param stdClass $cm Course-module record.
     * @param \context_module $context Module context.
     * @param int $recordingid Recording id.
     * @param array $data Question data from Gemini.
     * @return int Created question id.
     */
    public function create_draft_multichoice(
        stdClass $googlemeet,
        stdClass $cm,
        \context_module $context,
        int $recordingid,
        array $data
    ): int {
        $category = $this->get_category($googlemeet, $cm, $context, true);
        $stem = trim((string)($data['stem'] ?? ''));
        $options = array_values($data['options'] ?? []);
        $correctindex = (int)($data['correctindex'] ?? -1);
        if ($stem === '' || count($options) !== 4 || $correctindex < 0 || $correctindex > 3) {
            throw new moodle_exception('ai_invalid_analysis', 'googlemeet', '', 'Invalid question payload');
        }

        $question = new stdClass();
        $question->qtype = 'multichoice';

        $form = new stdClass();
        $form->category = $category->id . ',' . $context->id;
        $form->name = shorten_text(clean_param($stem, PARAM_TEXT), 250);
        $form->questiontext = ['text' => $this->html_paragraph($stem), 'format' => FORMAT_HTML];
        $form->generalfeedback = [
            'text' => $this->build_general_feedback($data['explanation'] ?? '', $data['citation'] ?? ''),
            'format' => FORMAT_HTML,
        ];
        $form->defaultmark = 1;
        $form->penalty = 0.3333333;
        $form->status = question_version_status::QUESTION_STATUS_DRAFT;
        $form->idnumber = null;
        $form->single = 1;
        $form->shuffleanswers = 0;
        $form->answernumbering = 'abc';
        $form->showstandardinstruction = 0;
        $form->shownumcorrect = 0;
        $form->correctfeedback = ['text' => '', 'format' => FORMAT_HTML];
        $form->partiallycorrectfeedback = ['text' => '', 'format' => FORMAT_HTML];
        $form->incorrectfeedback = ['text' => '', 'format' => FORMAT_HTML];
        $form->answer = [];
        $form->fraction = [];
        $form->feedback = [];

        foreach ($options as $i => $option) {
            $form->answer[$i] = ['text' => $this->html_paragraph((string)$option), 'format' => FORMAT_HTML];
            $form->fraction[$i] = ($i === $correctindex) ? 1.0 : 0.0;
            $form->feedback[$i] = ['text' => '', 'format' => FORMAT_HTML];
        }

        $created = question_bank::get_qtype('multichoice')->save_question($question, $form);
        \core_tag_tag::set_item_tags('core_question', 'question', $created->id, $context, [self::tag_for_recording($recordingid)], 0);
        question_bank::notify_question_edited($created->id);

        return (int)$created->id;
    }

    /**
     * Return the teacher-facing question list for one recording.
     *
     * @param stdClass $googlemeet Activity record.
     * @param stdClass $cm Course-module record.
     * @param \context_module $context Module context.
     * @param int $recordingid Recording id.
     * @param bool $readyonly Only include published questions.
     * @return array
     */
    public function get_questions(
        stdClass $googlemeet,
        stdClass $cm,
        \context_module $context,
        int $recordingid,
        bool $readyonly = false
    ): array {
        global $DB;

        $category = $this->get_category($googlemeet, $cm, $context, false);
        if (!$category) {
            return [];
        }

        $params = [
            'categoryid' => $category->id,
            'component' => 'core_question',
            'itemtype' => 'question',
            'tagname' => \core_text::strtolower(self::tag_for_recording($recordingid)),
        ];
        $statussql = '';
        if ($readyonly) {
            $statussql = ' AND qv.status = :readystatus';
            $params['readystatus'] = question_version_status::QUESTION_STATUS_READY;
        }

        $sql = "SELECT q.id,
                       q.name,
                       q.questiontext,
                       q.questiontextformat,
                       q.generalfeedback,
                       q.generalfeedbackformat,
                       qv.status,
                       qv.version,
                       qbe.questioncategoryid
                  FROM {question} q
                  JOIN {question_versions} qv ON qv.questionid = q.id
                  JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                  JOIN {tag_instance} ti ON ti.itemid = q.id
                  JOIN {tag} t ON t.id = ti.tagid
                 WHERE qbe.questioncategoryid = :categoryid
                   AND ti.component = :component
                   AND ti.itemtype = :itemtype
                   AND t.name = :tagname
                   {$statussql}
              ORDER BY q.id DESC";

        $records = $DB->get_records_sql($sql, $params);
        $questions = [];
        foreach ($records as $record) {
            $answers = $DB->get_records('question_answers', ['question' => $record->id], 'id ASC',
                'id, answer, answerformat, fraction');
            $answerdata = [];
            $correctindex = 0;
            $index = 0;
            foreach ($answers as $answer) {
                $iscorrect = (float)$answer->fraction > 0;
                if ($iscorrect) {
                    $correctindex = $index;
                }
                $answerdata[] = [
                    'id' => (int)$answer->id,
                    'index' => $index,
                    'text' => format_text($answer->answer, $answer->answerformat, ['context' => $context]),
                    'plain' => trim(html_to_text($answer->answer, 0, false)),
                    'correct' => $iscorrect,
                ];
                $index++;
            }

            $status = $record->status ?: question_version_status::QUESTION_STATUS_READY;
            $questions[] = [
                'id' => (int)$record->id,
                'name' => format_string($record->name),
                'stem' => format_text($record->questiontext, $record->questiontextformat, ['context' => $context]),
                'stemplain' => trim(html_to_text($record->questiontext, 0, false)),
                'generalfeedback' => format_text($record->generalfeedback, $record->generalfeedbackformat, ['context' => $context]),
                'generalfeedbackplain' => trim(html_to_text($record->generalfeedback, 0, false)),
                'answers' => $answerdata,
                'correctindex' => $correctindex,
                'status' => $status,
                'isdraft' => $status === question_version_status::QUESTION_STATUS_DRAFT,
                'isready' => $status === question_version_status::QUESTION_STATUS_READY,
                'statuslabel' => $status === question_version_status::QUESTION_STATUS_READY
                    ? get_string('question_status_published', 'googlemeet')
                    : get_string('question_status_draft', 'googlemeet'),
                'advancedurl' => (new moodle_url('/question/bank/editquestion/question.php', [
                    'cmid' => $cm->id,
                    'id' => $record->id,
                ]))->out(false),
            ];
        }

        return $questions;
    }

    /**
     * Return ready questions for the student practice player without correctness data.
     *
     * @param stdClass $googlemeet Activity record.
     * @param stdClass $cm Course-module record.
     * @param \context_module $context Module context.
     * @param int $recordingid Recording id.
     * @return array
     */
    public function get_ready_practice_questions(
        stdClass $googlemeet,
        stdClass $cm,
        \context_module $context,
        int $recordingid
    ): array {
        $questions = $this->get_questions($googlemeet, $cm, $context, $recordingid, true);
        $practicequestions = [];

        foreach ($questions as $question) {
            $answers = [];
            foreach ($question['answers'] as $answer) {
                $answers[] = [
                    'answerid' => (int)$answer['id'],
                    'text' => $answer['text'],
                ];
            }

            $practicequestions[] = [
                'questionid' => (int)$question['id'],
                'stem' => $question['stem'],
                'options' => $answers,
            ];
        }

        return $practicequestions;
    }

    /**
     * Validate a practice answer against a ready recording-scoped question.
     *
     * @param stdClass $googlemeet Activity record.
     * @param stdClass $cm Course-module record.
     * @param \context_module $context Module context.
     * @param int $recordingid Recording id.
     * @param int $questionid Question id.
     * @param int $answerid Answer id.
     * @return array
     */
    public function validate_practice_answer(
        stdClass $googlemeet,
        stdClass $cm,
        \context_module $context,
        int $recordingid,
        int $questionid,
        int $answerid
    ): array {
        global $DB;

        $rows = $this->require_questions_for_recording($googlemeet, $cm, $context, $recordingid, [$questionid], true);
        $row = reset($rows);
        if (!$row || $row->qtype !== 'multichoice') {
            throw new moodle_exception('invalidrecord', 'error');
        }

        $answers = $DB->get_records('question_answers', ['question' => $questionid], 'id ASC',
            'id, answer, answerformat, fraction');
        if (empty($answers) || !isset($answers[$answerid])) {
            throw new moodle_exception('invalidrecord', 'error');
        }

        $correctanswer = null;
        foreach ($answers as $answer) {
            if ((float)$answer->fraction > 0) {
                $correctanswer = $answer;
                break;
            }
        }
        if (!$correctanswer) {
            throw new moodle_exception('invalidrecord', 'error');
        }

        $question = $DB->get_record('question', ['id' => $questionid], 'generalfeedback,generalfeedbackformat', MUST_EXIST);

        return [
            'correct' => (float)$answers[$answerid]->fraction > 0,
            'correctanswerid' => (int)$correctanswer->id,
            'explanation' => format_text($question->generalfeedback, $question->generalfeedbackformat, ['context' => $context]),
        ];
    }

    /**
     * Check that each question belongs to this recording and return its rows.
     *
     * @param stdClass $googlemeet Activity record.
     * @param stdClass $cm Course-module record.
     * @param \context_module $context Module context.
     * @param int $recordingid Recording id.
     * @param array $questionids Question ids.
     * @param bool $readyonly Require ready status.
     * @return array
     */
    public function require_questions_for_recording(
        stdClass $googlemeet,
        stdClass $cm,
        \context_module $context,
        int $recordingid,
        array $questionids,
        bool $readyonly = false
    ): array {
        global $DB;

        $DB->get_record('googlemeet_recordings', ['id' => $recordingid, 'googlemeetid' => $googlemeet->id], 'id', MUST_EXIST);
        $category = $this->get_category($googlemeet, $cm, $context, false);
        if (!$category || empty($questionids)) {
            throw new moodle_exception('invalidrecord', 'error');
        }

        $questionids = array_values(array_unique(array_map('intval', $questionids)));
        [$insql, $inparams] = $DB->get_in_or_equal($questionids, SQL_PARAMS_NAMED);
        $params = $inparams + [
            'categoryid' => $category->id,
            'component' => 'core_question',
            'itemtype' => 'question',
            'tagname' => \core_text::strtolower(self::tag_for_recording($recordingid)),
        ];
        $statussql = '';
        if ($readyonly) {
            $statussql = ' AND qv.status = :readystatus';
            $params['readystatus'] = question_version_status::QUESTION_STATUS_READY;
        }

        $sql = "SELECT q.id, q.qtype, qv.id AS versionid, qv.status
                  FROM {question} q
                  JOIN {question_versions} qv ON qv.questionid = q.id
                  JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                  JOIN {tag_instance} ti ON ti.itemid = q.id
                  JOIN {tag} t ON t.id = ti.tagid
                 WHERE q.id {$insql}
                   AND qbe.questioncategoryid = :categoryid
                   AND ti.component = :component
                   AND ti.itemtype = :itemtype
                   AND t.name = :tagname
                   {$statussql}";
        $records = $DB->get_records_sql($sql, $params);
        if (count($records) !== count($questionids)) {
            throw new moodle_exception('invalidrecord', 'error');
        }

        return $records;
    }

    /**
     * Set draft/ready status for questions.
     *
     * @param array $questionrows Rows returned by require_questions_for_recording().
     * @param string $status Target status.
     * @return int
     */
    public function set_status(array $questionrows, string $status): int {
        global $DB;

        if (!in_array($status, [
            question_version_status::QUESTION_STATUS_DRAFT,
            question_version_status::QUESTION_STATUS_READY,
        ], true)) {
            throw new moodle_exception('invalidrecord', 'error');
        }

        foreach ($questionrows as $row) {
            $DB->set_field('question_versions', 'status', $status, ['id' => $row->versionid]);
            question_bank::notify_question_edited($row->id);
        }

        return count($questionrows);
    }

    /**
     * Delete draft questions.
     *
     * @param array $questionrows Rows returned by require_questions_for_recording().
     * @return int
     */
    public function discard_drafts(array $questionrows): int {
        foreach ($questionrows as $row) {
            if ($row->status !== question_version_status::QUESTION_STATUS_DRAFT) {
                throw new moodle_exception('question_discard_ready_error', 'googlemeet');
            }
            question_delete_question($row->id);
        }

        return count($questionrows);
    }

    /**
     * Update a question in place for compact teacher review edits.
     *
     * @param array $questionrows Exactly one verified question row.
     * @param string $stem Stem text.
     * @param array $options Four option strings.
     * @param int $correctindex Correct option index.
     * @param string $explanation Explanation.
     * @param string $citation Citation.
     * @return void
     */
    public function update_question(
        array $questionrows,
        string $stem,
        array $options,
        int $correctindex,
        string $explanation,
        string $citation
    ): void {
        global $DB;

        $row = reset($questionrows);
        if (!$row || $row->qtype !== 'multichoice') {
            throw new moodle_exception('invalidrecord', 'error');
        }
        $stem = trim($stem);
        $options = array_values($options);
        if ($stem === '' || count($options) !== 4 || $correctindex < 0 || $correctindex > 3) {
            throw new moodle_exception('invalidrecord', 'error');
        }

        $question = $DB->get_record('question', ['id' => $row->id], '*', MUST_EXIST);
        $question->name = shorten_text(clean_param($stem, PARAM_TEXT), 250);
        $question->questiontext = $this->html_paragraph($stem);
        $question->questiontextformat = FORMAT_HTML;
        $question->generalfeedback = $this->build_general_feedback($explanation, $citation);
        $question->generalfeedbackformat = FORMAT_HTML;
        $question->timemodified = time();
        $DB->update_record('question', $question);

        $answers = array_values($DB->get_records('question_answers', ['question' => $row->id], 'id ASC'));
        if (count($answers) !== 4) {
            throw new moodle_exception('invalidrecord', 'error');
        }
        foreach ($answers as $i => $answer) {
            $answer->answer = $this->html_paragraph((string)$options[$i]);
            $answer->answerformat = FORMAT_HTML;
            $answer->fraction = ($i === $correctindex) ? 1.0 : 0.0;
            $answer->feedback = '';
            $answer->feedbackformat = FORMAT_HTML;
            $DB->update_record('question_answers', $answer);
        }

        question_bank::notify_question_edited($row->id);
    }

    /**
     * Check whether a generation adhoc task is currently queued for this recording.
     *
     * @param int $recordingid Recording id.
     * @return bool
     */
    public function is_generation_queued(int $recordingid): bool {
        global $DB;

        return $DB->record_exists_select('task_adhoc',
            "(classname = :classname OR classname = :classname2) AND " . $DB->sql_like('customdata', ':needle', false, false),
            [
                'classname' => '\\mod_googlemeet\\task\\generate_questions',
                'classname2' => 'mod_googlemeet\\task\\generate_questions',
                'needle' => '%"recordingid":' . $recordingid . '%',
            ]);
    }

    /**
     * Build feedback HTML.
     *
     * @param string $explanation Explanation.
     * @param string|null $citation Citation.
     * @return string
     */
    private function build_general_feedback(string $explanation, ?string $citation): string {
        $explanation = trim($explanation);
        $citation = trim((string)$citation);
        if ($citation === '') {
            $citation = get_string('question_no_reference', 'googlemeet');
        }

        return $this->html_paragraph($explanation)
            . '<p><strong>' . s(get_string('question_reference_label', 'googlemeet')) . '</strong> '
            . s($citation) . '</p>';
    }

    /**
     * Convert plain text to a simple safe HTML paragraph.
     *
     * @param string $text Text.
     * @return string
     */
    private function html_paragraph(string $text): string {
        return '<p>' . nl2br(s(trim($text))) . '</p>';
    }
}
