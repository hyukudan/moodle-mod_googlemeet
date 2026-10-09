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
use mod_googlemeet\local\recording_cleanup;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/lib.php');
require_once($CFG->dirroot . '/mod/googlemeet/locallib.php');
require_once($CFG->dirroot . '/mod/googlemeet/classes/external.php');
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Central cascade delete (DAT-01), trash retention (OPS-03), orphan cleanup and course reset.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(recording_cleanup::class)]
#[CoversClass(task\purge_trash::class)]
final class delete_cascade_test extends \advanced_testcase {

    /**
     * Course + activity.
     *
     * @return array [course, googlemeet, cm, context]
     */
    private function create_activity(): array {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $googlemeet = $this->getDataGenerator()->create_module('googlemeet', [
            'course' => $course->id,
            'name' => 'Cascade room',
        ]);
        $cm = get_coursemodule_from_instance('googlemeet', $googlemeet->id, $course->id, false, MUST_EXIST);
        return [$course, $googlemeet, $cm, \context_module::instance($cm->id)];
    }

    /**
     * Insert a recording.
     *
     * @param int $googlemeetid Activity id.
     * @param array $overrides Field overrides.
     * @return int
     */
    private function create_recording(int $googlemeetid, array $overrides = []): int {
        global $DB;
        static $n = 0;
        $n++;
        return (int)$DB->insert_record('googlemeet_recordings', (object)array_merge([
            'googlemeetid' => $googlemeetid,
            'recordingid' => 'drive-' . $n,
            'name' => 'Recording ' . $n,
            'createdtime' => time() - 100,
            'duration' => '0:05:00',
            'webviewlink' => 'https://drive.google.com/file/d/drive-' . $n . '/view',
            'visible' => 1,
            'deleted' => 0,
            'timedeleted' => 0,
            'timemodified' => time(),
        ], $overrides));
    }

