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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/lib.php');
require_once($CFG->dirroot . '/mod/googlemeet/locallib.php');
require_once($CFG->dirroot . '/mod/googlemeet/classes/external.php');

/**
 * Soft-delete coverage for synced recordings.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class soft_delete_test extends \advanced_testcase {

    /**
     * Create a course, module and context.
     *
     * @return array
     */
    private function create_googlemeet_fixture(): array {
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $googlemeet = $this->getDataGenerator()->create_module('googlemeet', [
            'course' => $course->id,
            'name' => 'Soft delete room',
            'url' => 'https://meet.google.com/abc-defg-hij',
        ]);
        $cm = get_coursemodule_from_instance('googlemeet', $googlemeet->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        return [$course, $googlemeet, $cm, $context];
    }

    /**
     * Insert a recording row.
     *
     * @param int $googlemeetid Activity instance id.
     * @param string $driveid Drive file id.
     * @param array $overrides Field overrides.
     * @return int Recording row id.
     */
    private function create_recording(int $googlemeetid, string $driveid, array $overrides = []): int {
        global $DB;

        $now = time();
        $record = (object)array_merge([
            'googlemeetid' => $googlemeetid,
            'recordingid' => $driveid,
            'name' => 'Recording ' . $driveid,
            'createdtime' => $now - 100,
            'duration' => '00:05:00',
            'webviewlink' => 'https://drive.google.com/file/d/' . $driveid . '/view',
            'visible' => 1,
            'deleted' => 0,
            'timedeleted' => 0,
            'timemodified' => $now,
        ], $overrides);

        return (int)$DB->insert_record('googlemeet_recordings', $record);
    }

    /**
     * Insert an AI analysis row.
     *
     * @param int $recordingid Recording row id.
     * @return int Analysis row id.
     */
    private function create_analysis(int $recordingid): int {
        global $DB;

        $now = time();
        return (int)$DB->insert_record('googlemeet_ai_analysis', (object)[
            'recordingid' => $recordingid,
            'summary' => 'Existing summary',
            'keypoints' => '["One"]',
            'transcript' => 'Existing transcript',
            'topics' => '["Topic"]',
            'language' => 'en',
            'status' => 'completed',
            'retrycount' => 0,
            'nextretry' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Build a processed Drive file object as sync_recordings() expects it.
     *
     * @param string $driveid Drive file id.
     * @param string|null $name Recording name.
     * @return \stdClass
     */
    private function drive_file(string $driveid, ?string $name = null): \stdClass {
        return (object)[
            'recordingId' => $driveid,
            'name' => $name ?? ('Drive recording ' . $driveid),
            'createdTime' => time(),
            'duration' => '00:10:00',
            'webViewLink' => 'https://drive.google.com/file/d/' . $driveid . '/view',
        ];
    }

    /**
     * Missing Drive files are moved to trash and keep their AI analysis.
     */
    public function test_sync_soft_deletes_orphan_and_keeps_ai_analysis(): void {
        global $DB;

        $this->resetAfterTest();
        [, $googlemeet] = $this->create_googlemeet_fixture();
        $oldid = $this->create_recording($googlemeet->id, 'drive-old');
        $analysisid = $this->create_analysis($oldid);

        $result = sync_recordings($googlemeet->id, [$this->drive_file('drive-new')]);

        $old = $DB->get_record('googlemeet_recordings', ['id' => $oldid], '*', MUST_EXIST);
        $this->assertEquals(1, (int)$old->deleted);
        $this->assertGreaterThan(0, (int)$old->timedeleted);
        $this->assertTrue($DB->record_exists('googlemeet_ai_analysis', ['id' => $analysisid, 'recordingid' => $oldid]));
        $this->assertSame(1, (int)$result['stats']['trashed']);
        $this->assertSame(0, (int)$result['stats']['deleted']);
    }

    /**
     * A trashed recording is restored in place when the same Drive file appears again.
     */
    public function test_sync_restores_same_recordingid_in_place_with_ai_analysis(): void {
        global $DB;

        $this->resetAfterTest();
        [, $googlemeet] = $this->create_googlemeet_fixture();
        $recordingid = $this->create_recording($googlemeet->id, 'drive-back', [
            'deleted' => 1,
            'timedeleted' => time() - 50,
            'name' => 'Old trashed name',
        ]);
        $analysisid = $this->create_analysis($recordingid);

        $result = sync_recordings($googlemeet->id, [$this->drive_file('drive-back', 'Restored name')]);

        $recording = $DB->get_record('googlemeet_recordings', ['id' => $recordingid], '*', MUST_EXIST);
        $this->assertSame($recordingid, (int)$recording->id);
        $this->assertSame('Restored name', $recording->name);
        $this->assertEquals(0, (int)$recording->deleted);
        $this->assertEquals(0, (int)$recording->timedeleted);
        $this->assertTrue($DB->record_exists('googlemeet_ai_analysis', ['id' => $analysisid, 'recordingid' => $recordingid]));
        $this->assertSame(1, (int)$result['stats']['restored']);
        $this->assertSame(0, (int)$result['stats']['inserted']);
    }

    /**
     * The public recording list used by the print context excludes trashed rows.
     */
    public function test_list_recordings_context_excludes_deleted_for_normal_user(): void {
        global $DB;

        $this->resetAfterTest();
        [, $googlemeet] = $this->create_googlemeet_fixture();
        $activeid = $this->create_recording($googlemeet->id, 'drive-active', ['name' => 'Visible class']);
        $this->create_recording($googlemeet->id, 'drive-deleted', [
            'name' => 'Deleted class',
            'deleted' => 1,
            'timedeleted' => time(),
        ]);

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $recordings = googlemeet_list_recordings(['googlemeetid' => $googlemeet->id, 'visible' => 1]);

        $this->assertCount(1, $recordings);
        $this->assertSame($activeid, (int)$recordings[0]->id);
        $this->assertFalse($DB->record_exists('googlemeet_recordings', [
            'googlemeetid' => $googlemeet->id,
            'name' => 'Deleted class',
            'deleted' => 0,
        ]));
    }

    /**
     * Restore WS restores own trashed recordings and rejects IDs from another instance.
     */
    public function test_restore_recording_ws_happy_path_and_anti_idor(): void {
        global $DB;

        $this->resetAfterTest();
        [, $googlemeet, $cm] = $this->create_googlemeet_fixture();
        [, $othergooglemeet] = $this->create_googlemeet_fixture();

        $otherid = $this->create_recording($othergooglemeet->id, 'drive-other', [
            'deleted' => 1,
            'timedeleted' => time(),
        ]);
        try {
            \mod_googlemeet_external::restore_recording($otherid, $cm->id);
            $this->fail('Expected invalidrecord for a recording from another instance.');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidrecord', $e->errorcode);
        }

        $recordingid = $this->create_recording($googlemeet->id, 'drive-restore', [
            'deleted' => 1,
            'timedeleted' => time(),
        ]);
        $result = \mod_googlemeet_external::restore_recording($recordingid, $cm->id);
        $recording = $DB->get_record('googlemeet_recordings', ['id' => $recordingid], '*', MUST_EXIST);

        $this->assertTrue($result['success']);
        $this->assertEquals(0, (int)$recording->deleted);
        $this->assertEquals(0, (int)$recording->timedeleted);
    }

    /**
     * Trash WS moves own active recordings to trash and rejects other instances or already-deleted rows.
     */
    public function test_trash_recording_ws_happy_path_anti_idor_and_already_deleted(): void {
        global $DB;

        $this->resetAfterTest();
        [, $googlemeet, $cm] = $this->create_googlemeet_fixture();
        [, $othergooglemeet] = $this->create_googlemeet_fixture();

        $otherid = $this->create_recording($othergooglemeet->id, 'drive-other-trash');
        try {
            \mod_googlemeet_external::trash_recording($otherid, $cm->id);
            $this->fail('Expected invalidrecord for a recording from another instance.');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidrecord', $e->errorcode);
        }
        $other = $DB->get_record('googlemeet_recordings', ['id' => $otherid], '*', MUST_EXIST);
        $this->assertSame(0, (int)$other->deleted);

        $alreadydeletedid = $this->create_recording($googlemeet->id, 'drive-already-trash', [
            'deleted' => 1,
            'timedeleted' => time(),
        ]);
        try {
            \mod_googlemeet_external::trash_recording($alreadydeletedid, $cm->id);
            $this->fail('Expected invalidrecord for an already-deleted recording.');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidrecord', $e->errorcode);
        }

        $recordingid = $this->create_recording($googlemeet->id, 'drive-trash');
        $result = \mod_googlemeet_external::trash_recording($recordingid, $cm->id);
        $recording = $DB->get_record('googlemeet_recordings', ['id' => $recordingid], '*', MUST_EXIST);

        $this->assertTrue($result['success']);
        $this->assertSame(1, (int)$recording->deleted);
        $this->assertGreaterThan(0, (int)$recording->timedeleted);
    }

    /**
     * Purge WS deletes own trashed recordings and their AI analysis, and rejects other instances.
     */
    public function test_purge_recording_ws_happy_path_deletes_ai_and_anti_idor(): void {
        global $DB;

        $this->resetAfterTest();
        [, $googlemeet, $cm] = $this->create_googlemeet_fixture();
        [, $othergooglemeet] = $this->create_googlemeet_fixture();

        $otherid = $this->create_recording($othergooglemeet->id, 'drive-other-purge', [
            'deleted' => 1,
            'timedeleted' => time(),
        ]);
        try {
            \mod_googlemeet_external::purge_recording($otherid, $cm->id);
            $this->fail('Expected invalidrecord for a recording from another instance.');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidrecord', $e->errorcode);
        }
        $this->assertTrue($DB->record_exists('googlemeet_recordings', ['id' => $otherid]));

        $recordingid = $this->create_recording($googlemeet->id, 'drive-purge', [
            'deleted' => 1,
            'timedeleted' => time(),
        ]);
        $analysisid = $this->create_analysis($recordingid);

        $result = \mod_googlemeet_external::purge_recording($recordingid, $cm->id);

        $this->assertTrue($result['success']);
        $this->assertFalse($DB->record_exists('googlemeet_recordings', ['id' => $recordingid]));
        $this->assertFalse($DB->record_exists('googlemeet_ai_analysis', ['id' => $analysisid]));
    }

    /**
     * Search filtering cannot match trashed recordings because they are removed before filtering.
     */
    public function test_search_filter_does_not_match_deleted_recordings(): void {
        $this->resetAfterTest();
        [, $googlemeet] = $this->create_googlemeet_fixture();
        $this->create_recording($googlemeet->id, 'drive-active-search', ['name' => 'Visible topic']);
        $this->create_recording($googlemeet->id, 'drive-deleted-search', [
            'name' => 'Unique deleted needle',
            'deleted' => 1,
            'timedeleted' => time(),
        ]);

        $recordings = googlemeet_list_recordings(['googlemeetid' => $googlemeet->id], false);
        $filtered = googlemeet_filter_recordings_by_query($recordings, 'deleted needle');

        $this->assertCount(0, $filtered);
    }

    /**
     * Exhausted autosync events are reopened by manual sync without touching non-exhausted events.
     */
    public function test_reset_exhausted_autosync_events_only_reopens_exhausted_rows(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('maxsyncattempts', 3, 'googlemeet');
        [, $googlemeet] = $this->create_googlemeet_fixture();
        $now = time();

        $exhaustedid = $DB->insert_record('googlemeet_events', (object)[
            'googlemeetid' => $googlemeet->id,
            'eventdate' => $now - HOURSECS,
            'duration' => HOURSECS,
            'timemodified' => $now,
            'autosynced' => $now - 100,
            'syncattempts' => 3,
            'nextsyncattempt' => $now + HOURSECS,
        ]);
        $belowmaxid = $DB->insert_record('googlemeet_events', (object)[
            'googlemeetid' => $googlemeet->id,
            'eventdate' => $now - HOURSECS,
            'duration' => HOURSECS,
            'timemodified' => $now,
            'autosynced' => $now - 100,
            'syncattempts' => 2,
            'nextsyncattempt' => $now + HOURSECS,
        ]);
        $openid = $DB->insert_record('googlemeet_events', (object)[
            'googlemeetid' => $googlemeet->id,
            'eventdate' => $now - HOURSECS,
            'duration' => HOURSECS,
            'timemodified' => $now,
            'autosynced' => 0,
            'syncattempts' => 3,
            'nextsyncattempt' => $now + HOURSECS,
        ]);

        $this->assertSame(1, googlemeet_reset_exhausted_autosync_events((int)$googlemeet->id));

        $exhausted = $DB->get_record('googlemeet_events', ['id' => $exhaustedid], '*', MUST_EXIST);
        $this->assertSame(0, (int)$exhausted->autosynced);
        $this->assertSame(0, (int)$exhausted->syncattempts);
        $this->assertSame(0, (int)$exhausted->nextsyncattempt);

        $belowmax = $DB->get_record('googlemeet_events', ['id' => $belowmaxid], '*', MUST_EXIST);
        $this->assertSame($now - 100, (int)$belowmax->autosynced);
        $this->assertSame(2, (int)$belowmax->syncattempts);
        $this->assertSame($now + HOURSECS, (int)$belowmax->nextsyncattempt);

        $open = $DB->get_record('googlemeet_events', ['id' => $openid], '*', MUST_EXIST);
        $this->assertSame(0, (int)$open->autosynced);
        $this->assertSame(3, (int)$open->syncattempts);
        $this->assertSame($now + HOURSECS, (int)$open->nextsyncattempt);
    }

    /**
     * Enrichment task custom data survives Moodle's JSON round-trip.
     */
    public function test_process_recording_enrichment_custom_data_round_trip(): void {
        $task = new \mod_googlemeet\task\process_recording_enrichment();
        $task->set_custom_data([
            'googlemeetid' => 42,
            'recordingids' => [7, 8],
        ]);

        $data = $task->get_custom_data();
        $this->assertSame(42, (int)$data->googlemeetid);
        $this->assertSame([7, 8], array_map('intval', $data->recordingids));
    }

    /**
     * Deleted recordings are skipped before the enrichment task needs Google authentication.
     */
    public function test_process_recording_enrichment_skips_deleted_recordings(): void {
        global $DB;

        $this->resetAfterTest();
        $this->expectOutputRegex('/recording #\d+ is deleted; skipping/');
        [, $googlemeet] = $this->create_googlemeet_fixture();
        $recordingid = $this->create_recording($googlemeet->id, 'drive-deleted-enrichment', [
            'deleted' => 1,
            'timedeleted' => time(),
        ]);

        $task = new \mod_googlemeet\task\process_recording_enrichment();
        $task->set_custom_data([
            'googlemeetid' => $googlemeet->id,
            'recordingids' => [$recordingid],
        ]);
        $task->execute();

        $recording = $DB->get_record('googlemeet_recordings', ['id' => $recordingid], '*', MUST_EXIST);
        $this->assertSame(1, (int)$recording->deleted);
        $this->assertFalse($DB->record_exists('googlemeet_ai_analysis', ['recordingid' => $recordingid]));
    }

    /**
     * Deferred sync queues enrichment and skips immediate AI/notification queues.
     */
    public function test_sync_recordings_deferred_queues_enrichment_without_ai_or_notify(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('enableai', 1, 'googlemeet');
        set_config('geminiapikey', 'test-key', 'googlemeet');
        set_config('ai_autogenerate', 1, 'googlemeet');

        [, $googlemeet] = $this->create_googlemeet_fixture();
        $subscriber = $this->getDataGenerator()->create_user();
        $DB->insert_record('googlemeet_recording_subs', (object)[
            'googlemeetid' => $googlemeet->id,
            'userid' => $subscriber->id,
            'timecreated' => time(),
        ]);

        $result = sync_recordings($googlemeet->id, [$this->drive_file('drive-deferred')], true);

        $this->assertSame(1, (int)$result['stats']['inserted']);
        $this->assertCount(1, $result['newrecordingids']);
        $recordingid = (int)$result['newrecordingids'][0];
        $this->assertFalse($DB->record_exists('googlemeet_ai_analysis', ['recordingid' => $recordingid]));

        $enrichmenttasks = \core\task\manager::get_adhoc_tasks(
            \mod_googlemeet\task\process_recording_enrichment::class
        );
        $enrichmenttasks = array_values($enrichmenttasks);
        $this->assertCount(1, $enrichmenttasks);
        $data = $enrichmenttasks[0]->get_custom_data();
        $this->assertSame((int)$googlemeet->id, (int)$data->googlemeetid);
        $this->assertSame([$recordingid], array_map('intval', $data->recordingids));

        $notificationtasks = \core\task\manager::get_adhoc_tasks(
            \mod_googlemeet\task\notify_new_recordings::class
        );
        $this->assertCount(0, $notificationtasks);
    }
}
