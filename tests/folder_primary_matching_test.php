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

/**
 * Test wrapper exposing the protected client helpers used by this suite.
 */
class folder_matching_testable_client extends client {
    /**
     * Expose the recording filter.
     *
     * @param array $recordings Drive recording objects.
     * @param string $meetingcode Meeting code.
     * @param string $activityname Activity name.
     * @param int $googlemeetid Current activity id.
     * @param string $customfilter Custom recording filter.
     * @param int[] $nameconflicts Conflicting activity ids.
     * @param array $eventslots Event slots per googlemeetid.
     * @return array
     */
    public function filter_recordings_for_activity_for_test(array $recordings, string $meetingcode, string $activityname,
            int $googlemeetid, string $customfilter = '', array $nameconflicts = [], array $eventslots = []): array {
        return $this->filter_recordings_for_activity(
            $recordings, $meetingcode, $activityname, $googlemeetid, $customfilter, $nameconflicts, $eventslots);
    }

    /**
     * Expose the assigned-folder listing.
     *
     * @param int $googlemeetid Activity id.
     * @return string[]
     */
    public function get_assigned_folder_ids_for_test(int $googlemeetid): array {
        return $this->get_assigned_folder_ids($googlemeetid);
    }

    /**
     * Expose the folder owner map.
     *
     * @param string[] $folderids Drive folder ids.
     * @return array
     */
    public function get_folder_owner_map_for_test(array $folderids): array {
        return $this->get_folder_owner_map($folderids);
    }

    /**
     * Expose the two-leg Drive fetch.
     *
     * @param object $service Fake REST service.
     * @param object $googlemeet Activity instance.
     * @param string[] $folderids Discovered Meet folders.
     * @param string $namefilter Name query fragment.
     * @param string $recordingfields Fields mask.
     * @return array
     */
    public function fetch_recordings_for_activity_for_test($service, $googlemeet, array $folderids,
            string $namefilter, string $recordingfields): array {
        return $this->fetch_recordings_for_activity($service, $googlemeet, $folderids, $namefilter, $recordingfields);
    }
}

/**
 * Minimal fake REST service used by helper::request().
 */
class folder_matching_fake_drive_service {
    /** @var array Queued responses. */
    private array $responses;

    /** @var array Captured calls. */
    public array $calls = [];

    /**
     * @param array $responses Responses returned by call().
     */
    public function __construct(array $responses) {
        $this->responses = $responses;
    }

    /**
     * Fake core\oauth2\rest::call().
     *
     * @param string $api API name.
     * @param array $params Request params.
     * @param mixed $rawpost Raw body.
     * @return \stdClass
     */
    public function call($api, $params, $rawpost = false): \stdClass {
        $this->calls[] = ['api' => $api, 'params' => $params, 'rawpost' => $rawpost];
        return (object)array_shift($this->responses);
    }
}

