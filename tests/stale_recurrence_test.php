<?php
namespace mod_googlemeet;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/lib.php');
require_once($CFG->dirroot . '/mod/googlemeet/locallib.php');

/**
 * Tests for the abandoned-recurrence detector + alert task.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class stale_recurrence_test extends \advanced_testcase {

    /** Insert a googlemeet_events row. */
    private function add_event(int $gmid, int $eventdate): void {
        global $DB;
        $DB->insert_record('googlemeet_events', (object) [
            'googlemeetid' => $gmid,
            'eventdate' => $eventdate,
            'duration' => 7200,
            'timemodified' => $eventdate,
            'autosynced' => 0,
            'syncattempts' => 0,
        ]);
    }

    /** Insert a googlemeet_recordings row. */
    private function add_recording(int $gmid, int $createdtime, int $deleted = 0): void {
        global $DB;
        $DB->insert_record('googlemeet_recordings', (object) [
            'googlemeetid' => $gmid,
            'recordingid' => 'rec-' . $gmid . '-' . $createdtime,
            'name' => 'Recording',
            'createdtime' => $createdtime,
            'duration' => '1:00:00',
            'webviewlink' => 'https://drive.google.com/file/d/x/view',
            'transcripttext' => '',
            'transcriptfileid' => '',
            'notestext' => '',
            'notesdocid' => '',
            'visible' => 1,
            'deleted' => $deleted,
            'timedeleted' => 0,
            'timemodified' => $createdtime,
        ]);
    }

    /** Create a googlemeet module and return its instance record (has ->id and ->cmid). */
    private function make_module(): \stdClass {
        // add_instance() mirrors the session into the core calendar (calendar_event::create),
        // which requires a user with calendar-manage capability; run as admin in tests.
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        return $this->getDataGenerator()->create_module('googlemeet', ['course' => $course->id]);
    }

    public function test_detects_abandoned_recurrence(): void {
        $this->resetAfterTest();
        $now = 1800000000;
        $gm = $this->make_module();
        $this->add_event($gm->id, $now + DAYSECS);          // future session
        $this->add_recording($gm->id, $now - 30 * DAYSECS); // last recording 30d ago

        $stale = googlemeet_get_stale_recurrences(3, $now);

        $this->assertCount(1, $stale);
        $this->assertEquals($gm->id, $stale[0]->id);
        $this->assertEquals($gm->cmid, $stale[0]->cmid);
        $this->assertEquals(1, (int) $stale[0]->futurecount);
    }

    public function test_ignores_recent_recording(): void {
        $this->resetAfterTest();
        $now = 1800000000;
        $gm = $this->make_module();
        $this->add_event($gm->id, $now + DAYSECS);
        $this->add_recording($gm->id, $now - 5 * DAYSECS); // recent
        $this->assertCount(0, googlemeet_get_stale_recurrences(3, $now));
    }

    public function test_ignores_when_no_future_sessions(): void {
        $this->resetAfterTest();
        $now = 1800000000;
        $gm = $this->make_module();
        $this->add_event($gm->id, $now - 10 * DAYSECS); // only past
        $this->add_recording($gm->id, $now - 60 * DAYSECS);
        $this->assertCount(0, googlemeet_get_stale_recurrences(3, $now));
    }

    public function test_ignores_when_never_recorded(): void {
        $this->resetAfterTest();
        $now = 1800000000;
        $gm = $this->make_module();
        $this->add_event($gm->id, $now + DAYSECS);
        // no recordings at all
        $this->assertCount(0, googlemeet_get_stale_recurrences(3, $now));
    }

    public function test_ignores_when_only_deleted_recordings(): void {
        $this->resetAfterTest();
        $now = 1800000000;
        $gm = $this->make_module();
        $this->add_event($gm->id, $now + DAYSECS);
        $this->add_recording($gm->id, $now - 60 * DAYSECS, 1); // deleted
        $this->assertCount(0, googlemeet_get_stale_recurrences(3, $now));
    }

    public function test_send_stale_alert_notifies_admins(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $sink = $this->redirectMessages();

        $info = (object) [
            'id' => 123,
            'name' => 'Live classes',
            'course' => 7,
            'coursename' => 'Test course',
            'cmid' => 456,
            'lastrecording' => 1795000000,
            'futurecount' => 3,
        ];

        googlemeet_send_stale_alert($info);

        $messages = $sink->get_messages();
        $this->assertCount(count(get_admins()), $messages);
        $this->assertEquals('mod_googlemeet', $messages[0]->component);
        $this->assertEquals('stalerecurrence', $messages[0]->eventtype);
        $this->assertStringContainsString('Live classes', $messages[0]->subject);
        $this->assertStringContainsString('update=456', $messages[0]->fullmessagehtml);
    }

    /** Run the scheduled task, swallowing its mtrace output. */
    private function run_task(): void {
        $task = new \mod_googlemeet\task\check_stale_recurrence();
        ob_start();
        $task->execute();
        ob_end_clean();
    }

    public function test_task_notifies_once_then_respects_renotify(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();

        set_config('stalerecurrence_enabled', 1, 'googlemeet');
        set_config('stalerecurrence_weeks', 3, 'googlemeet');
        set_config('stalerecurrence_renotifydays', 28, 'googlemeet');

        $gm = $this->make_module();
        $this->add_event($gm->id, time() + DAYSECS);
        $this->add_recording($gm->id, time() - 40 * DAYSECS);

        // First run: notifies once and records state.
        $sink = $this->redirectMessages();
        $this->run_task();
        $this->assertCount(count(get_admins()), $sink->get_messages());
        $this->assertNotEmpty(get_config('googlemeet', 'stalealert_' . $gm->id));
        $sink->close();

        // Second run (within renotify window): no new message.
        $sink = $this->redirectMessages();
        $this->run_task();
        $this->assertCount(0, $sink->get_messages());
        $sink->close();

        // Age the last-alert timestamp beyond the renotify window: notifies again.
        set_config('stalealert_' . $gm->id, time() - 40 * DAYSECS, 'googlemeet');
        $sink = $this->redirectMessages();
        $this->run_task();
        $this->assertCount(count(get_admins()), $sink->get_messages());
        $sink->close();
    }

    public function test_task_clears_state_when_no_longer_stale(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();

        set_config('stalerecurrence_enabled', 1, 'googlemeet');
        set_config('stalerecurrence_weeks', 3, 'googlemeet');
        set_config('stalerecurrence_renotifydays', 28, 'googlemeet');

        $gm = $this->make_module();
        $eventid = $DB->insert_record('googlemeet_events', (object) [
            'googlemeetid' => $gm->id, 'eventdate' => time() + DAYSECS,
            'duration' => 7200, 'timemodified' => time(), 'autosynced' => 0, 'syncattempts' => 0,
        ]);
        $this->add_recording($gm->id, time() - 40 * DAYSECS);

        $this->run_task();
        $this->assertNotEmpty(get_config('googlemeet', 'stalealert_' . $gm->id));

        // Remove the future session → no longer stale → state must be cleared.
        $DB->delete_records('googlemeet_events', ['id' => $eventid]);
        $this->run_task();
        $this->assertFalse(get_config('googlemeet', 'stalealert_' . $gm->id));
    }

    public function test_task_noop_when_disabled(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        set_config('stalerecurrence_enabled', 0, 'googlemeet');

        $gm = $this->make_module();
        $this->add_event($gm->id, time() + DAYSECS);
        $this->add_recording($gm->id, time() - 40 * DAYSECS);

        $sink = $this->redirectMessages();
        $this->run_task();
        $this->assertCount(0, $sink->get_messages());
    }

    public function test_task_enabled_by_default_when_unset(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        // Do NOT set stalerecurrence_enabled: get_config() returns false (unpersisted default),
        // which must behave as ON.
        set_config('stalerecurrence_weeks', 3, 'googlemeet');
        set_config('stalerecurrence_renotifydays', 28, 'googlemeet');

        $gm = $this->make_module();
        $this->add_event($gm->id, time() + DAYSECS);
        $this->add_recording($gm->id, time() - 40 * DAYSECS);

        $sink = $this->redirectMessages();
        $this->run_task();
        $this->assertCount(count(get_admins()), $sink->get_messages());
    }
}
