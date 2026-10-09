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

use core_external\external_api;
use core_question\local\bank\question_version_status;
use mod_googlemeet\local\practice_attempts;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/lib.php');
require_once($CFG->dirroot . '/mod/googlemeet/locallib.php');
require_once($CFG->dirroot . '/mod/googlemeet/classes/external.php');

/**
 * Tests for practice attempts persistence (ANA-05) and the activity-wide practice modes.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\mod_googlemeet\local\practice_attempts::class)]
#[CoversClass(\mod_googlemeet\external\get_practice_session::class)]
final class practice_attempts_test extends \advanced_testcase {

    /** @var \stdClass */
    private $course;
    /** @var \stdClass */
    private $googlemeet;
    /** @var \stdClass */
    private $cm;
    /** @var \context_module */
    private $context;
    /** @var \stdClass */
    private $student;

    /**
     * Course, activity and an enrolled student; the admin is the current user.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->course = $this->getDataGenerator()->create_course();
        $this->googlemeet = $this->getDataGenerator()->create_module('googlemeet', [
            'course' => $this->course->id,
            'name' => 'Practice room',
            'url' => 'https://meet.google.com/abc-defg-hij',
        ]);
        $this->cm = get_coursemodule_from_instance('googlemeet', $this->googlemeet->id, $this->course->id, false, MUST_EXIST);
        $this->context = \context_module::instance($this->cm->id);
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
    }

    /**
     * Insert a recording, optionally with AI topics.
     *
     * @param array $topics AI topics.
     * @param int $visible Visible flag.
     * @return int
     */
    private function create_recording(array $topics = [], int $visible = 1): int {
        global $DB;

        static $n = 0;
        $n++;
        $id = (int)$DB->insert_record('googlemeet_recordings', (object)[
            'googlemeetid' => $this->googlemeet->id,
            'recordingid' => 'drive-' . $n . '-' . random_string(6),
            'name' => 'Class ' . $n,
            'createdtime' => time() - 1000 + $n,
            'duration' => '00:10:00',
            'webviewlink' => 'https://drive.google.com/file/d/example/view',
            'visible' => $visible,
            'timemodified' => time(),
        ]);
        if ($topics) {
            $DB->insert_record('googlemeet_ai_analysis', (object)[
                'recordingid' => $id,
                'summary' => 'Summary',
                'keypoints' => json_encode(['k']),
                'topics' => json_encode($topics),
                'status' => 'completed',
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }
        return $id;
    }

    /**
     * Create a question for a recording (correct answer is index 1).
     *
     * @param int $recordingid Recording id.
     * @param bool $ready Publish it.
     * @return int
     */
    private function create_question(int $recordingid, bool $ready = true): int {
        global $DB;

        $service = new question_service();
        $questionid = $service->create_draft_multichoice($this->googlemeet, $this->cm, $this->context, $recordingid, [
            'stem' => 'Question ' . random_string(5),
            'options' => ['A', 'B', 'C', 'D'],
            'correctindex' => 1,
            'explanation' => 'Because.',
            'citation' => '00:01:00',
        ]);
        if ($ready) {
            $DB->set_field('question_versions', 'status', question_version_status::QUESTION_STATUS_READY,
                ['questionid' => $questionid]);
        }
        return $questionid;
    }

    /**
     * Answer ids of a question, in order.
     *
     * @param int $questionid Question id.
     * @return int[]
     */
    private function answer_ids(int $questionid): array {
        global $DB;
        return array_map('intval', array_keys($DB->get_records('question_answers', ['question' => $questionid], 'id ASC', 'id')));
    }

    /**
     * Answering through the WS stores one attempt with the right correctness.
     */
    public function test_check_practice_answer_records_attempt(): void {
        global $DB;

        $rec = $this->create_recording();
        $qid = $this->create_question($rec);
        $answers = $this->answer_ids($qid);

        $this->setUser($this->student);
        $result = external_api::clean_returnvalue(\mod_googlemeet_external::check_practice_answer_returns(),
            \mod_googlemeet_external::check_practice_answer($rec, $this->cm->id, $qid, $answers[0]));
        $this->assertFalse($result['correct']);
        external_api::clean_returnvalue(\mod_googlemeet_external::check_practice_answer_returns(),
            \mod_googlemeet_external::check_practice_answer($rec, $this->cm->id, $qid, $answers[1]));

        $attempts = array_values($DB->get_records(practice_attempts::TABLE, ['userid' => $this->student->id], 'id ASC'));
        $this->assertCount(2, $attempts);
        $this->assertEquals($this->googlemeet->id, $attempts[0]->googlemeetid);
        $this->assertEquals($rec, $attempts[0]->recordingid);
        $this->assertEquals($qid, $attempts[0]->questionid);
        $this->assertEquals(0, $attempts[0]->correct);
        $this->assertEquals(1, $attempts[1]->correct);
        $this->assertGreaterThan(0, $attempts[1]->timecreated);
    }

    /**
     * An invalid answer (draft question) throws and stores nothing.
     */
    public function test_invalid_answer_records_nothing(): void {
        global $DB;

        $rec = $this->create_recording();
        $draft = $this->create_question($rec, false);
        $answers = $this->answer_ids($draft);

        $this->setUser($this->student);
        try {
            \mod_googlemeet_external::check_practice_answer($rec, $this->cm->id, $draft, $answers[0]);
            $this->fail('Exception expected');
        } catch (\moodle_exception $e) {
            $this->assertEquals(0, $DB->count_records(practice_attempts::TABLE));
        }
    }

    /**
     * Failed selection uses the LATEST attempt per question and only serves published, visible questions.
     */
    public function test_failed_selection_uses_latest_attempt(): void {
        global $DB;

        $rec1 = $this->create_recording();
        $rec2 = $this->create_recording();
        $hidden = $this->create_recording([], 0);
        $q1 = $this->create_question($rec1); // Wrong then right: not failed.
        $q2 = $this->create_question($rec1); // Right then wrong: failed.
        $q3 = $this->create_question($rec2); // Wrong only: failed.
        $q4 = $this->create_question($rec2); // Wrong, later unpublished: not served.
        $q5 = $this->create_question($hidden); // Wrong on a hidden recording: not served to students.
        $q6 = $this->create_question($rec2); // Never answered.
        $uid = (int)$this->student->id;
        $gid = (int)$this->googlemeet->id;

        practice_attempts::record($gid, $rec1, $q1, $uid, false, 100);
        practice_attempts::record($gid, $rec1, $q1, $uid, true, 200);
        practice_attempts::record($gid, $rec1, $q2, $uid, true, 100);
        practice_attempts::record($gid, $rec1, $q2, $uid, false, 200);
        practice_attempts::record($gid, $rec2, $q3, $uid, false, 300);
        practice_attempts::record($gid, $rec2, $q4, $uid, false, 300);
        practice_attempts::record($gid, $hidden, $q5, $uid, false, 300);
        // Another user's failures never leak into this user's selection.
        $other = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        practice_attempts::record($gid, $rec2, $q6, (int)$other->id, false, 300);

        $refs = practice_attempts::get_failed_question_refs($gid, $uid);
        $this->assertEqualsCanonicalizing([$q2, $q3, $q4, $q5], array_keys($refs));
        $this->assertEquals($rec1, $refs[$q2]);

        $DB->set_field('question_versions', 'status', question_version_status::QUESTION_STATUS_DRAFT, ['questionid' => $q4]);

        $this->setUser($this->student);
        $questions = practice_attempts::get_session_questions($this->googlemeet, $this->cm, $this->context, $uid,
            practice_attempts::MODE_FAILED);
        $ids = array_column($questions, 'questionid');
        // Most recently failed first: q3 (attempt id later than q2's).
        $this->assertEquals([$q3, $q2], $ids);
        $this->assertEquals($rec2, $questions[0]['recordingid']);
        $this->assertArrayNotHasKey('correct', $questions[0]['options'][0]);
        $this->assertEquals(2, practice_attempts::count_failed_questions($this->googlemeet, $this->cm, $this->context, $uid));

        $cta = practice_attempts::hero_cta_context($this->googlemeet, $this->cm, $this->context, $uid);
        $this->assertTrue($cta['hasfailed']);
        $this->assertStringContainsString('(2)', $cta['failedlabel']);
    }

    /**
     * Topic mode serves questions of every visible recording sharing the topic (accent/case-insensitive).
     */
    public function test_topic_mode_and_topic_list(): void {
        $reca = $this->create_recording(['Constitución', 'Derechos']);
        $recb = $this->create_recording(['constitucion']);
        $recc = $this->create_recording(['Otro tema']);
        $recd = $this->create_recording(['Sin preguntas']);
        $qa = $this->create_question($reca);
        $qb1 = $this->create_question($recb);
        $qb2 = $this->create_question($recb);
        $this->create_question($recb, false);
        $qc = $this->create_question($recc);

        $this->setUser($this->student);
        $questions = practice_attempts::get_session_questions($this->googlemeet, $this->cm, $this->context,
            (int)$this->student->id, practice_attempts::MODE_TOPIC, 'CONSTITUCIÓN');
        $this->assertEqualsCanonicalizing([$qa, $qb1, $qb2], array_column($questions, 'questionid'));
        $this->assertNotContains($qc, array_column($questions, 'questionid'));

        $topics = practice_attempts::get_practice_topics($this->googlemeet, $this->cm, $this->context);
        $bykey = [];
        foreach ($topics as $t) {
            $bykey[googlemeet_fold($t['topic'])] = $t;
        }
        $this->assertArrayNotHasKey(googlemeet_fold('Sin preguntas'), $bykey);
        $this->assertEquals(2, $bykey[googlemeet_fold('Constitución')]['recordings']);
        $this->assertEquals(3, $bykey[googlemeet_fold('Constitución')]['questions']);
        $this->assertEquals(1, $bykey[googlemeet_fold('Derechos')]['questions']);
        $this->assertEquals(1, $bykey[googlemeet_fold('Otro tema')]['questions']);
        $this->assertEquals([$reca => 1, $recb => 2, $recc => 1],
            practice_attempts::count_ready_questions_by_recording($this->googlemeet, $this->cm, $this->context));
        unset($recd);
    }

    /**
     * The session WS returns recording-bound questions and rejects unknown modes.
     */
    public function test_get_practice_session_ws(): void {
        $rec = $this->create_recording(['Tema']);
        $qid = $this->create_question($rec);
        practice_attempts::record((int)$this->googlemeet->id, $rec, $qid, (int)$this->student->id, false);

        $this->setUser($this->student);
        $result = external_api::clean_returnvalue(external\get_practice_session::execute_returns(),
            external\get_practice_session::execute($this->cm->id, 'failed', ''));
        $this->assertCount(1, $result['questions']);
        $this->assertEquals($rec, $result['questions'][0]['recordingid']);

        $result = external_api::clean_returnvalue(external\get_practice_session::execute_returns(),
            external\get_practice_session::execute($this->cm->id, 'topic', 'tema'));
        $this->assertCount(1, $result['questions']);

        $this->expectException(\invalid_parameter_exception::class);
        external\get_practice_session::execute($this->cm->id, 'everything', '');
    }

    /**
     * Teachers get per-question stats and the most failed questions per recording.
     */
    public function test_question_stats_and_most_failed(): void {
        $rec = $this->create_recording();
        $q1 = $this->create_question($rec);
        $q2 = $this->create_question($rec);
        $gid = (int)$this->googlemeet->id;
        $u1 = (int)$this->student->id;
        $u2 = (int)$this->getDataGenerator()->create_and_enrol($this->course, 'student')->id;

        practice_attempts::record($gid, $rec, $q1, $u1, false);
        practice_attempts::record($gid, $rec, $q1, $u2, false);
        practice_attempts::record($gid, $rec, $q1, $u2, true);
        practice_attempts::record($gid, $rec, $q2, $u1, true);
        practice_attempts::record($gid, $rec, $q2, $u2, false);
        practice_attempts::record($gid, $rec, $q2, $u2, true);
        practice_attempts::record($gid, $rec, $q2, $u1, true);

        $stats = practice_attempts::get_question_stats($gid, $rec);
        $this->assertEquals(3, $stats[$q1]['attempts']);
        $this->assertEquals(2, $stats[$q1]['wrong']);
        $this->assertEquals(2, $stats[$q1]['users']);
        $this->assertEquals(33, $stats[$q1]['correctpct']);
        $this->assertEquals(75, $stats[$q2]['correctpct']);

        $failed = practice_attempts::get_most_failed_by_recording($gid, 5);
        $this->assertEquals([$q1, $q2], array_column($failed[$rec], 'questionid'));
        $this->assertStringStartsWith('Question', $failed[$rec][0]['name']);
    }
}
