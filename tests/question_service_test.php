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
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/lib.php');
require_once($CFG->dirroot . '/mod/googlemeet/locallib.php');

/**
 * Unit tests for mod_googlemeet\question_service question-bank integration.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\mod_googlemeet\question_service::class)]
class question_service_test extends \advanced_testcase {

    /**
     * Create a course, googlemeet module and module context.
     *
     * @return array
     */
    private function create_googlemeet_fixture(): array {
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $googlemeet = $this->getDataGenerator()->create_module('googlemeet', [
            'course' => $course->id,
            'name' => 'Practice room',
            'url' => 'https://meet.google.com/abc-defg-hij',
        ]);
        $cm = get_coursemodule_from_instance('googlemeet', $googlemeet->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        return [$course, $googlemeet, $cm, $context];
    }

    /**
     * Insert a recording for a googlemeet instance.
     *
     * @param int $googlemeetid Google Meet instance id.
     * @param string $recordingid External recording id.
     * @return int
     */
    private function create_recording(int $googlemeetid, string $recordingid = 'drive-recording'): int {
        global $DB;

        $now = time();
        return (int)$DB->insert_record('googlemeet_recordings', (object) [
            'googlemeetid' => $googlemeetid,
            'recordingid' => $recordingid . '-' . random_string(6),
            'name' => 'Recording ' . random_string(6),
            'createdtime' => $now,
            'duration' => '00:05:00',
            'webviewlink' => 'https://drive.google.com/file/d/example/view',
            'transcripttext' => 'Transcript text.',
            'visible' => 1,
            'timemodified' => $now,
        ]);
    }

    /**
     * Standard generated multichoice payload.
     *
     * @param string $stem Stem text.
     * @param int $correctindex Correct answer index.
     * @return array
     */
    private function question_data(string $stem = 'What was explained?', int $correctindex = 1): array {
        return [
            'stem' => $stem,
            'options' => [
                'First option',
                'Second option',
                'Third option',
                'Fourth option',
            ],
            'correctindex' => $correctindex,
            'explanation' => 'Because the recording explains this point.',
            'citation' => '00:01:05',
        ];
    }

    /**
     * Create one draft question.
     *
     * @param question_service $service Service instance.
     * @param \stdClass $googlemeet Activity record.
     * @param \stdClass $cm Course-module record.
     * @param \context_module $context Module context.
     * @param int $recordingid Recording id.
     * @param array|null $data Question data.
     * @return int
     */
    private function create_draft_question(
        question_service $service,
        \stdClass $googlemeet,
        \stdClass $cm,
        \context_module $context,
        int $recordingid,
        ?array $data = null
    ): int {
        return $service->create_draft_multichoice(
            $googlemeet,
            $cm,
            $context,
            $recordingid,
            $data ?? $this->question_data()
        );
    }

    /**
     * Return answer ids for a question.
     *
     * @param int $questionid Question id.
     * @return array
     */
    private function get_answer_ids(int $questionid): array {
        global $DB;

        return array_map('intval', array_keys($DB->get_records('question_answers', ['question' => $questionid], 'id ASC')));
    }

    /**
     * Draft multichoice creation stores category, status, tag and answer correctness.
     *
     */
    public function test_create_draft_multichoice_creates_tagged_draft_question(): void {
        global $DB;

        $this->resetAfterTest();

        [, $googlemeet, $cm, $context] = $this->create_googlemeet_fixture();
        $recordingid = $this->create_recording($googlemeet->id);
        $service = new question_service();

        $questionid = $this->create_draft_question($service, $googlemeet, $cm, $context, $recordingid);

        $this->assertGreaterThan(0, $questionid);
        $category = $service->get_category($googlemeet, $cm, $context, false);
        $this->assertNotEmpty($category);

        $row = $DB->get_record_sql(
            "SELECT q.id, qv.status, qbe.questioncategoryid
               FROM {question} q
               JOIN {question_versions} qv ON qv.questionid = q.id
               JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
              WHERE q.id = :questionid",
            ['questionid' => $questionid],
            MUST_EXIST
        );
        $this->assertEquals($category->id, (int)$row->questioncategoryid);
        $this->assertEquals(question_version_status::QUESTION_STATUS_DRAFT, $row->status);

        $tags = \core_tag_tag::get_item_tags_array('core_question', 'question', $questionid);
        $this->assertContains(question_service::tag_for_recording($recordingid), array_values($tags));

        $answers = $DB->get_records('question_answers', ['question' => $questionid], 'id ASC', 'id, fraction');
        $this->assertCount(4, $answers);
        $correct = array_filter($answers, static function($answer): bool {
            return (float)$answer->fraction === 1.0;
        });
        $this->assertCount(1, $correct);
    }

    /**
     * Teacher list includes drafts, while ready-only hides them.
     *
     */
    public function test_get_questions_lists_drafts_and_readyonly_excludes_them(): void {
        $this->resetAfterTest();

        [, $googlemeet, $cm, $context] = $this->create_googlemeet_fixture();
        $recordingid = $this->create_recording($googlemeet->id);
        $service = new question_service();
        $questionid = $this->create_draft_question($service, $googlemeet, $cm, $context, $recordingid);

        $questions = $service->get_questions($googlemeet, $cm, $context, $recordingid);
        $this->assertCount(1, $questions);
        $this->assertEquals($questionid, $questions[0]['id']);
        $this->assertTrue($questions[0]['isdraft']);
        $this->assertFalse($questions[0]['isready']);

        $this->assertEmpty($service->get_questions($googlemeet, $cm, $context, $recordingid, true));
    }

    /**
     * Publishing a draft makes it visible in ready-only lists.
     *
     */
    public function test_set_status_publishes_draft_question(): void {
        $this->resetAfterTest();

        [, $googlemeet, $cm, $context] = $this->create_googlemeet_fixture();
        $recordingid = $this->create_recording($googlemeet->id);
        $service = new question_service();
        $questionid = $this->create_draft_question($service, $googlemeet, $cm, $context, $recordingid);
        $rows = $service->require_questions_for_recording($googlemeet, $cm, $context, $recordingid, [$questionid]);

        $this->assertEquals(1, $service->set_status($rows, question_version_status::QUESTION_STATUS_READY));

        $ready = $service->get_questions($googlemeet, $cm, $context, $recordingid, true);
        $this->assertCount(1, $ready);
        $this->assertEquals($questionid, $ready[0]['id']);
        $this->assertTrue($ready[0]['isready']);
    }

    /**
     * Drafts can be discarded, but ready questions cannot.
     *
     */
    public function test_discard_drafts_deletes_drafts_and_rejects_ready_questions(): void {
        $this->resetAfterTest();

        [, $googlemeet, $cm, $context] = $this->create_googlemeet_fixture();
        $recordingid = $this->create_recording($googlemeet->id);
        $service = new question_service();

        $draftid = $this->create_draft_question($service, $googlemeet, $cm, $context, $recordingid,
            $this->question_data('Draft to discard'));
        $draftrows = $service->require_questions_for_recording($googlemeet, $cm, $context, $recordingid, [$draftid]);
        $this->assertEquals(1, $service->discard_drafts($draftrows));
        $this->assertEmpty($service->get_questions($googlemeet, $cm, $context, $recordingid));

        $readyid = $this->create_draft_question($service, $googlemeet, $cm, $context, $recordingid,
            $this->question_data('Ready to keep'));
        $readyrows = $service->require_questions_for_recording($googlemeet, $cm, $context, $recordingid, [$readyid]);
        $service->set_status($readyrows, question_version_status::QUESTION_STATUS_READY);
        $readyrows = $service->require_questions_for_recording($googlemeet, $cm, $context, $recordingid, [$readyid]);

        $this->expectException(\moodle_exception::class);
        $service->discard_drafts($readyrows);
    }

    /**
     * Question ownership checks reject another recording's tag and draft ready-only access.
     *
     */
    public function test_require_questions_for_recording_rejects_cross_recording_and_drafts_when_readyonly(): void {
        $this->resetAfterTest();

        [, $googlemeet, $cm, $context] = $this->create_googlemeet_fixture();
        $recordinga = $this->create_recording($googlemeet->id, 'recording-a');
        $recordingb = $this->create_recording($googlemeet->id, 'recording-b');
        $service = new question_service();
        $questionid = $this->create_draft_question($service, $googlemeet, $cm, $context, $recordinga);

        try {
            $service->require_questions_for_recording($googlemeet, $cm, $context, $recordingb, [$questionid]);
            $this->fail('Expected cross-recording question lookup to fail.');
        } catch (\moodle_exception $e) {
            $this->assertInstanceOf(\moodle_exception::class, $e);
        }

        $this->expectException(\moodle_exception::class);
        $service->require_questions_for_recording($googlemeet, $cm, $context, $recordinga, [$questionid], true);
    }

    /**
     * Practice questions return only ready questions and hide correctness metadata.
     *
     */
    public function test_get_ready_practice_questions_returns_ready_options_without_correctness_leaks(): void {
        $this->resetAfterTest();

        [, $googlemeet, $cm, $context] = $this->create_googlemeet_fixture();
        $recordingid = $this->create_recording($googlemeet->id);
        $service = new question_service();
        $draftid = $this->create_draft_question($service, $googlemeet, $cm, $context, $recordingid,
            $this->question_data('Draft hidden from practice'));
        $readyid = $this->create_draft_question($service, $googlemeet, $cm, $context, $recordingid,
            $this->question_data('Ready practice question'));
        $rows = $service->require_questions_for_recording($googlemeet, $cm, $context, $recordingid, [$readyid]);
        $service->set_status($rows, question_version_status::QUESTION_STATUS_READY);

        $questions = $service->get_ready_practice_questions($googlemeet, $cm, $context, $recordingid);
        $this->assertCount(1, $questions);
        $this->assertEquals($readyid, $questions[0]['questionid']);
        $this->assertNotEquals($draftid, $questions[0]['questionid']);

        foreach ($questions[0]['options'] as $option) {
            $this->assertEquals(['answerid', 'text'], array_keys($option));
            $this->assertArrayNotHasKey('correct', $option);
            $this->assertArrayNotHasKey('fraction', $option);
        }
    }

    /**
     * Practice answer validation reports correctness and rejects foreign answer ids.
     *
     */
    public function test_validate_practice_answer_checks_correctness_and_rejects_foreign_answer(): void {
        $this->resetAfterTest();

        [, $googlemeet, $cm, $context] = $this->create_googlemeet_fixture();
        $recordingid = $this->create_recording($googlemeet->id);
        $service = new question_service();
        $questionid = $this->create_draft_question($service, $googlemeet, $cm, $context, $recordingid,
            $this->question_data('Ready validation question', 1));
        $otherquestionid = $this->create_draft_question($service, $googlemeet, $cm, $context, $recordingid,
            $this->question_data('Other validation question', 0));
        $rows = $service->require_questions_for_recording($googlemeet, $cm, $context, $recordingid,
            [$questionid, $otherquestionid]);
        $service->set_status($rows, question_version_status::QUESTION_STATUS_READY);
        $answerids = $this->get_answer_ids($questionid);
        $otheranswerids = $this->get_answer_ids($otherquestionid);

        $correct = $service->validate_practice_answer($googlemeet, $cm, $context, $recordingid, $questionid, $answerids[1]);
        $this->assertTrue($correct['correct']);
        $this->assertEquals($answerids[1], $correct['correctanswerid']);
        $this->assertNotEmpty(trim(html_to_text($correct['explanation'], 0, false)));

        $wrong = $service->validate_practice_answer($googlemeet, $cm, $context, $recordingid, $questionid, $answerids[0]);
        $this->assertFalse($wrong['correct']);
        $this->assertEquals($answerids[1], $wrong['correctanswerid']);

        $this->expectException(\moodle_exception::class);
        $service->validate_practice_answer($googlemeet, $cm, $context, $recordingid, $questionid, $otheranswerids[0]);
    }
}