    /**
     * Add every kind of dependent to a recording.
     *
     * @param \stdClass $googlemeet Activity.
     * @param \stdClass $cm Course module.
     * @param \context_module $context Context.
     * @param int $recordingid Recording id.
     * @param int $userid Student id.
     * @return int[] Question ids [free, used in a quiz].
     */
    private function add_dependents(\stdClass $googlemeet, \stdClass $cm, \context_module $context,
            int $recordingid, int $userid): array {
        global $DB;

        $DB->insert_record('googlemeet_ai_analysis', (object)[
            'recordingid' => $recordingid, 'summary' => 'Summary', 'transcript' => 'Transcript',
            'status' => 'completed', 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $DB->insert_record('googlemeet_recording_progress', (object)[
            'recordingid' => $recordingid, 'userid' => $userid, 'watchedseconds' => 60, 'completed' => 1,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        set_user_preference(googlemeet_lastjump_preference_name($recordingid), 120, $userid);
        set_user_preference(googlemeet_keypoints_preference_name($recordingid), '1a2b3c4d:10', $userid);
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id, 'component' => 'mod_googlemeet', 'filearea' => 'recordingmaterial',
            'itemid' => $recordingid, 'filepath' => '/', 'filename' => 'notes-' . $recordingid . '.txt',
        ], 'material');

        $service = new question_service();
        $data = ['options' => ['One', 'Two', 'Three', 'Four'], 'correctindex' => 0, 'explanation' => 'E', 'citation' => ''];
        $free = $service->create_draft_multichoice($googlemeet, $cm, $context, $recordingid, ['stem' => 'Free'] + $data);
        $used = $service->create_draft_multichoice($googlemeet, $cm, $context, $recordingid, ['stem' => 'Used'] + $data);
        foreach ([$free, $used] as $questionid) {
            $DB->set_field('question_versions', 'status', question_version_status::QUESTION_STATUS_READY,
                ['questionid' => $questionid]);
        }
        // Simulate the question being used by a quiz slot.
        $qv = $DB->get_record('question_versions', ['questionid' => $used], 'questionbankentryid, version', MUST_EXIST);
        $DB->insert_record('question_references', (object)[
            'usingcontextid' => \context_course::instance($googlemeet->course)->id,
            'component' => 'mod_quiz', 'questionarea' => 'slot', 'itemid' => 1,
            'questionbankentryid' => $qv->questionbankentryid, 'version' => $qv->version,
        ]);
        $DB->insert_record('googlemeet_practice_attempts', (object)[
            'googlemeetid' => $googlemeet->id, 'recordingid' => $recordingid, 'questionid' => $free,
            'userid' => $userid, 'correct' => 1, 'timecreated' => time(),
        ]);
        return [$free, $used];
    }

    /**
     * Count every dependent of one recording.
     *
     * @param int $recordingid Recording id.
     * @return array
     */
    private function count_dependents(int $recordingid): array {
        global $DB;
        return [
            'analysis' => $DB->count_records('googlemeet_ai_analysis', ['recordingid' => $recordingid]),
            'progress' => $DB->count_records('googlemeet_recording_progress', ['recordingid' => $recordingid]),
            'attempts' => $DB->count_records('googlemeet_practice_attempts', ['recordingid' => $recordingid]),
            'files' => $DB->count_records_select('files',
                "component = 'mod_googlemeet' AND filearea = 'recordingmaterial' AND itemid = ? AND filename <> '.'",
                [$recordingid]),
            'preferences' => $DB->count_records_list('user_preferences', 'name', [
                googlemeet_lastjump_preference_name($recordingid), googlemeet_keypoints_preference_name($recordingid)]),
            'tagged' => $DB->count_records_sql(
                "SELECT COUNT(1) FROM {tag_instance} ti JOIN {tag} t ON t.id = ti.tagid
                  WHERE ti.component = 'core_question' AND t.name = ?",
                [question_service::tag_for_recording($recordingid)]),
        ];
    }

    /**
     * Purging one trashed recording leaves no dependents and hides (not deletes) a question used in a quiz.
     */
    public function test_purge_recording_cascade(): void {
        global $DB;
        $this->resetAfterTest();
        [, $googlemeet, $cm, $context] = $this->create_activity();
        $student = $this->getDataGenerator()->create_user();

        $purged = $this->create_recording($googlemeet->id, ['deleted' => 1, 'timedeleted' => time()]);
        $kept = $this->create_recording($googlemeet->id);
        [$free, $used] = $this->add_dependents($googlemeet, $cm, $context, $purged, $student->id);
        $this->add_dependents($googlemeet, $cm, $context, $kept, $student->id);

        $result = \mod_googlemeet_external::purge_recording($purged, $cm->id);
        $this->assertTrue($result['success']);

        $this->assertFalse($DB->record_exists('googlemeet_recordings', ['id' => $purged]));
        $this->assertSame(array_fill_keys(['analysis', 'progress', 'attempts', 'files', 'preferences', 'tagged'], 0),
            $this->count_dependents($purged));
        $this->assertFalse($DB->record_exists('question', ['id' => $free]));
        $this->assertTrue($DB->record_exists('question', ['id' => $used]));
        $this->assertEquals(question_version_status::QUESTION_STATUS_HIDDEN,
            $DB->get_field('question_versions', 'status', ['questionid' => $used]));

        // The other recording is untouched.
        $this->assertTrue($DB->record_exists('googlemeet_recordings', ['id' => $kept]));
        $this->assertSame(['analysis' => 1, 'progress' => 1, 'attempts' => 1, 'files' => 1, 'preferences' => 2, 'tagged' => 2],
            $this->count_dependents($kept));
    }

    /**
     * delete_all_recordings uses the cascade too.
     */
    public function test_delete_all_recordings_cascade(): void {
        global $DB;
        $this->resetAfterTest();
        [, $googlemeet, $cm, $context] = $this->create_activity();
        $student = $this->getDataGenerator()->create_user();
        $a = $this->create_recording($googlemeet->id);
        $b = $this->create_recording($googlemeet->id, ['deleted' => 1, 'timedeleted' => time()]);
        $this->add_dependents($googlemeet, $cm, $context, $a, $student->id);
        $this->add_dependents($googlemeet, $cm, $context, $b, $student->id);

        \mod_googlemeet_external::delete_all_recordings($googlemeet->id, $cm->id);

        $this->assertEquals(0, $DB->count_records('googlemeet_recordings', ['googlemeetid' => $googlemeet->id]));
        foreach ([$a, $b] as $id) {
            $counts = $this->count_dependents($id);
            $this->assertSame(0, array_sum($counts), json_encode($counts));
        }
    }

    /**
     * Deleting the activity removes recordings and their per-recording data (progress included).
     */
    public function test_delete_instance_cascade(): void {
        global $DB;
        $this->resetAfterTest();
        [, $googlemeet, $cm, $context] = $this->create_activity();
        $student = $this->getDataGenerator()->create_user();
        $a = $this->create_recording($googlemeet->id);
        $this->add_dependents($googlemeet, $cm, $context, $a, $student->id);

        course_delete_module($cm->id);

        $this->assertFalse($DB->record_exists('googlemeet', ['id' => $googlemeet->id]));
        $this->assertFalse($DB->record_exists('googlemeet_recordings', ['id' => $a]));
        $counts = $this->count_dependents($a);
        unset($counts['tagged']); // The module question bank is handled by core.
        $this->assertSame(0, array_sum($counts), json_encode($counts));
    }

    /**
     * The orphan scan lists data of gone recordings without deleting it; delete_orphans() removes it.
     */
    public function test_find_and_delete_orphans(): void {
        global $DB;
        $this->resetAfterTest();
        [, $googlemeet, $cm, $context] = $this->create_activity();
        $student = $this->getDataGenerator()->create_user();
        $gone = $this->create_recording($googlemeet->id);
        $alive = $this->create_recording($googlemeet->id);
        [$free, $used] = $this->add_dependents($googlemeet, $cm, $context, $gone, $student->id);
        $this->add_dependents($googlemeet, $cm, $context, $alive, $student->id);
        // Legacy bug: the recording row went away without its dependents.
        $DB->delete_records('googlemeet_recordings', ['id' => $gone]);
        // Rows of an activity that no longer exists.
        $DB->insert_record('googlemeet_recording_subs', (object)['googlemeetid' => 987654, 'userid' => $student->id,
            'timecreated' => time()]);
        $DB->insert_record('googlemeet_recordings', (object)['googlemeetid' => 987654, 'recordingid' => 'x',
            'name' => 'Ghost', 'duration' => '1:00', 'webviewlink' => 'x', 'timemodified' => time()]);

        $orphans = recording_cleanup::find_orphans();
        $this->assertCount(1, $orphans['analysis']);
        $this->assertCount(1, $orphans['progress']);
        $this->assertCount(1, $orphans['attempts']);
        $this->assertCount(1, $orphans['subscriptions']);
        $this->assertCount(1, $orphans['recordings']);
        $this->assertCount(2, $orphans['preferences']);
        $this->assertEqualsCanonicalizing([$free, $used], array_keys($orphans['questions']));
        $this->assertNotEmpty($orphans['files']);
        // Dry run: nothing deleted.
        $this->assertEquals(1, $this->count_dependents($gone)['analysis']);

        $result = recording_cleanup::delete_orphans($orphans);
        $this->assertEquals(1, $result['questions']);
        $this->assertEquals(1, $result['questionshidden']);
        $this->assertEquals(1, $result['files']);
        $this->assertSame(0, array_sum($this->count_dependents($gone)));

        // Idempotent: nothing left, and live data was not touched.
        $this->assertSame(0, array_sum(array_map('count', recording_cleanup::find_orphans())));
        $this->assertSame(['analysis' => 1, 'progress' => 1, 'attempts' => 1, 'files' => 1, 'preferences' => 2, 'tagged' => 2],
            $this->count_dependents($alive));
    }

    /**
     * Recordings older than the retention period are purged, newer ones and active ones stay.
     */
    public function test_purge_expired_trash(): void {
        global $DB;
        $this->resetAfterTest();
        [, $googlemeet, $cm, $context] = $this->create_activity();
        $student = $this->getDataGenerator()->create_user();
        $now = time();
        $old = $this->create_recording($googlemeet->id, ['deleted' => 1, 'timedeleted' => $now - 31 * DAYSECS]);
        $recent = $this->create_recording($googlemeet->id, ['deleted' => 1, 'timedeleted' => $now - 10 * DAYSECS]);
        $legacy = $this->create_recording($googlemeet->id, ['deleted' => 1, 'timedeleted' => 0]);
        $active = $this->create_recording($googlemeet->id, ['createdtime' => $now - 100 * DAYSECS]);
        $this->add_dependents($googlemeet, $cm, $context, $old, $student->id);

        // Retention disabled: nothing happens.
        set_config('trashretentiondays', 0, 'googlemeet');
        $this->assertSame(0, recording_cleanup::purge_expired_trash($now)['recordings']);
        $this->assertTrue($DB->record_exists('googlemeet_recordings', ['id' => $old]));

        // Default (setting never saved) is 30 days.
        unset_config('trashretentiondays', 'googlemeet');
        $this->assertSame(30, recording_cleanup::get_retention_days());
        $stats = recording_cleanup::purge_expired_trash($now);
        $this->assertSame(1, $stats['recordings']);
        $this->assertFalse($DB->record_exists('googlemeet_recordings', ['id' => $old]));
        $this->assertSame(0, array_sum($this->count_dependents($old)));
        $this->assertTrue($DB->record_exists('googlemeet_recordings', ['id' => $recent]));
        $this->assertTrue($DB->record_exists('googlemeet_recordings', ['id' => $active]));
        // A trashed row without timestamp starts its clock now instead of being purged.
        $this->assertEquals($now, (int)$DB->get_field('googlemeet_recordings', 'timedeleted', ['id' => $legacy]));

        // The scheduled task uses the same path.
        set_config('trashretentiondays', 5, 'googlemeet');
        ob_start();
        (new task\purge_trash())->execute();
        ob_end_clean();
        $this->assertFalse($DB->record_exists('googlemeet_recordings', ['id' => $recent]));
        $this->assertTrue($DB->record_exists('googlemeet_recordings', ['id' => $legacy]));
        $this->assertTrue($DB->record_exists('googlemeet_recordings', ['id' => $active]));
    }

    /**
     * Teacher notice in the trash view.
     */
    public function test_purge_notice(): void {
        $this->resetAfterTest();
        $now = make_timestamp(2026, 10, 9, 12);
        $this->assertSame('', recording_cleanup::purge_notice($now, 0, $now));
        $expected = get_string('recordings_trash_purgeon', 'googlemeet',
            userdate($now + 30 * DAYSECS, get_string('trash_purge_dateformat', 'googlemeet')));
        $this->assertSame($expected, recording_cleanup::purge_notice($now, 30, $now));
        $this->assertStringContainsString('08/11', $expected);
        $this->assertSame(get_string('recordings_trash_purgesoon', 'googlemeet'),
            recording_cleanup::purge_notice($now - 40 * DAYSECS, 30, $now));
    }

    /**
     * Course reset removes student data and keeps the course content.
     */
    public function test_reset_userdata(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $googlemeet, $cm, $context] = $this->create_activity();
        $student = $this->getDataGenerator()->create_user();
        $a = $this->create_recording($googlemeet->id);
        $this->add_dependents($googlemeet, $cm, $context, $a, $student->id);
        $DB->insert_record('googlemeet_recording_subs', (object)['googlemeetid' => $googlemeet->id,
            'userid' => $student->id, 'timecreated' => time()]);

        $status = googlemeet_reset_userdata((object)['courseid' => $course->id, 'reset_googlemeet_userdata' => 1]);
        $this->assertCount(1, $status);

        $counts = $this->count_dependents($a);
        $this->assertSame(0, $counts['progress']);
        $this->assertSame(0, $counts['attempts']);
        $this->assertSame(0, $counts['preferences']);
        $this->assertSame(1, $counts['analysis']);
        $this->assertSame(1, $counts['files']);
        $this->assertSame(2, $counts['tagged']);
        $this->assertTrue($DB->record_exists('googlemeet_recordings', ['id' => $a]));
        $this->assertFalse($DB->record_exists('googlemeet_recording_subs', ['googlemeetid' => $googlemeet->id]));
    }
}
