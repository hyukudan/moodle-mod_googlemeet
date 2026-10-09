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
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Test wrapper exposing the Drive recording filter of the client.
 */
class backup_restore_testable_client extends client {
    /**
     * Expose filter_recordings_for_activity() for unit tests.
     *
     * @param array $recordings Drive recordings.
     * @param string $meetingcode Meeting code.
     * @param string $activityname Activity name.
     * @param int $googlemeetid Activity id.
     * @return array
     */
    public function filter_for_test(array $recordings, string $meetingcode, string $activityname, int $googlemeetid): array {
        return $this->filter_recordings_for_activity($recordings, $meetingcode, $activityname, $googlemeetid);
    }
}

/**
 * Backup/restore and duplication of the AI practice questions (DAT-02) and restore coherence (DAT-03).
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\restore_googlemeet_activity_structure_step::class)]
#[CoversClass(\backup_googlemeet_activity_task::class)]
#[CoversClass(question_service::class)]
final class backup_restore_test extends \advanced_testcase {

    /**
     * Build a course with one googlemeet, three recordings (one in the trash), questions and events.
     *
     * Recording A: 2 questions (1 ready, 1 draft). Recording B: 1 ready question.
     * Recording C (trashed, not backed up): 1 draft question.
     *
     * @return array
     */
    private function create_fixture(): array {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['shortname' => 'srccourse']);
        $googlemeet = $this->getDataGenerator()->create_module('googlemeet', [
            'course' => $course->id,
            'name' => 'Practice room',
            'url' => 'https://meet.google.com/abc-defg-hij',
        ]);
        $DB->update_record('googlemeet', (object)[
            'id' => $googlemeet->id,
            'eventid' => 'ORIGINAL_GOOGLE_EVENT',
            'lastsync' => time() - 100,
            'creatoremail' => 'teacher@example.com',
        ]);
        $googlemeet = $DB->get_record('googlemeet', ['id' => $googlemeet->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('googlemeet', $googlemeet->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        $reca = $this->create_recording($googlemeet->id, 'drive-A');
        $recb = $this->create_recording($googlemeet->id, 'drive-B');
        $recc = $this->create_recording($googlemeet->id, 'drive-C', 1);

        $service = new question_service();
        $qa1 = $service->create_draft_multichoice($googlemeet, $cm, $context, $reca, $this->question_data('A one'));
        $qa2 = $service->create_draft_multichoice($googlemeet, $cm, $context, $reca, $this->question_data('A two'));
        $qb1 = $service->create_draft_multichoice($googlemeet, $cm, $context, $recb, $this->question_data('B one'));
        $service->create_draft_multichoice($googlemeet, $cm, $context, $recc, $this->question_data('C one'));
        // Publish directly (the optional local_questions leak gate is not under test here).
        foreach ([$qa1, $qb1] as $questionid) {
            $DB->set_field('question_versions', 'status', question_version_status::QUESTION_STATUS_READY,
                ['questionid' => $questionid]);
        }

        // Events: one past session already auto-synced, one future session wrongly flagged as synced.
        $DB->delete_records('googlemeet_events', ['googlemeetid' => $googlemeet->id]);
        $DB->insert_record('googlemeet_events', (object)[
            'googlemeetid' => $googlemeet->id, 'eventdate' => time() - 10 * DAYSECS, 'duration' => 3600,
            'timemodified' => time(), 'autosynced' => time() - 9 * DAYSECS, 'syncattempts' => 1, 'nextsyncattempt' => 0,
        ]);
        $DB->insert_record('googlemeet_events', (object)[
            'googlemeetid' => $googlemeet->id, 'eventdate' => time() + 10 * DAYSECS, 'duration' => 3600,
            'timemodified' => time(), 'autosynced' => time(), 'syncattempts' => 3, 'nextsyncattempt' => time() + 60,
        ]);

        return [$course, $googlemeet, $cm, $context, ['A' => $reca, 'B' => $recb, 'C' => $recc]];
    }

    /**
     * Insert a recording.
     *
     * @param int $googlemeetid Activity id.
     * @param string $driveid Drive recording id.
     * @param int $deleted Soft-deleted flag.
     * @return int
     */
    private function create_recording(int $googlemeetid, string $driveid, int $deleted = 0): int {
        global $DB;

        return (int)$DB->insert_record('googlemeet_recordings', (object)[
            'googlemeetid' => $googlemeetid,
            'recordingid' => $driveid,
            'name' => 'Practice room ' . $driveid,
            'createdtime' => time(),
            'duration' => '00:05:00',
            'webviewlink' => 'https://drive.google.com/file/d/' . $driveid . '/view',
            'visible' => 1,
            'deleted' => $deleted,
            'timedeleted' => $deleted ? time() : 0,
            'timemodified' => time(),
        ]);
    }

    /**
     * Generated multichoice payload.
     *
     * @param string $stem Stem.
     * @return array
     */
    private function question_data(string $stem): array {
        return [
            'stem' => $stem,
            'options' => ['One', 'Two', 'Three', 'Four'],
            'correctindex' => 1,
            'explanation' => 'Explanation.',
            'citation' => '00:01:05',
        ];
    }

    /**
     * Assert the copy's question bank is bound to its own cmid and recordings.
     *
     * @param \stdClass $newgooglemeet Restored activity record.
     */
    private function assert_copy_bound(\stdClass $newgooglemeet): void {
        global $DB;

        $newcm = get_coursemodule_from_instance('googlemeet', $newgooglemeet->id, 0, false, MUST_EXIST);
        $newcontext = \context_module::instance($newcm->id);
        $service = new question_service();

        // Category idnumber follows the new cmid.
        $category = $service->get_category($newgooglemeet, $newcm, $newcontext, false);
        $this->assertNotNull($category);
        $this->assertEquals(question_service::category_idnumber((int)$newcm->id), $category->idnumber);
        $this->assertEquals($newcontext->id, $category->contextid);

        $newa = (int)$DB->get_field('googlemeet_recordings', 'id', ['googlemeetid' => $newgooglemeet->id, 'recordingid' => 'drive-A']);
        $newb = (int)$DB->get_field('googlemeet_recordings', 'id', ['googlemeetid' => $newgooglemeet->id, 'recordingid' => 'drive-B']);
        $this->assertGreaterThan(0, $newa);
        $this->assertGreaterThan(0, $newb);
        // The trashed recording is not part of the backup.
        $this->assertFalse($DB->record_exists('googlemeet_recordings',
            ['googlemeetid' => $newgooglemeet->id, 'recordingid' => 'drive-C']));

        // Questions travel with their status and are bound to the NEW recording ids.
        $questionsa = $service->get_questions($newgooglemeet, $newcm, $newcontext, $newa);
        $this->assertCount(2, $questionsa);
        $this->assertCount(1, $service->get_questions($newgooglemeet, $newcm, $newcontext, $newa, true));
        $this->assertCount(1, $service->get_ready_practice_questions($newgooglemeet, $newcm, $newcontext, $newb));

        // The question of the trashed recording is kept but parked under an orphan tag.
        $orphans = $DB->count_records_sql(
            "SELECT COUNT(1)
               FROM {tag_instance} ti
               JOIN {tag} t ON t.id = ti.tagid
              WHERE ti.component = 'core_question' AND ti.itemtype = 'question'
                AND ti.contextid = ? AND " . $DB->sql_like('t.name', '?'),
            [$newcontext->id, question_service::ORPHAN_TAG_PREFIX . '%']);
        $this->assertEquals(1, $orphans);

        // DAT-03: the copy never points at the original's Google Calendar event nor its sync state.
        $this->assertNull($newgooglemeet->eventid);
        $this->assertNull($newgooglemeet->lastsync);
        $this->assertEquals('teacher@example.com', $newgooglemeet->creatoremail);
        $this->assertEquals('https://meet.google.com/abc-defg-hij', $newgooglemeet->url);
    }

    /**
     * Assert the source activity was not modified by the copy.
     *
     * @param \stdClass $googlemeet Source activity.
     * @param \stdClass $cm Source cm.
     * @param \context_module $context Source context.
     * @param array $recs Source recording ids.
     */
    private function assert_source_untouched(\stdClass $googlemeet, \stdClass $cm, \context_module $context, array $recs): void {
        $service = new question_service();
        $category = $service->get_category($googlemeet, $cm, $context, false);
        $this->assertEquals(question_service::category_idnumber((int)$cm->id), $category->idnumber);
        $this->assertCount(2, $service->get_questions($googlemeet, $cm, $context, $recs['A']));
        $this->assertCount(1, $service->get_questions($googlemeet, $cm, $context, $recs['B']));
        $this->assertCount(1, $service->get_questions($googlemeet, $cm, $context, $recs['C']));
    }

    /**
     * The module declares that it uses (but does not publish) questions.
     */
    public function test_supports_private_question_bank(): void {
        $this->assertTrue((bool)googlemeet_supports(FEATURE_USES_QUESTIONS));
        $this->assertEmpty(googlemeet_supports(FEATURE_PUBLISHES_QUESTIONS));
    }

    /**
     * Duplicating the activity copies its questions and rebinds them to the copy.
     */
    public function test_duplicate_module_keeps_questions(): void {
        global $DB;

        [$course, $googlemeet, $cm, $context, $recs] = $this->create_fixture();

        $newcm = duplicate_module($course, get_fast_modinfo($course)->get_cm($cm->id));
        $newgooglemeet = $DB->get_record('googlemeet', ['id' => $newcm->instance], '*', MUST_EXIST);

        $this->assert_copy_bound($newgooglemeet);
        $this->assert_source_untouched($googlemeet, $cm, $context, $recs);

        // Questions were copied, not shared.
        $newcontext = \context_module::instance($newcm->id);
        $this->assertNotEquals($context->id, $newcontext->id);
        $this->assertEquals(4, $DB->count_records_sql(
            "SELECT COUNT(1)
               FROM {question_bank_entries} qbe
               JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
              WHERE qc.contextid = ?", [$newcontext->id]));
    }

    /**
     * A full course backup restored as a new course keeps questions and resets Google state.
     */
    public function test_course_backup_restore_new_course(): void {
        global $DB, $USER;

        [$course, $googlemeet, $cm, $context, $recs] = $this->create_fixture();

        $bc = new \backup_controller(\backup::TYPE_1COURSE, $course->id, \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO, \backup::MODE_GENERAL, $USER->id);
        $bc->execute_plan();
        $file = $bc->get_results()['backup_destination'];
        $bc->destroy();

        $backupdir = 'googlemeet-dat02-' . random_string(6);
        $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'),
            make_backup_temp_directory($backupdir));

        $newcourseid = \restore_dbops::create_new_course('Copy', 'dstcourse', $course->category);
        $rc = new \restore_controller($backupdir, $newcourseid, \backup::INTERACTIVE_NO, \backup::MODE_GENERAL,
            $USER->id, \backup::TARGET_NEW_COURSE);
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        $newgooglemeet = $DB->get_record('googlemeet', ['course' => $newcourseid], '*', MUST_EXIST);
        $this->assert_copy_bound($newgooglemeet);
        $this->assert_source_untouched($googlemeet, $cm, $context, $recs);

        // Auto-sync bookkeeping: past session keeps its state, future session is reset.
        $events = array_values($DB->get_records('googlemeet_events', ['googlemeetid' => $newgooglemeet->id], 'eventdate ASC'));
        $this->assertCount(2, $events);
        $this->assertNotEquals(0, (int)$events[0]->autosynced);
        $this->assertEquals(0, (int)$events[1]->autosynced);
        $this->assertEquals(0, (int)$events[1]->syncattempts);
        $this->assertEquals(0, (int)$events[1]->nextsyncattempt);
    }

    /**
     * get_category() adopts the single category of the context left with a stale cmid idnumber.
     */
    public function test_get_category_adopts_stale_idnumber(): void {
        global $DB;

        [, $googlemeet, $cm, $context] = $this->create_fixture();
        $service = new question_service();
        $category = $service->get_category($googlemeet, $cm, $context, false);
        $DB->set_field('question_categories', 'idnumber', question_service::category_idnumber(999999),
            ['id' => $category->id]);

        $adopted = $service->get_category($googlemeet, $cm, $context, false);
        $this->assertEquals($category->id, $adopted->id);
        $this->assertEquals(question_service::category_idnumber((int)$cm->id),
            $DB->get_field('question_categories', 'idnumber', ['id' => $category->id]));
    }

    /**
     * A Drive recording shared by the source and the copy is kept by the copy's sync filter.
     */
    public function test_sync_filter_keeps_recordings_shared_with_source(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $source = $this->getDataGenerator()->create_module('googlemeet', ['course' => $course->id, 'name' => 'Room']);
        $copy = $this->getDataGenerator()->create_module('googlemeet', ['course' => $course->id, 'name' => 'Room']);
        $this->create_recording($source->id, 'shared');
        $this->create_recording($copy->id, 'shared');
        $this->create_recording($source->id, 'sourceonly');

        $reflection = new \ReflectionClass(backup_restore_testable_client::class);
        /** @var backup_restore_testable_client $client */
        $client = $reflection->newInstanceWithoutConstructor();

        $drive = [
            (object)['id' => 'shared', 'name' => 'Room (2026-10-01)'],
            (object)['id' => 'sourceonly', 'name' => 'Room (2026-10-02)'],
        ];
        $ids = array_map(static fn($r) => $r->id, $client->filter_for_test($drive, 'abc-defg-hij', 'Room', (int)$copy->id));
        $this->assertEquals(['shared'], $ids);

        $ids = array_map(static fn($r) => $r->id, $client->filter_for_test($drive, 'abc-defg-hij', 'Room', (int)$source->id));
        $this->assertEquals(['shared', 'sourceonly'], $ids);
    }
}
