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
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/lib.php');
require_once($CFG->dirroot . '/mod/googlemeet/locallib.php');
require_once($CFG->dirroot . '/mod/googlemeet/classes/external.php');

/**
 * Bulk publishing of draft questions (per recording and activity-wide).
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\mod_googlemeet\question_service::class)]
#[CoversClass(\mod_googlemeet_external::class)]
final class question_publish_test extends \advanced_testcase {

    /** @var string Stem the local_questions leak gate rejects (pattern "pendiente_tarea"). */
    private const LEAKY_STEM = '<p>Queda pendiente de verificar esta cifra con el temario.</p>';

    /**
     * Create a course with a googlemeet activity.
     *
     * @return array [course, googlemeet, cm, context]
     */
    private function create_activity(): array {
        $course = $this->getDataGenerator()->create_course();
        $googlemeet = $this->getDataGenerator()->create_module('googlemeet', [
            'course' => $course->id,
            'name' => 'Practice room',
            'url' => 'https://meet.google.com/abc-defg-hij',
        ]);
        $cm = get_coursemodule_from_instance('googlemeet', $googlemeet->id, $course->id, false, MUST_EXIST);
        return [$course, $googlemeet, $cm, \context_module::instance($cm->id)];
    }

    /**
     * Insert a recording.
     *
     * @param int $googlemeetid Activity instance id.
     * @param string $name Recording name.
     * @return int
     */
    private function create_recording(int $googlemeetid, string $name = 'Recording'): int {
        global $DB;

        $now = time();
        return (int)$DB->insert_record('googlemeet_recordings', (object) [
            'googlemeetid' => $googlemeetid,
            'recordingid' => 'drive-' . random_string(8),
            'name' => $name,
            'createdtime' => $now,
            'duration' => '00:05:00',
            'webviewlink' => 'https://drive.google.com/file/d/example/view',
            'transcripttext' => 'Transcript text.',
            'visible' => 1,
            'timemodified' => $now,
        ]);
    }

    /**
     * Create one draft question for a recording.
     *
     * @param question_service $service Service.
     * @param array $activity [course, googlemeet, cm, context].
     * @param int $recordingid Recording id.
     * @param string $stem Stem.
     * @return int
     */
    private function create_draft(question_service $service, array $activity, int $recordingid,
            string $stem = 'Which law regulates the national health system?'): int {
        [, $googlemeet, $cm, $context] = $activity;
        return $service->create_draft_multichoice($googlemeet, $cm, $context, $recordingid, [
            'stem' => $stem,
            'options' => ['Law 14/1986', 'Law 16/2003', 'Law 41/2002', 'Law 55/2003'],
            'correctindex' => 0,
            'explanation' => 'It is the general health law.',
            'citation' => '00:01:05',
        ]);
    }

    /**
     * Current status of a question version.
     *
     * @param int $questionid Question id.
     * @return string
     */
    private function status_of(int $questionid): string {
        global $DB;
        return (string)$DB->get_field('question_versions', 'status', ['questionid' => $questionid], MUST_EXIST);
    }

    /**
     * Whether the real local_questions leak gate is installed.
     *
     * @return bool
     */
    private function leak_gate_installed(): bool {
        global $CFG;
        $path = $CFG->dirroot . '/local/questions/fugaslib.php';
        if (file_exists($path)) {
            require_once($path);
        }
        return function_exists('fugas_campos_pregunta') && function_exists('fugas_exigir_limpia');
    }

    /**
     * Activity-wide publish only touches drafts of this activity's live recordings.
     */
    public function test_publish_activity_drafts_only_publishes_drafts_of_this_activity(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $service = new question_service();

        $activity = $this->create_activity();
        [, $googlemeet, $cm, $context] = $activity;
        $reca = $this->create_recording($googlemeet->id, 'Class A');
        $recb = $this->create_recording($googlemeet->id, 'Class B');
        $recdeleted = $this->create_recording($googlemeet->id, 'Trashed class');

        $a1 = $this->create_draft($service, $activity, $reca);
        $a2 = $this->create_draft($service, $activity, $reca);
        $b1 = $this->create_draft($service, $activity, $recb);
        $ready = $this->create_draft($service, $activity, $recb);
        $service->set_status($service->require_questions_for_recording($googlemeet, $cm, $context, $recb, [$ready]),
            question_version_status::QUESTION_STATUS_READY);
        $trashed = $this->create_draft($service, $activity, $recdeleted);
        $DB->set_field('googlemeet_recordings', 'deleted', 1, ['id' => $recdeleted]);

        // Another activity in the same course with its own draft.
        $other = $this->create_activity();
        $otherrec = $this->create_recording($other[1]->id, 'Other class');
        $foreign = $this->create_draft($service, $other, $otherrec);

        $this->assertSame(3, $service->count_activity_drafts($googlemeet, $cm, $context));

        $outcome = $service->publish_activity_drafts($googlemeet, $cm, $context);

        $this->assertSame(3, $outcome['published']);
        $this->assertSame(0, $outcome['failed']);
        $this->assertCount(2, $outcome['results']);
        $byrecording = array_column($outcome['results'], null, 'recordingid');
        $this->assertSame(2, $byrecording[$reca]['published']);
        $this->assertSame(1, $byrecording[$recb]['published']);
        $this->assertTrue($byrecording[$reca]['success']);

        foreach ([$a1, $a2, $b1, $ready] as $id) {
            $this->assertSame(question_version_status::QUESTION_STATUS_READY, $this->status_of($id));
        }
        $this->assertSame(question_version_status::QUESTION_STATUS_DRAFT, $this->status_of($trashed));
        $this->assertSame(question_version_status::QUESTION_STATUS_DRAFT, $this->status_of($foreign));
        $this->assertSame(0, $service->count_activity_drafts($googlemeet, $cm, $context));
    }

    /**
     * A leak-gate failure keeps that recording's whole batch in draft and reports it,
     * while the other recordings are still published.
     */
    public function test_publish_activity_drafts_leak_failure_is_all_or_nothing_per_recording(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $activity = $this->create_activity();
        [, $googlemeet, $cm, $context] = $activity;
        $reca = $this->create_recording($googlemeet->id, 'Leaky class');
        $recb = $this->create_recording($googlemeet->id, 'Clean class');

        $plain = new question_service();
        $a1 = $this->create_draft($plain, $activity, $reca);
        $aleak = $this->create_draft($plain, $activity, $reca);
        $b1 = $this->create_draft($plain, $activity, $recb);

        // Substitute the gate: reject the batch that contains $aleak, as fugas_exigir_limpia() would.
        $service = new class($aleak) extends question_service {
            /** @var int Question the fake gate rejects. */
            private int $leakid;

            /**
             * Constructor.
             *
             * @param int $leakid Question the fake gate rejects.
             */
            public function __construct(int $leakid) {
                $this->leakid = $leakid;
            }

            /**
             * Fake leak gate.
             *
             * @param array $questionrows Rows.
             * @return void
             */
            protected function assert_publishable(array $questionrows): void {
                if (isset($questionrows[$this->leakid])) {
                    throw new \moodle_exception('generalexceptionmessage', 'error', '', 'internal note found');
                }
            }
        };

        $outcome = $service->publish_activity_drafts($googlemeet, $cm, $context);

        $this->assertSame(1, $outcome['published']);
        $this->assertSame(2, $outcome['failed']);
        $byrecording = array_column($outcome['results'], null, 'recordingid');
        $this->assertFalse($byrecording[$reca]['success']);
        $this->assertSame(0, $byrecording[$reca]['published']);
        $this->assertSame(2, $byrecording[$reca]['count']);
        $this->assertStringContainsString('internal note found', $byrecording[$reca]['error']);
        $this->assertTrue($byrecording[$recb]['success']);

        $this->assertSame(question_version_status::QUESTION_STATUS_DRAFT, $this->status_of($a1));
        $this->assertSame(question_version_status::QUESTION_STATUS_DRAFT, $this->status_of($aleak));
        $this->assertSame(question_version_status::QUESTION_STATUS_READY, $this->status_of($b1));
    }

    /**
     * With the real local_questions gate, the per-recording publish WS keeps the whole
     * selection in draft when one question leaks, and the activity WS reports the failure.
     */
    public function test_ws_publish_with_real_leak_gate(): void {
        global $DB;

        if (!$this->leak_gate_installed()) {
            $this->markTestSkipped('local_questions leak gate (fugaslib.php) not installed.');
        }
        $this->resetAfterTest();
        $this->setAdminUser();
        $service = new question_service();

        $activity = $this->create_activity();
        [, $googlemeet, $cm] = $activity;
        $reca = $this->create_recording($googlemeet->id, 'Leaky class');
        $recb = $this->create_recording($googlemeet->id, 'Clean class');
        $clean = $this->create_draft($service, $activity, $reca);
        $leaky = $this->create_draft($service, $activity, $reca);
        $DB->set_field('question', 'questiontext', self::LEAKY_STEM, ['id' => $leaky]);
        \question_bank::notify_question_edited($leaky);
        $b1 = $this->create_draft($service, $activity, $recb);

        try {
            \mod_googlemeet_external::publish_questions($reca, $cm->id, [$clean, $leaky], sesskey());
            $this->fail('A leaky question must block the batch.');
        } catch (\moodle_exception $e) {
            $this->assertSame(question_version_status::QUESTION_STATUS_DRAFT, $this->status_of($clean));
            $this->assertSame(question_version_status::QUESTION_STATUS_DRAFT, $this->status_of($leaky));
        }

        $result = external_api::clean_returnvalue(
            \mod_googlemeet_external::publish_activity_drafts_returns(),
            \mod_googlemeet_external::publish_activity_drafts($cm->id, sesskey())
        );
        $this->assertFalse($result['success']);
        $this->assertSame(1, $result['published']);
        $this->assertSame(2, $result['failed']);
        $this->assertSame(question_version_status::QUESTION_STATUS_DRAFT, $this->status_of($clean));
        $this->assertSame(question_version_status::QUESTION_STATUS_READY, $this->status_of($b1));
    }

    /**
     * The activity WS requires managequestions and a valid sesskey; teachers can use it.
     */
    public function test_ws_publish_activity_drafts_capabilities(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $service = new question_service();

        $activity = $this->create_activity();
        [$course, $googlemeet, $cm] = $activity;
        $rec = $this->create_recording($googlemeet->id, 'Class');
        $draft = $this->create_draft($service, $activity, $rec);

        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $this->setUser($student);
        try {
            \mod_googlemeet_external::publish_activity_drafts($cm->id, sesskey());
            $this->fail('Students must not publish questions.');
        } catch (\required_capability_exception $e) {
            $this->assertSame(question_version_status::QUESTION_STATUS_DRAFT, $this->status_of($draft));
        }

        $this->setUser($teacher);
        try {
            \mod_googlemeet_external::publish_activity_drafts($cm->id, 'not-the-sesskey');
            $this->fail('An invalid sesskey must be rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidsesskey', $e->errorcode);
        }

        $result = external_api::clean_returnvalue(
            \mod_googlemeet_external::publish_activity_drafts_returns(),
            \mod_googlemeet_external::publish_activity_drafts($cm->id, sesskey())
        );
        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['published']);
        $this->assertSame(question_version_status::QUESTION_STATUS_READY, $this->status_of($draft));

        // Nothing left: a second run is a no-op.
        $again = \mod_googlemeet_external::publish_activity_drafts($cm->id, sesskey());
        $this->assertTrue($again['success']);
        $this->assertSame(0, $again['published']);
        $this->assertSame([], $again['results']);
    }

    /**
     * The per-recording publish WS (used by "Publicar seleccionadas" / "Publicar todas las de
     * esta clase") rejects students and questions of another recording.
     */
    public function test_ws_publish_questions_scope_and_capability(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $service = new question_service();

        $activity = $this->create_activity();
        [$course, $googlemeet, $cm] = $activity;
        $reca = $this->create_recording($googlemeet->id, 'Class A');
        $recb = $this->create_recording($googlemeet->id, 'Class B');
        $a1 = $this->create_draft($service, $activity, $reca);
        $a2 = $this->create_draft($service, $activity, $reca);
        $b1 = $this->create_draft($service, $activity, $recb);

        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $this->setUser($student);
        try {
            \mod_googlemeet_external::publish_questions($reca, $cm->id, [$a1], sesskey());
            $this->fail('Students must not publish questions.');
        } catch (\required_capability_exception $e) {
            $this->assertSame(question_version_status::QUESTION_STATUS_DRAFT, $this->status_of($a1));
        }

        $this->setUser($teacher);
        try {
            \mod_googlemeet_external::publish_questions($reca, $cm->id, [$a1, $b1], sesskey());
            $this->fail('A question of another recording must reject the batch.');
        } catch (\moodle_exception $e) {
            $this->assertSame(question_version_status::QUESTION_STATUS_DRAFT, $this->status_of($a1));
            $this->assertSame(question_version_status::QUESTION_STATUS_DRAFT, $this->status_of($b1));
        }

        $result = \mod_googlemeet_external::publish_questions($reca, $cm->id, [$a1, $a2], sesskey());
        $this->assertSame(2, $result['count']);
        $this->assertSame(question_version_status::QUESTION_STATUS_READY, $this->status_of($a1));
        $this->assertSame(question_version_status::QUESTION_STATUS_READY, $this->status_of($a2));
        $this->assertSame(question_version_status::QUESTION_STATUS_DRAFT, $this->status_of($b1));
    }
}
