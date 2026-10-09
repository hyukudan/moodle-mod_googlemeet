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

/**
 * Test wrapper exposing selected protected client helpers.
 */
class client_testable_client extends client {
    /**
     * Expose Drive pagination for unit tests.
     *
     * @param object $service Fake REST service.
     * @param array $params Request params.
     * @return array
     */
    public function list_all_pages_for_test($service, array $params): array {
        return $this->list_all_pages($service, $params);
    }

    /**
     * Expose Meet folder discovery for unit tests.
     *
     * @param object $service Fake REST service.
     * @return string[]
     */
    public function get_meet_recordings_folder_ids_for_test($service): array {
        return $this->get_meet_recordings_folder_ids($service);
    }

    /**
     * Expose parents query chunking for unit tests.
     *
     * @param string[] $folderids Drive folder ids.
     * @return string[]
     */
    public function build_parents_query_chunks_for_test(array $folderids): array {
        return $this->build_parents_query_chunks($folderids);
    }

    /**
     * Expose the event overlap matcher for unit tests.
     *
     * @param int $start Recording interval start.
     * @param int $end Recording interval end.
     * @param array $eventslots Event slots per googlemeetid.
     * @param int[] $candidateids Competing activity ids.
     * @return array
     */
    public static function best_event_overlap_match_for_test(int $start, int $end, array $eventslots, array $candidateids): array {
        return self::best_event_overlap_match($start, $end, $eventslots, $candidateids);
    }

    /**
     * Expose same-name conflict resolution for unit tests.
     *
     * @param object $recording Drive recording object.
     * @param int $googlemeetid Current activity id.
     * @param int[] $nameconflicts Conflicting activity ids.
     * @param array $eventslots Event slots per googlemeetid.
     * @return bool
     */
    public function recording_passes_conflict_resolution_for_test($recording, int $googlemeetid, array $nameconflicts, array $eventslots): bool {
        return $this->recording_passes_conflict_resolution($recording, $googlemeetid, $nameconflicts, $eventslots);
    }
}

/**
 * Minimal fake REST service used by helper::request().
 */
