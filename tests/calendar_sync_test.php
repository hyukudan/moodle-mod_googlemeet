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

use mod_googlemeet\local\calendar_sync;
use mod_googlemeet\local\sync_log;
use mod_googlemeet\task\calendar_delete_event;
use mod_googlemeet\task\calendar_update_event;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/lib.php');
require_once($CFG->dirroot . '/mod/googlemeet/locallib.php');

/**
 * Test double of \mod_googlemeet\rest: records calls, optionally throws.
 */
class calendar_sync_test_fake_rest {
    /** @var array Captured calls. */
    public array $calls = [];

    /** @var \Exception|null Thrown by call() when set. */
    public ?\Exception $throw = null;

    /**
     * Fake core\oauth2\rest::call().
     *
     * @param string $api
     * @param array $params
     * @param mixed $rawpost
     * @return \stdClass|null
     */
    public function call($api, $params, $rawpost = false) {
        $this->calls[] = ['api' => $api, 'params' => $params, 'body' => $rawpost === false ? null : json_decode($rawpost, true)];
        if ($this->throw) {
            throw $this->throw;
        }
        return $api === 'deleteevent' ? null : (object)['id' => $params['eventid'] ?? ''];
    }
}

/**
 * DAT-04: Google Calendar event follows activity edits and deletion.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(calendar_sync::class)]
#[CoversClass(calendar_update_event::class)]
#[CoversClass(calendar_delete_event::class)]
final class calendar_sync_test extends \advanced_testcase {

    /** @var calendar_sync_test_fake_rest */
    private calendar_sync_test_fake_rest $rest;

    /** @var array Users the fake factory was asked for. */
    private array $factoryusers = [];

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->rest = new calendar_sync_test_fake_rest();
        $this->factoryusers = [];
        calendar_sync::set_service_factory(function(\stdClass $user) {
            $this->factoryusers[] = (int)$user->id;
            return $this->rest;
        });
    }

    protected function tearDown(): void {
        calendar_sync::set_service_factory(null);
        parent::tearDown();
    }

    /**
     * htmlLink "eid" for an event id, as add_instance() stores it.
     *
     * @param string $eventid
     * @return string
     */
    private static function eid(string $eventid): string {
        return rtrim(strtr(base64_encode($eventid . ' profe@example.com'), '+/', '-_'), '=');
    }

    /**
     * Activity linked to a Google event owned by $creator.
     *
     * @param \stdClass $creator
     * @param string $eventid
     * @return array [googlemeet record, cm]
     */
    private function make_linked_activity(\stdClass $creator, string $eventid = 'evt0123abc', string $url = ''): array {
        global $DB;
        static $n = 0;
        $n++;
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $gm = $this->getDataGenerator()->create_module('googlemeet', [
            'course' => $course->id,
            // Each activity its own room unless a test shares one on purpose.
            'url' => $url !== '' ? $url
                : 'https://meet.google.com/q' . chr(97 + intdiv($n, 26) % 26) . chr(97 + $n % 26) . '-defg-hij',
            'name' => 'Derecho administrativo',
            'eventdate' => make_timestamp(2026, 3, 2, 0, 0, 0, 'Europe/Madrid'),
            'starthour' => 18, 'startminute' => 30, 'endhour' => 20, 'endminute' => 0,
        ]);
        $DB->update_record('googlemeet', (object)[
            'id' => $gm->id, 'eventid' => self::eid($eventid), 'creatoremail' => $creator->email,
        ]);
        $cm = get_coursemodule_from_instance('googlemeet', $gm->id, $course->id, false, MUST_EXIST);
        return [$DB->get_record('googlemeet', ['id' => $gm->id]), $cm];
    }

    /**
     * Form-like data for googlemeet_update_instance().
     *
     * @param \stdClass $record
     * @param \stdClass $cm
     * @param array $changes
     * @return \stdClass
     */
    private function form_data(\stdClass $record, \stdClass $cm, array $changes): \stdClass {
        $data = clone $record;
        $data->instance = $record->id;
        $data->coursemodule = $cm->id;
        unset($data->addmultiply, $data->days);
        foreach ($changes as $key => $value) {
            $data->$key = $value;
        }
        return $data;
    }

    public function test_decode_event_id(): void {
        $this->assertSame('evt0123abc', calendar_sync::decode_event_id(self::eid('evt0123abc')));
        $this->assertSame('rawid_123', calendar_sync::decode_event_id('rawid_123'));
        // htmlLink of a recurring event may carry the first instance: the series id is used.
        $this->assertSame('evt0123abc', calendar_sync::decode_event_id(self::eid('evt0123abc_20260302T173000Z')));
        $this->assertSame('evt0123abc', calendar_sync::decode_event_id(self::eid('evt0123abc_20260302')));
        $this->assertSame('evt0123abc', calendar_sync::series_id('evt0123abc'));
        $this->assertSame('', calendar_sync::decode_event_id(null));
        $this->assertSame('', calendar_sync::decode_event_id('  '));
    }

    /**
     * Times are wall-clock in the timeZone sent; UNTIL is the end of the last local day in UTC.
     */
    public function test_build_event_body_timezone_and_recurrence(): void {
        $gm = (object)[
            'name' => 'Derecho administrativo',
            'eventdate' => make_timestamp(2026, 3, 2, 0, 0, 0, 'Europe/Madrid'),
            'starthour' => 18, 'startminute' => 30, 'endhour' => 20, 'endminute' => 0,
            'addmultiply' => 1, 'days' => json_encode(['Wed' => '1', 'Mon' => '1']), 'period' => 2,
            'eventenddate' => make_timestamp(2026, 6, 30, 0, 0, 0, 'Europe/Madrid'),
        ];

        $body = calendar_sync::build_event_body($gm, 'Europe/Madrid');
        $this->assertSame('Derecho administrativo', $body['summary']);
        $this->assertSame(['dateTime' => '2026-03-02T18:30:00', 'timeZone' => 'Europe/Madrid'], $body['start']);
        $this->assertSame(['dateTime' => '2026-03-02T20:00:00', 'timeZone' => 'Europe/Madrid'], $body['end']);
        // 30 June 23:59:59 CEST = 21:59:59 UTC.
        $this->assertSame(['RRULE:FREQ=WEEKLY;INTERVAL=2;UNTIL=20260630T215959Z;BYDAY=MO,WE'], $body['recurrence']);

        // Same instant in another zone: the wall-clock moves with the zone (never the server's).
        $this->setTimezone('Australia/Perth');
        $body = calendar_sync::build_event_body($gm, 'America/New_York');
        $this->assertSame(['dateTime' => '2026-03-02T12:30:00', 'timeZone' => 'America/New_York'], $body['start']);

        // Single session: no recurrence.
        $gm->addmultiply = 0;
        $this->assertSame([], calendar_sync::build_event_body($gm, 'Europe/Madrid')['recurrence']);
    }

    public function test_needs_patch(): void {
        $old = (object)['name' => 'A', 'eventdate' => 100, 'eventenddate' => 200, 'starthour' => 10, 'startminute' => 0,
            'endhour' => 11, 'endminute' => 0, 'addmultiply' => 1, 'days' => '{"Mon":"1"}', 'period' => 1, 'intro' => 'x'];
        $this->assertFalse(calendar_sync::needs_patch($old, (object)((array)$old + [])));
        $this->assertFalse(calendar_sync::needs_patch($old, (object)(['intro' => 'changed'] + (array)$old)));
        $this->assertTrue(calendar_sync::needs_patch($old, (object)(['name' => 'B'] + (array)$old)));
        $this->assertTrue(calendar_sync::needs_patch($old, (object)(['days' => '{"Tue":"1"}'] + (array)$old)));
        $this->assertFalse(calendar_sync::needs_patch($old, (object)(['days' => (object)['Mon' => 1]] + (array)$old)));
        $this->assertTrue(calendar_sync::needs_patch($old, (object)(['starthour' => 9] + (array)$old)));
        $this->assertTrue(calendar_sync::needs_patch($old, (object)(['addmultiply' => 0, 'days' => null] + (array)$old)));
    }

    /**
     * Editing the weekday queues one patch; after cron the event gets the new recurrence, as the creator.
     */
    public function test_update_instance_patches_event_after_cron(): void {
        global $DB;
        $creator = $this->getDataGenerator()->create_user(['email' => 'profe@example.com', 'timezone' => 'Europe/Madrid']);
        $editor = $this->getDataGenerator()->create_user();
        [$record, $cm] = $this->make_linked_activity($creator);

        // A save that does not touch the event does not queue anything.
        $this->setUser($editor);
        googlemeet_update_instance($this->form_data($record, $cm, ['intro' => 'Nueva descripción']));
        $this->assertEmpty(\core\task\manager::get_adhoc_tasks(calendar_update_event::class));

        $data = $this->form_data($record, $cm, [
            'name' => 'Derecho administrativo II',
            'addmultiply' => 1, 'days' => ['Tue' => '1'], 'period' => 1,
            'eventenddate' => make_timestamp(2026, 6, 30, 0, 0, 0, 'Europe/Madrid'),
        ]);
        googlemeet_update_instance($data);
        // Saving twice in a row collapses into a single task.
        googlemeet_update_instance($this->form_data($DB->get_record('googlemeet', ['id' => $record->id]), $cm,
            ['name' => 'Derecho administrativo II', 'addmultiply' => 1, 'days' => ['Tue' => '1'], 'period' => 1,
             'eventenddate' => make_timestamp(2026, 6, 30, 0, 0, 0, 'Europe/Madrid'), 'starthour' => 17]));
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(calendar_update_event::class));
        $this->assertEmpty($this->rest->calls, 'Nothing is sent to Google while saving.');

        ob_start();
        $this->runAdhocTasks(calendar_update_event::class);
        $output = ob_get_clean();
        $this->assertStringContainsString('op=update activity=' . $record->id . ' result=success', $output);

        $this->assertCount(1, $this->rest->calls);
        $call = $this->rest->calls[0];
        $this->assertSame('updateevent', $call['api']);
        $this->assertSame(['calendarid' => 'primary', 'eventid' => 'evt0123abc'], $call['params']);
        $this->assertSame('Derecho administrativo II', $call['body']['summary']);
        $this->assertSame(['dateTime' => '2026-03-02T17:30:00', 'timeZone' => 'Europe/Madrid'], $call['body']['start']);
        $this->assertSame(['RRULE:FREQ=WEEKLY;INTERVAL=1;UNTIL=20260630T215959Z;BYDAY=TU'], $call['body']['recurrence']);
        $this->assertSame([(int)$creator->id], $this->factoryusers, 'Runs with the creator token.');
        $this->assertSame('Derecho administrativo II', $DB->get_field('googlemeet', 'originalname', ['id' => $record->id]));
        $this->assertSame(sync_log::STATUS_SUCCESS, sync_log::latest((int)$record->id, sync_log::KIND_CALENDAR_UPDATE)->status);
    }

    /**
     * A Google failure never blocks saving: it is logged and the editor gets a notice.
     */
    public function test_update_failure_is_logged_and_notified(): void {
        $creator = $this->getDataGenerator()->create_user(['email' => 'profe@example.com']);
        $editor = $this->getDataGenerator()->create_user();
        [$record] = $this->make_linked_activity($creator);
        $this->rest->throw = new \Exception('403: Forbidden');

        $sink = $this->redirectMessages();
        ob_start();
        $result = calendar_sync::apply_update((int)$record->id, (int)$editor->id);
        $output = ob_get_clean();
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertSame('error', $result);
        $this->assertStringContainsString('result=error', $output);
        $this->assertCount(1, $messages);
        $this->assertEquals($editor->id, $messages[0]->useridto);
        $this->assertStringContainsString('403: Forbidden', $messages[0]->fullmessage);
        $row = sync_log::latest((int)$record->id, sync_log::KIND_CALENDAR_UPDATE);
        $this->assertSame(sync_log::STATUS_ERROR, $row->status);
        $this->assertStringContainsString('403', $row->message);
    }

    /**
     * Creator without a Google link: error + notice, no call.
     */
    public function test_update_without_google_link_notifies(): void {
        $creator = $this->getDataGenerator()->create_user(['email' => 'profe@example.com']);
        $editor = $this->getDataGenerator()->create_user();
        [$record] = $this->make_linked_activity($creator);
        calendar_sync::set_service_factory(fn() => null);

        $sink = $this->redirectMessages();
        ob_start();
        $this->assertSame('error', calendar_sync::apply_update((int)$record->id, (int)$editor->id));
        ob_end_clean();
        $this->assertCount(1, $sink->get_messages());
        $sink->close();
    }

    /**
     * Deleting the activity queues the deletion with the data captured before the record went away.
     */
    public function test_delete_instance_deletes_event_after_cron(): void {
        global $DB;
        set_config('coursebinenable', 0, 'tool_recyclebin');
        $creator = $this->getDataGenerator()->create_user(['email' => 'profe@example.com']);
        [$record, $cm] = $this->make_linked_activity($creator);

        course_delete_module($cm->id);
        $this->assertFalse($DB->record_exists('googlemeet', ['id' => $record->id]));
        $tasks = \core\task\manager::get_adhoc_tasks(calendar_delete_event::class);
        $this->assertCount(1, $tasks);
        $data = (array)reset($tasks)->get_custom_data();
        $this->assertSame($record->eventid, $data['eventid']);
        $this->assertSame('profe@example.com', $data['creatoremail']);

        ob_start();
        $this->runAdhocTasks(calendar_delete_event::class);
        $output = ob_get_clean();
        $this->assertStringContainsString('op=delete activity=' . $record->id . ' result=success', $output);
        $this->assertCount(1, $this->rest->calls);
        $this->assertSame('deleteevent', $this->rest->calls[0]['api']);
        $this->assertSame('evt0123abc', $this->rest->calls[0]['params']['eventid']);
    }

    /**
     * An event already deleted in Google (404/410) counts as done.
     */
    public function test_delete_already_gone_is_success(): void {
        $creator = $this->getDataGenerator()->create_user(['email' => 'profe@example.com']);
        $this->rest->throw = new \Exception('410: Resource has been deleted');
        ob_start();
        $result = calendar_sync::apply_delete(['googlemeetid' => 99, 'eventid' => self::eid('gone1'),
            'creatoremail' => $creator->email, 'name' => 'X', 'course' => 0, 'editorid' => 0]);
        ob_end_clean();
        $this->assertSame('success', $result);
    }

    /**
     * A duplicated activity sharing the Google event never deletes it, and edits are not pushed.
     */
    public function test_shared_event_is_left_alone(): void {
        global $DB;
        $creator = $this->getDataGenerator()->create_user(['email' => 'profe@example.com']);
        [$record] = $this->make_linked_activity($creator);
        [$copy] = $this->make_linked_activity($creator);
        $this->assertSame($record->eventid, $copy->eventid);

        $this->assertFalse(calendar_sync::queue_delete($copy, 0, 'activity'));
        $DB->delete_records('googlemeet', ['id' => $copy->id]);
        $this->assertEmpty(\core\task\manager::get_adhoc_tasks(calendar_delete_event::class));

        // Now unique again: deletion is queued.
        $this->assertTrue(calendar_sync::queue_delete($DB->get_record('googlemeet', ['id' => $record->id]), 0, 'activity'));
    }

    /**
     * A restored/copied activity (eventid dropped, same Meet URL) in another course protects the event,
     * also when it appears after the deletion was queued.
     */
    public function test_room_shared_by_url_is_left_alone(): void {
        global $DB;
        set_config('coursebinenable', 0, 'tool_recyclebin');
        $creator = $this->getDataGenerator()->create_user(['email' => 'profe@example.com']);
        [$record] = $this->make_linked_activity($creator, 'evtshared1', 'https://meet.google.com/aaa-bbbb-ccc');
        [$copy] = $this->make_linked_activity($creator, 'unused', 'https://meet.google.com/AAA-BBBB-CCC?authuser=1');
        $DB->set_field('googlemeet', 'eventid', null, ['id' => $copy->id]);

        $this->assertTrue(calendar_sync::is_room_shared((string)$record->eventid, $record->url, (int)$record->id));
        $this->assertFalse(calendar_sync::queue_delete($record, 0, 'activity'));

        // Queued while unique, but a copy shows up before the task runs: skipped at run time.
        $DB->set_field('googlemeet', 'url', 'https://meet.google.com/zzz-zzzz-zzz', ['id' => $copy->id]);
        $this->assertTrue(calendar_sync::queue_delete($record, 0, 'activity'));
        $DB->set_field('googlemeet', 'url', $record->url, ['id' => $copy->id]);
        $DB->delete_records('googlemeet', ['id' => $record->id]);
        ob_start();
        $this->runAdhocTasks(calendar_delete_event::class);
        $output = ob_get_clean();
        $this->assertStringContainsString('result=skipped', $output);
        $this->assertSame([], $this->rest->calls);
    }

    /**
     * Course deletion (and any path that is not an explicit activity deletion) never touches Google.
     */
    public function test_course_deletion_never_deletes_event(): void {
        set_config('coursebinenable', 0, 'tool_recyclebin');
        set_config('categorybinenable', 0, 'tool_recyclebin');
        $creator = $this->getDataGenerator()->create_user(['email' => 'profe@example.com']);
        [$record] = $this->make_linked_activity($creator);

        delete_course($record->course, false);
        $this->assertEmpty(\core\task\manager::get_adhoc_tasks(calendar_delete_event::class));

        // Direct callback call (other path): nothing queued either.
        [$other] = $this->make_linked_activity($creator, 'evtother');
        googlemeet_delete_instance($other->id);
        $this->assertEmpty(\core\task\manager::get_adhoc_tasks(calendar_delete_event::class));
    }

    /**
     * With the course recycle bin on, the deletion waits until the bin item has expired.
     */
    public function test_recycle_bin_delays_deletion(): void {
        set_config('coursebinenable', 1, 'tool_recyclebin');
        set_config('coursebinexpiry', WEEKSECS, 'tool_recyclebin');
        $this->assertSame(WEEKSECS + DAYSECS, calendar_sync::recyclebin_delay());
        $creator = $this->getDataGenerator()->create_user(['email' => 'profe@example.com']);
        [$record] = $this->make_linked_activity($creator);
        $this->assertTrue(calendar_sync::queue_delete($record, 0, 'activity'));
        $task = \core\task\manager::get_adhoc_tasks(calendar_delete_event::class);
        $this->assertGreaterThanOrEqual(time() + WEEKSECS, reset($task)->get_next_run_time());

        // A bin that never expires: never deleted.
        set_config('coursebinexpiry', 0, 'tool_recyclebin');
        $this->assertNull(calendar_sync::recyclebin_delay());
        [$other] = $this->make_linked_activity($creator, 'evtother');
        $this->assertFalse(calendar_sync::queue_delete($other, 0, 'activity'));
    }

    /**
     * Activities without a Google event (created without a linked account) queue nothing.
     */
    public function test_unlinked_activity_queues_nothing(): void {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $gm = $this->getDataGenerator()->create_module('googlemeet', ['course' => $course->id]);
        global $DB;
        $record = $DB->get_record('googlemeet', ['id' => $gm->id]);
        $this->assertFalse(calendar_sync::queue_update($record, 0));
        googlemeet_delete_instance($gm->id);
        $this->assertEmpty(\core\task\manager::get_adhoc_tasks(calendar_delete_event::class));
    }
}