/**
 * Folder-fingerprint primary matching semantics.
 *
 * Covers the behaviour matrix: renaming the Moodle activity, the meeting or the
 * Drive file must not orphan recordings; trash only happens on real deletion;
 * folder ownership prevents cross-activity contamination; unassigned folders
 * still require a name match.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class folder_primary_matching_test extends \advanced_testcase {

    /**
     * Create a course, module and context (as admin).
     *
     * @param string $name Activity name.
     * @return array [course, googlemeet, cm, context]
     */
    private function create_googlemeet_fixture(string $name = 'Room'): array {
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $googlemeet = $this->getDataGenerator()->create_module('googlemeet', [
            'course' => $course->id,
            'name' => $name,
            'url' => 'https://meet.google.com/abc-defg-hij',
        ]);
        $cm = get_coursemodule_from_instance('googlemeet', $googlemeet->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        return [$course, $googlemeet, $cm, $context];
    }

    /**
     * Insert a stored recording row.
     *
     * @param int $googlemeetid Owning activity id.
     * @param string $driveid Drive file id.
     * @param array $overrides Field overrides.
     * @return int Row id.
     */
    private function create_recording(int $googlemeetid, string $driveid, array $overrides = []): int {
        global $DB;

        $now = time();
        return $DB->insert_record('googlemeet_recordings', (object)array_merge([
            'googlemeetid' => $googlemeetid,
            'recordingid' => $driveid,
            'name' => 'Recording ' . $driveid,
            'createdtime' => $now - 100,
            'duration' => '01:00:00',
            'webviewlink' => 'https://drive.google.com/file/d/' . $driveid . '/view',
            'drivefolderid' => null,
            'visible' => 1,
            'deleted' => 0,
            'timedeleted' => 0,
            'timemodified' => $now,
        ], $overrides));
    }

    /**
     * Build a processed Drive file object as sync_recordings() expects it.
     *
     * @param string $driveid Drive file id.
     * @param string|null $name Drive file name.
     * @param array $extra Extra fields (e.g. drivefolderid).
     * @return \stdClass
     */
    private function drive_file(string $driveid, ?string $name = null, array $extra = []): \stdClass {
        return (object)array_merge([
            'recordingId' => $driveid,
            'name' => $name ?? ('Drive recording ' . $driveid),
            'createdTime' => time(),
            'duration' => '00:10:00',
            'webViewLink' => 'https://drive.google.com/file/d/' . $driveid . '/view',
        ], $extra);
    }

    /**
     * Build a raw Drive file object as returned by files.list.
     *
     * @param string $driveid Drive file id.
     * @param string $name Drive file name.
     * @param string|null $folderid Parent folder id.
     * @return \stdClass
     */
    private function raw_drive_file(string $driveid, string $name, ?string $folderid): \stdClass {
        $file = (object)[
            'id' => $driveid,
            'name' => $name,
        ];
        if ($folderid !== null) {
            $file->parents = [$folderid];
        }
        return $file;
    }

    /**
     * Build the testable client without running the OAuth constructor.
     *
     * @return folder_matching_testable_client
     */
    private function make_client(): folder_matching_testable_client {
        $reflection = new \ReflectionClass(folder_matching_testable_client::class);
        return $reflection->newInstanceWithoutConstructor();
    }

    /**
     * A recording in a folder assigned to this activity is imported even when its
     * filename matches nothing.
     */
    public function test_folder_assigned_recording_imported_with_non_matching_name(): void {
        [, $googlemeet] = $this->create_googlemeet_fixture();
        $this->create_recording($googlemeet->id, 'drive-old', ['drivefolderid' => 'folder-a']);

        $client = $this->make_client();
        $file = $this->raw_drive_file('drive-new', 'Completely unrelated name.mp4', 'folder-a');

        $result = $client->filter_recordings_for_activity_for_test([$file], 'abc-defg-hij', $googlemeet->name, $googlemeet->id);

        $this->assertCount(1, $result['recordings']);
        $this->assertSame(1, $result['matchedvia']['folder']);
        $this->assertSame(0, $result['matchedvia']['name']);
    }

    /**
     * A recording in a folder assigned to another activity is never imported here.
     */
    public function test_folder_of_other_activity_is_never_imported(): void {
        [, $room1] = $this->create_googlemeet_fixture('Room one');
        [, $room2] = $this->create_googlemeet_fixture('Room two');
        $this->create_recording($room2->id, 'drive-theirs', ['drivefolderid' => 'folder-b']);

        $client = $this->make_client();
        // Even a filename that matches room1's name cannot steal a file from room2's folder.
        $file = $this->raw_drive_file('drive-new', $room1->name . ' (2026-10-10 10:00)', 'folder-b');

        $result = $client->filter_recordings_for_activity_for_test([$file], 'abc-defg-hij', $room1->name, $room1->id);

        $this->assertCount(0, $result['recordings']);
        $this->assertSame(1, $result['matchedvia']['skipped_other_activity_folder']);
    }

    /**
     * Recordings in unassigned folders still need a name match.
     */
    public function test_unassigned_folder_still_requires_name_match(): void {
        [, $googlemeet] = $this->create_googlemeet_fixture('Videotutoría');

        $client = $this->make_client();

        $matching = $this->raw_drive_file('drive-match', 'Videotutoría (2026-10-10 10:00)', 'folder-unknown');
        $foreign = $this->raw_drive_file('drive-foreign', 'Other meeting (2026-10-10 11:00)', 'folder-unknown');

        $result = $client->filter_recordings_for_activity_for_test(
            [$matching, $foreign], 'abc-defg-hij', $googlemeet->name, $googlemeet->id);

        $this->assertCount(1, $result['recordings']);
        $this->assertSame('drive-match', $result['recordings'][0]->id);
        $this->assertSame(1, $result['matchedvia']['name']);
    }

    /**
     * Renaming the Moodle activity keeps recordings linked: the name query leg
     * returns nothing, but the assigned-folder leg returns the file and the
     * filter keeps the stored row.
     */
    public function test_activity_rename_keeps_recordings_linked_via_folder(): void {
        global $DB;

        [, $googlemeet] = $this->create_googlemeet_fixture();
        $this->create_recording($googlemeet->id, 'drive-1', ['drivefolderid' => 'folder-a']);

        $client = $this->make_client();
        $fields = 'nextPageToken, files(id,name,permissionIds,createdTime,videoMediaMetadata,webViewLink,parents)';
        // Name leg (scoped to the discovered "root-1" folder) returns nothing after
        // the rename; the assigned-folder leg returns the stored file.
        $service = new folder_matching_fake_drive_service([
            ['files' => []],
            ['files' => [$this->raw_drive_file('drive-1', 'Any new name.mp4', 'folder-a')]],
        ]);

        $fetched = $client->fetch_recordings_for_activity_for_test(
            $service, $googlemeet, ['root-1'], '(name contains "NoLongerMatching")', $fields);

        $this->assertCount(1, $fetched);
        $this->assertSame('drive-1', $fetched[0]->id);

        // The stored row must survive the sync (not be soft-deleted as orphan).
        $result = sync_recordings($googlemeet->id, [$this->drive_file('drive-1', 'Any new name.mp4',
            ['drivefolderid' => 'folder-a'])]);
        $row = $DB->get_record('googlemeet_recordings', ['recordingid' => 'drive-1'], '*', MUST_EXIST);
        $this->assertSame(0, (int)$row->deleted);
        $this->assertSame(0, (int)$result['stats']['trashed']);
    }

    /**
     * Renaming a Drive file updates the stored row in place: no trash, no
     * duplicate row, name updated.
     */
    public function test_drive_file_rename_updates_row_in_place(): void {
        global $DB;

        [, $googlemeet] = $this->create_googlemeet_fixture();
        $this->create_recording($googlemeet->id, 'drive-1', ['name' => 'Old name']);

        $result = sync_recordings($googlemeet->id, [$this->drive_file('drive-1', 'New name')]);

        $rows = $DB->get_records('googlemeet_recordings', ['recordingid' => 'drive-1']);
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame('New name', $row->name);
        $this->assertSame(0, (int)$row->deleted);
        $this->assertSame(0, (int)$result['stats']['trashed']);
        $this->assertSame(0, (int)$result['stats']['inserted']);
        $this->assertGreaterThanOrEqual(1, (int)$result['stats']['updated']);
    }

    /**
     * A stored recording whose Drive file is gone (absent from every query leg)
     * is soft-deleted.
     */
    public function test_drive_file_deletion_soft_deletes_row(): void {
        global $DB;

        [, $googlemeet] = $this->create_googlemeet_fixture();
        $rowid = $this->create_recording($googlemeet->id, 'drive-gone');

        $result = sync_recordings($googlemeet->id, [$this->drive_file('drive-other')]);

        $row = $DB->get_record('googlemeet_recordings', ['id' => $rowid], '*', MUST_EXIST);
        $this->assertSame(1, (int)$row->deleted);
        $this->assertSame(1, (int)$result['stats']['trashed']);
    }

    /**
     * New videos appearing in an assigned folder are imported by
     * sync_recordings() regardless of name (the filter already admitted them).
     */
    public function test_new_video_in_assigned_folder_is_imported(): void {
        global $DB;

        [, $googlemeet] = $this->create_googlemeet_fixture();
        $this->create_recording($googlemeet->id, 'drive-old', ['drivefolderid' => 'folder-a']);

        $result = sync_recordings($googlemeet->id, [
            $this->drive_file('drive-old', 'Old video', ['drivefolderid' => 'folder-a']),
            $this->drive_file('drive-new', 'Whatever the meeting was called', ['drivefolderid' => 'folder-a']),
        ]);

        $this->assertSame(1, (int)$result['stats']['inserted']);
        $newrow = $DB->get_record('googlemeet_recordings', ['recordingid' => 'drive-new'], '*', MUST_EXIST);
        $this->assertSame('folder-a', $newrow->drivefolderid);
        $this->assertSame(0, (int)$newrow->deleted);
    }

    /**
     * The assigned-folder leg also runs in the all-Drive fallback (no Meet
     * folders discovered).
     */
    public function test_assigned_folder_leg_runs_in_all_drive_fallback(): void {
        [, $googlemeet] = $this->create_googlemeet_fixture();
        $this->create_recording($googlemeet->id, 'drive-1', ['drivefolderid' => 'folder-a']);

        $client = $this->make_client();
        $fields = 'nextPageToken, files(id,name,permissionIds,createdTime,videoMediaMetadata,webViewLink,parents)';
        $service = new folder_matching_fake_drive_service([
            ['files' => []], // Name leg, all-Drive.
            ['files' => [$this->raw_drive_file('drive-1', 'Renamed video.mp4', 'folder-a')]],
        ]);

        $fetched = $client->fetch_recordings_for_activity_for_test(
            $service, $googlemeet, [], '(name contains "NoLongerMatching")', $fields);

        $this->assertCount(1, $fetched);
        $this->assertSame('drive-1', $fetched[0]->id);
    }

    /**
     * Folders claimed by several activities are ambiguous and fall back to name
     * matching (never silently pinned to one owner).
     */
    public function test_folder_claimed_by_two_activities_is_ambiguous(): void {
        [, $room1] = $this->create_googlemeet_fixture('Room one');
        [, $room2] = $this->create_googlemeet_fixture('Room two');
        $this->create_recording($room1->id, 'drive-1', ['drivefolderid' => 'folder-shared']);
        $this->create_recording($room2->id, 'drive-2', ['drivefolderid' => 'folder-shared']);

        $client = $this->make_client();
        $owners = $client->get_folder_owner_map_for_test(['folder-shared']);
        $this->assertArrayNotHasKey('folder-shared', $owners);

        // The name still decides for ambiguous folders.
        $matching = $this->raw_drive_file('drive-3', 'Room one (2026-10-10 10:00)', 'folder-shared');
        $foreign = $this->raw_drive_file('drive-4', 'Unrelated meeting', 'folder-shared');
        $result = $client->filter_recordings_for_activity_for_test(
            [$matching, $foreign], 'abc-defg-hij', 'Room one', $room1->id);
        $this->assertCount(1, $result['recordings']);
        $this->assertSame('drive-3', $result['recordings'][0]->id);
    }

    /**
     * get_assigned_folder_ids returns distinct, non-empty folder ids of stored
     * recordings only.
     */
    public function test_get_assigned_folder_ids_ignores_empty_and_dedupes(): void {
        global $DB;

        [, $googlemeet] = $this->create_googlemeet_fixture();
        $this->create_recording($googlemeet->id, 'drive-1', ['drivefolderid' => 'folder-a']);
        $this->create_recording($googlemeet->id, 'drive-2', ['drivefolderid' => 'folder-a']);
        $this->create_recording($googlemeet->id, 'drive-3', ['drivefolderid' => '']);
        $trashed = $this->create_recording($googlemeet->id, 'drive-4', ['drivefolderid' => 'folder-b']);
        $DB->set_field('googlemeet_recordings', 'deleted', 1, ['id' => $trashed]);

        $client = $this->make_client();
        $this->assertSame(['folder-a'], $client->get_assigned_folder_ids_for_test($googlemeet->id));
    }
}