class client_test_fake_drive_service {
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
 * Unit tests for mod_googlemeet\client helpers.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class client_test extends \advanced_testcase {

    /**
     * Build a client without running the OAuth constructor.
     *
     * @return client_testable_client
     */
    private function make_client(): client_testable_client {
        $reflection = new \ReflectionClass(client_testable_client::class);
        return $reflection->newInstanceWithoutConstructor();
    }

    /**
     * Build a fake Drive file/doc object.
     *
     * @param string $id File id.
     * @param string $name File name.
     * @param int|null $created Timestamp.
     * @return \stdClass
     */
    private function drive_doc(string $id, string $name, ?int $created = null): \stdClass {
        $doc = (object)[
            'id' => $id,
            'name' => $name,
        ];
        if ($created !== null) {
            $doc->createdTime = gmdate('Y-m-d\TH:i:s\Z', $created);
        }
        return $doc;
    }

    /**
     * Drive pagination accumulates files from multiple pages and passes pageToken.
     */
    public function test_list_all_pages_accumulates_two_pages(): void {
        $client = $this->make_client();
        $service = new client_test_fake_drive_service([
            ['files' => [(object)['id' => 'one']], 'nextPageToken' => 'token-2'],
            ['files' => [(object)['id' => 'two']]],
        ]);

        $files = $client->list_all_pages_for_test($service, [
            'q' => 'trashed = false',
            'pageSize' => 100,
            'fields' => 'nextPageToken, files(id)',
        ]);

        $this->assertSame(['one', 'two'], array_map(static fn($file) => $file->id, $files));
        $this->assertCount(2, $service->calls);
        $this->assertArrayNotHasKey('pageToken', $service->calls[0]['params']);
        $this->assertSame('token-2', $service->calls[1]['params']['pageToken']);
    }

    /**
     * Drive pagination stops at the defensive ten-page cap.
     */
    public function test_list_all_pages_stops_at_ten_page_cap(): void {
        $this->expectOutputRegex('/Drive list pagination stopped after 10 pages/');
        $client = $this->make_client();
        $responses = [];
        for ($i = 1; $i <= 11; $i++) {
            $responses[] = [
                'files' => [(object)['id' => 'file-' . $i]],
                'nextPageToken' => 'token-' . $i,
            ];
        }
        $service = new client_test_fake_drive_service($responses);

        $files = $client->list_all_pages_for_test($service, [
            'q' => 'name contains "Class"',
            'pageSize' => 1000,
            'fields' => 'nextPageToken, files(id)',
        ]);

        $this->assertCount(10, $files);
        $this->assertCount(10, $service->calls);
        $this->assertDebuggingCalled();
    }

    /**
     * Meeting code extraction supports real URL variants and rejects invalid values.
     */
    public function test_extract_meeting_code_variants(): void {
        $cases = [
            'https://meet.google.com/abc-defg-hij' => 'abc-defg-hij',
            'https://meet.google.com/abc-defg-hij?authuser=0' => 'abc-defg-hij',
            'https://meet.google.com/abc-defg-hij/' => 'abc-defg-hij',
            'http://meet.google.com/abc-defg-hij' => 'abc-defg-hij',
            'HTTPS://MEET.GOOGLE.COM/ABC-DEFG-HIJ' => 'abc-defg-hij',
            'https://meet.google.com/abc-defg-hij?pli=1&hs=122' => 'abc-defg-hij',
            'https://meet.google.com/ab-defg-hij' => null,
            '' => null,
        ];

        foreach ($cases as $url => $expected) {
            $this->assertSame($expected, client::extract_meeting_code($url), $url);
        }
    }

    /**
     * Notes selection keeps Gemini/Notas/Notes priority and chooses the nearest valid preferred doc.
     */
    public function test_select_notes_candidate_uses_temporal_proximity_and_name_priority(): void {
        $recordingtime = strtotime('2026-06-01 10:00:00 UTC');
        $docs = [
            $this->drive_doc('outside', 'Class - Gemini notes old', $recordingtime - (40 * HOURSECS)),
            $this->drive_doc('fallback-nearest', 'Class ordinary doc', $recordingtime + HOURSECS),
            $this->drive_doc('preferred-farther', 'Class - Gemini notes', $recordingtime + (8 * HOURSECS)),
            $this->drive_doc('preferred-nearest', 'Class - Notas', $recordingtime + (2 * HOURSECS)),
        ];

        $candidate = client::select_notes_candidate_for_recording($docs, 'Class.mp4', $recordingtime);

        $this->assertNotNull($candidate);
        $this->assertSame('preferred-nearest', $candidate->id);
    }

    /**
     * Notes outside the 36-hour window are ignored.
     */
    public function test_select_notes_candidate_rejects_docs_outside_temporal_window(): void {
        $recordingtime = strtotime('2026-06-01 10:00:00 UTC');
        $docs = [
            $this->drive_doc('outside', 'Class - Gemini notes', $recordingtime + (37 * HOURSECS)),
        ];

        $this->assertNull(client::select_notes_candidate_for_recording($docs, 'Class.mp4', $recordingtime));
    }

    /**
     * Folder discovery finds the new "Google Meet" root and its per-meeting subfolders.
     */
    public function test_get_meet_recordings_folder_ids_finds_new_topology(): void {
        $client = $this->make_client();
        $service = new client_test_fake_drive_service([
            ['files' => [(object)['id' => 'root-a']]],
            ['files' => [(object)['id' => 'sub-a1'], (object)['id' => 'sub-a2']]],
        ]);

        $folderids = $client->get_meet_recordings_folder_ids_for_test($service);

        $this->assertSame(['root-a', 'sub-a1', 'sub-a2'], $folderids);
        // Root query searches for the new folder name and the legacy names.
        $this->assertStringContainsString('name = "Google Meet"', $service->calls[0]['params']['q']);
        $this->assertStringContainsString('name contains "Meet Recordings"', $service->calls[0]['params']['q']);
        // Second (and last) call lists subfolders of the discovered root: depth cap is 2.
        $this->assertStringContainsString('parents="root-a"', $service->calls[1]['params']['q']);
        $this->assertStringContainsString('mimeType = "application/vnd.google-apps.folder"', $service->calls[1]['params']['q']);
        $this->assertCount(2, $service->calls);
    }

    /**
     * Folder discovery detects the renamed legacy folder and stops when it has no subfolders.
     */
    public function test_get_meet_recordings_folder_ids_detects_renamed_legacy_folder(): void {
        $client = $this->make_client();
        $service = new client_test_fake_drive_service([
            ['files' => [(object)['id' => 'legacy-1']]],
            ['files' => []],
        ]);

        $folderids = $client->get_meet_recordings_folder_ids_for_test($service);

        $this->assertSame(['legacy-1'], $folderids);
        $this->assertCount(2, $service->calls);
    }

    /**
     * Folder discovery returns an empty list (all-Drive fallback) when no folder exists.
     */
    public function test_get_meet_recordings_folder_ids_empty_when_no_folders(): void {
        $client = $this->make_client();
        $service = new client_test_fake_drive_service([
            ['files' => []],
        ]);

        $folderids = $client->get_meet_recordings_folder_ids_for_test($service);

        $this->assertSame([], $folderids);
        $this->assertCount(1, $service->calls);
    }

    /**
     * Parents query chunks are built at the configured size and escape special characters.
     */
    public function test_build_parents_query_chunks_size_and_escaping(): void {
        $client = $this->make_client();
        $folderids = [];
        for ($i = 1; $i <= 120; $i++) {
            $folderids[] = 'folder-' . $i;
        }
        $folderids[] = 'quote"back\slash';

        $chunks = $client->build_parents_query_chunks_for_test($folderids);

        $this->assertCount(3, $chunks);
        $this->assertCount(50, explode(' or ', $chunks[0]));
        $this->assertCount(50, explode(' or ', $chunks[1]));
        $this->assertCount(21, explode(' or ', $chunks[2]));
        $this->assertStringContainsString('parents="quote\"back\\\\slash"', $chunks[2]);
        // Duplicate ids collapse instead of producing repeated parents terms.
        $deduped = $client->build_parents_query_chunks_for_test(['a', 'a', 'b']);
        $this->assertCount(1, $deduped);
        $this->assertSame('parents="a" or parents="b"', $deduped[0]);
    }

    /**
     * A one-hour session and a 15-minute session of same-named rooms are each
     * assigned to their own slot regardless of which recording exists first.
     */
    public function test_best_event_overlap_match_long_and_short_sessions(): void {
        $room1 = 101;
        $room2 = 102;
        $eventslots = [
            $room1 => [['start' => strtotime('2026-10-06 10:00:00 UTC'), 'end' => strtotime('2026-10-06 11:00:00 UTC')]],
            $room2 => [['start' => strtotime('2026-10-06 11:30:00 UTC'), 'end' => strtotime('2026-10-06 11:45:00 UTC')]],
        ];

        // Long session: the shorter recording being created first must not matter.
        $long = client_testable_client::best_event_overlap_match_for_test(
            strtotime('2026-10-06 10:00:00 UTC'),
            strtotime('2026-10-06 11:00:00 UTC'),
            $eventslots,
            [$room1, $room2]
        );
        $this->assertSame($room1, $long['googlemeetid']);
        $this->assertFalse($long['tied']);

        // Short session, created after the long one finished processing.
        $short = client_testable_client::best_event_overlap_match_for_test(
            strtotime('2026-10-06 11:30:00 UTC'),
            strtotime('2026-10-06 11:45:00 UTC'),
            $eventslots,
            [$room1, $room2]
        );
        $this->assertSame($room2, $short['googlemeetid']);
        $this->assertFalse($short['tied']);
    }

    /**
     * Recordings starting slightly before/after their slot still match through
     * the margin, while far-away recordings do not match at all.
     */
    public function test_best_event_overlap_match_margin_and_no_overlap(): void {
        $room1 = 101;
        $eventslots = [
            $room1 => [['start' => strtotime('2026-10-06 10:00:00 UTC'), 'end' => strtotime('2026-10-06 10:15:00 UTC')]],
        ];

        // Recording started 8 minutes early: still inside the widened slot.
        $early = client_testable_client::best_event_overlap_match_for_test(
            strtotime('2026-10-06 09:52:00 UTC'),
            strtotime('2026-10-06 10:30:00 UTC'),
            $eventslots,
            [$room1]
        );
        $this->assertSame($room1, $early['googlemeetid']);
        $this->assertGreaterThan(0, $early['overlap']);

        // Recording hours away: no overlap.
        $away = client_testable_client::best_event_overlap_match_for_test(
            strtotime('2026-10-06 14:00:00 UTC'),
            strtotime('2026-10-06 15:00:00 UTC'),
            $eventslots,
            [$room1]
        );
        $this->assertSame(0, $away['googlemeetid']);
        $this->assertSame(0, $away['overlap']);
        $this->assertFalse($away['tied']);
    }

    /**
     * Two candidates covering the recording equally are reported as a tie so the
     * caller leaves the recording unassigned.
     */
    public function test_best_event_overlap_match_detects_ties(): void {
        $room1 = 101;
        $room2 = 102;
        $eventslots = [
            $room1 => [['start' => strtotime('2026-10-06 10:00:00 UTC'), 'end' => strtotime('2026-10-06 11:00:00 UTC')]],
            $room2 => [['start' => strtotime('2026-10-06 10:00:00 UTC'), 'end' => strtotime('2026-10-06 11:00:00 UTC')]],
        ];

        $match = client_testable_client::best_event_overlap_match_for_test(
            strtotime('2026-10-06 10:00:00 UTC'),
            strtotime('2026-10-06 11:00:00 UTC'),
            $eventslots,
            [$room1, $room2]
        );

        $this->assertGreaterThan(0, $match['overlap']);
        $this->assertTrue($match['tied']);
    }

    /**
     * Create two same-named activities (the collision scenario) in one course.
     *
     * @return array [course, room1, room2]
     */
    private function create_same_name_rooms(): array {
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $room1 = $this->getDataGenerator()->create_module('googlemeet', [
            'course' => $course->id,
            'name' => 'Videotutoría',
            'url' => 'https://meet.google.com/aaa-bbbb-ccc',
        ]);
        $room2 = $this->getDataGenerator()->create_module('googlemeet', [
            'course' => $course->id,
            'name' => 'Videotutoría',
            'url' => 'https://meet.google.com/xxx-yyyy-zzz',
        ]);

        return [$course, $room1, $room2];
    }

    /**
     * Insert a googlemeet_recordings row with the minimal required fields.
     *
     * @param int $googlemeetid Owning activity id.
     * @param array $overrides Field overrides.
     * @return int Row id.
     */
    private function insert_recording_row(int $googlemeetid, array $overrides = []): int {
        global $DB;

        $now = time();
        return $DB->insert_record('googlemeet_recordings', (object)array_merge([
            'googlemeetid' => $googlemeetid,
            'recordingid' => 'drive-' . random_int(100000, 999999),
            'name' => 'Videotutoría (2026-10-06 10:00)',
            'createdtime' => $now,
            'duration' => '01:00:00',
            'webviewlink' => 'https://drive.google.com/file/d/xyz/view',
            'drivefolderid' => null,
            'visible' => 1,
            'deleted' => 0,
            'timedeleted' => 0,
            'timemodified' => $now,
        ], $overrides));
    }

    /**
     * A recording whose Drive folder already has assignments follows that fingerprint.
     */
    public function test_conflict_resolution_follows_folder_fingerprint(): void {
        global $DB;
        [, $room1, $room2] = $this->create_same_name_rooms();
        $this->insert_recording_row($room1->id, ['drivefolderid' => 'folder-room1']);

        $client = $this->make_client();
        $recording = (object)['parents' => ['folder-room1']];

        $this->assertTrue($client->recording_passes_conflict_resolution_for_test(
            $recording, $room1->id, [$room2->id], []));
        $this->assertFalse($client->recording_passes_conflict_resolution_for_test(
            $recording, $room2->id, [$room1->id], []));

        // Deleted rows must not pin the folder.
        $DB->set_field('googlemeet_recordings', 'deleted', 1, ['googlemeetid' => $room1->id]);
        $unpinned = (object)[
            'parents' => ['folder-room1'],
            'createdTime' => gmdate('Y-m-d\TH:i:s\Z', strtotime('2026-10-06 11:30:00 UTC')),
            'videoMediaMetadata' => (object)['durationMillis' => 15 * 60 * 1000],
        ];
        $eventslots = [
            $room1->id => [['start' => strtotime('2026-10-06 10:00:00 UTC'), 'end' => strtotime('2026-10-06 11:00:00 UTC')]],
            $room2->id => [['start' => strtotime('2026-10-06 11:30:00 UTC'), 'end' => strtotime('2026-10-06 11:45:00 UTC')]],
        ];
        $this->assertTrue($client->recording_passes_conflict_resolution_for_test(
            $unpinned, $room2->id, [$room1->id], $eventslots));
    }

    /**
     * An unknown folder is bootstrapped with the scheduled event slots.
     */
    public function test_conflict_resolution_bootstraps_from_event_slots(): void {
        [, $room1, $room2] = $this->create_same_name_rooms();

        $client = $this->make_client();
        $recording = (object)[
            'parents' => ['folder-unknown'],
            'createdTime' => gmdate('Y-m-d\TH:i:s\Z', strtotime('2026-10-06 11:30:00 UTC')),
            'videoMediaMetadata' => (object)['durationMillis' => 15 * 60 * 1000],
        ];
        $eventslots = [
            $room1->id => [['start' => strtotime('2026-10-06 10:00:00 UTC'), 'end' => strtotime('2026-10-06 11:00:00 UTC')]],
            $room2->id => [['start' => strtotime('2026-10-06 11:30:00 UTC'), 'end' => strtotime('2026-10-06 11:45:00 UTC')]],
        ];

        $this->assertTrue($client->recording_passes_conflict_resolution_for_test(
            $recording, $room2->id, [$room1->id], $eventslots));
        $this->assertFalse($client->recording_passes_conflict_resolution_for_test(
            $recording, $room1->id, [$room2->id], $eventslots));
    }

    /**
     * Equal matches are left unassigned instead of being stolen by either room.
     */
    public function test_conflict_resolution_leaves_ties_unassigned(): void {
        [, $room1, $room2] = $this->create_same_name_rooms();

        $client = $this->make_client();
        $recording = (object)[
            'parents' => ['folder-unknown'],
            'createdTime' => gmdate('Y-m-d\TH:i:s\Z', strtotime('2026-10-06 10:00:00 UTC')),
            'videoMediaMetadata' => (object)['durationMillis' => 60 * 60 * 1000],
        ];
        $eventslots = [
            $room1->id => [['start' => strtotime('2026-10-06 10:00:00 UTC'), 'end' => strtotime('2026-10-06 11:00:00 UTC')]],
            $room2->id => [['start' => strtotime('2026-10-06 10:00:00 UTC'), 'end' => strtotime('2026-10-06 11:00:00 UTC')]],
        ];

        $this->assertFalse($client->recording_passes_conflict_resolution_for_test(
            $recording, $room1->id, [$room2->id], $eventslots));
        $this->assertDebuggingCalled();
    }

    /**
     * The access mode resolves from the new setting, deriving from the legacy
     * makerecordingspublic switch when no explicit mode is configured.
     */
    public function test_recording_link_access_resolves_explicit_and_legacy_modes(): void {
        set_config('recordinglinkaccess', '', 'googlemeet');
        set_config('makerecordingspublic', 0, 'googlemeet');
        $this->assertSame('private', client::recording_link_access());

        set_config('makerecordingspublic', 1, 'googlemeet');
        $this->assertSame('anyone', client::recording_link_access());

        foreach (['private', 'anyone', 'domain'] as $mode) {
            set_config('recordinglinkaccess', $mode, 'googlemeet');
            $this->assertSame($mode, client::recording_link_access());
        }
    }

    /**
     * The domain permission carries the owner domain and is not discoverable;
     * anyone mode keeps the historical payload.
     */
    public function test_build_recording_permission_builds_expected_payloads(): void {
        $anyone = client::build_recording_permission('anyone', 'manuel@fpvirtualaragon.es');
        $this->assertSame(['role' => 'reader', 'type' => 'anyone'], $anyone);

        $domain = client::build_recording_permission('domain', 'Manuel@FPVirtualAragon.ES');
        $this->assertSame('reader', $domain['role']);
        $this->assertSame('domain', $domain['type']);
        $this->assertSame('fpvirtualaragon.es', $domain['domain']);
        $this->assertFalse($domain['allowFileDiscovery']);

        $this->assertNull(client::build_recording_permission('private', 'manuel@fpvirtualaragon.es'));
        $this->assertNull(client::build_recording_permission('domain', ''));
    }

    /**
     * Consumer provider domains are refused: a domain grant for them would expose
     * the recording to every user of that provider, so nothing is granted.
     */
    public function test_build_recording_permission_refuses_consumer_domains(): void {
        foreach (['manuel@gmail.com', 'manuel@outlook.com', 'manuel@yahoo.es'] as $email) {
            $this->assertNull(client::build_recording_permission('domain', $email));
            $this->assertDebuggingCalled();
        }
    }

    /**
     * Domain extraction keeps the part after the last @, lower-cased.
     */
    public function test_email_domain_extraction(): void {
        $this->assertSame('fpvirtualaragon.es', client::email_domain('manuel@fpvirtualaragon.es'));
        $this->assertSame('sub.example.org', client::email_domain('a.b@SUB.example.org'));
        $this->assertNull(client::email_domain('not-an-email'));
        $this->assertNull(client::email_domain(''));
        $this->assertNull(client::email_domain(null));
    }
}
