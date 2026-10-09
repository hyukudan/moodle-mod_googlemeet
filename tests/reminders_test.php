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

use mod_googlemeet\local\reminders;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/lib.php');
require_once($CFG->dirroot . '/mod/googlemeet/locallib.php');

/**
 * Pre-session reminders: cron-gap tolerant window (NOT-02), second reminder deduplicated
 * separately (NOT-04), push data and localised content (NOT-03, QA-03).
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(reminders::class)]
final class reminders_test extends \advanced_testcase {

    /** @var int Fixed reference time for the tests. */
    private int $now;

    /**
     * Course + activity with reminders on and one enrolled student.
     *
     * @param array $settings Activity settings (notify, minutesbefore, notifyhoursbefore).
     * @return array [course, googlemeet, student]
     */
    private function create_activity(array $settings = []): array {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $googlemeet = $gen->create_module('googlemeet', array_merge([
            'course' => $course->id,
            'name' => 'Constitution, Title VIII',
            'notify' => 1,
            'minutesbefore' => 10,
            'notifyhoursbefore' => 24,
        ], $settings));
        $student = $gen->create_user(['firstname' => 'Ana', 'lang' => 'en', 'timezone' => 'Europe/Madrid']);
        $gen->enrol_user($student->id, $course->id, 'student');
        return [$course, $googlemeet, $student];
    }

    /**
     * Insert a session.
     *
     * @param int $googlemeetid Activity id.
     * @param int $eventdate Start.
     * @return int Event id.
     */
    private function add_event(int $googlemeetid, int $eventdate): int {
        global $DB;
        return (int)$DB->insert_record('googlemeet_events', (object)[
            'googlemeetid' => $googlemeetid,
            'eventdate' => $eventdate,
            'duration' => HOURSECS,
            'timemodified' => $this->now,
        ]);
    }

    /**
     * Map of "eventid:kind" for the due reminders.
     *
     * @param int $now Reference time.
     * @return string[]
     */
    private function due_keys(int $now): array {
        $keys = array_map(static function($event) {
            return $event->id . ':' . $event->kind;
        }, reminders::get_due_events($now));
        sort($keys);
        return $keys;
    }

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->now = time();
    }

    /**
     * NOT-02: a run that comes back after a cron gap still sends the reminder while the session
     * has not started; started sessions and sessions outside the window are not due.
     */
    public function test_minutes_window_tolerates_cron_gap(): void {
        [, $googlemeet] = $this->create_activity(['notifyhoursbefore' => 0]);

        // Window opened 7 minutes ago (10-minute reminder, starts in 3): still due.
        $late = $this->add_event($googlemeet->id, $this->now + 3 * MINSECS);
        // Already started: not due.
        $started = $this->add_event($googlemeet->id, $this->now - MINSECS);
        // Window not open yet.
        $future = $this->add_event($googlemeet->id, $this->now + 20 * MINSECS);

        $keys = $this->due_keys($this->now);
        $this->assertContains($late . ':' . reminders::KIND_MINUTES, $keys);
        $this->assertNotContains($started . ':' . reminders::KIND_MINUTES, $keys);
        $this->assertNotContains($future . ':' . reminders::KIND_MINUTES, $keys);
        $this->assertCount(1, $keys);
    }

    /**
     * NOT-04: the hours reminder is due inside its window, and gives way to the minutes reminder
     * once that one is due (a late run never sends both at once).
     */
    public function test_hours_window_and_handover(): void {
        [, $googlemeet] = $this->create_activity();

        $tomorrow = $this->add_event($googlemeet->id, $this->now + 20 * HOURSECS);
        $soon = $this->add_event($googlemeet->id, $this->now + 5 * MINSECS);
        $far = $this->add_event($googlemeet->id, $this->now + 30 * HOURSECS);

        $expected = [$soon . ':' . reminders::KIND_MINUTES, $tomorrow . ':' . reminders::KIND_HOURS];
        sort($expected);
        // Not due: the early reminder of the imminent session, and anything for the far one.
        $this->assertSame($expected, $this->due_keys($this->now));
        $this->assertNotEmpty($far);
    }

    /**
     * Disabled reminders, an "off" early reminder and cancelled sessions are not due.
     */
    public function test_disabled_and_cancelled(): void {
        global $DB;

        [, $off] = $this->create_activity(['notify' => 0]);
        $this->add_event($off->id, $this->now + 5 * MINSECS);

        [, $noearly] = $this->create_activity(['notifyhoursbefore' => 0]);
        $this->add_event($noearly->id, $this->now + 20 * HOURSECS);

        [, $cancelledgm] = $this->create_activity();
        $this->add_event($cancelledgm->id, $this->now + 5 * MINSECS);
        $DB->insert_record('googlemeet_cancelled', (object)[
            'googlemeetid' => $cancelledgm->id,
            'cancelleddate' => $this->now + 5 * MINSECS,
            'reason' => 'Holiday',
            'timemodified' => $this->now,
        ]);

        $this->assertSame([], $this->due_keys($this->now));
    }

    /**
     * Each reminder kind is sent once per student and session; the early and the late
     * reminder are deduplicated separately.
     */
    public function test_run_dedupes_each_kind_separately(): void {
        global $DB;

        [, $googlemeet, $student] = $this->create_activity();
        $start = $this->now + 20 * HOURSECS;
        $eventid = $this->add_event($googlemeet->id, $start);

        $sink = $this->redirectMessages();

        // 20 h before: the early reminder.
        $this->assertSame(1, reminders::run($this->now));
        $this->assertSame(0, reminders::run($this->now + MINSECS));

        // 5 minutes before: the late reminder, once.
        $this->assertSame(1, reminders::run($start - 5 * MINSECS));
        $this->assertSame(0, reminders::run($start - 4 * MINSECS));

        $messages = $sink->get_messages();
        $this->assertCount(2, $messages);
        foreach ($messages as $message) {
            $this->assertEquals($student->id, $message->useridto);
        }
        $this->assertSame(2, $DB->count_records('googlemeet_notify_done', ['eventid' => $eventid,
            'userid' => $student->id]));
        $this->assertTrue($DB->record_exists('googlemeet_notify_done', ['eventid' => $eventid,
            'userid' => $student->id, 'kind' => reminders::KIND_HOURS]));
        $this->assertTrue($DB->record_exists('googlemeet_notify_done', ['eventid' => $eventid,
            'userid' => $student->id, 'kind' => reminders::KIND_MINUTES]));
    }

    /**
     * Recipients are filtered per kind: a student notified for one kind still gets the other.
     */
    public function test_recipients_per_kind(): void {
        [, $googlemeet, $student] = $this->create_activity();
        $eventid = $this->add_event($googlemeet->id, $this->now + HOURSECS);

        googlemeet_notify_done($student->id, $eventid, reminders::KIND_HOURS);
        $this->assertArrayNotHasKey($student->id, googlemeet_get_users_to_notify($eventid, reminders::KIND_HOURS));
        $this->assertArrayHasKey($student->id, googlemeet_get_users_to_notify($eventid, reminders::KIND_MINUTES));
    }

    /**
     * Message content: localised subject without emoji, plain and HTML bodies, and the data the
     * Moodle app needs to open the activity from a push notification.
     */
    public function test_message_content(): void {
        [$course, $googlemeet, $student] = $this->create_activity();
        $eventid = $this->add_event($googlemeet->id, $this->now + 20 * HOURSECS);

        $due = array_values(array_filter(reminders::get_due_events($this->now), static function($event) use ($eventid) {
            return (int)$event->id === $eventid;
        }));
        $this->assertCount(1, $due);
        $message = reminders::build_message($student, $due[0]);

        $this->assertSame('notification', $message->name);
        $this->assertStringStartsWith('Upcoming live class: Constitution, Title VIII', $message->subject);
        $this->assertSame(0, preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $message->subject));
        $this->assertSame(FORMAT_PLAIN, (int)$message->fullmessageformat);
        $this->assertStringNotContainsString('<p>', $message->fullmessage);
        $this->assertStringContainsString('Ana', $message->fullmessagehtml);
        $this->assertStringContainsString('/mod/googlemeet/view.php?id=' . $googlemeet->cmid, $message->contexturl);
        $customdata = is_string($message->customdata) ? json_decode($message->customdata, true)
            : (array)$message->customdata;
        $this->assertEquals($googlemeet->cmid, $customdata['cmid']);
        $this->assertEquals($course->id, $customdata['courseid']);

        $due[0]->kind = reminders::KIND_MINUTES;
        $this->assertStringStartsWith('Starting soon: ', reminders::build_message($student, $due[0])->subject);
    }

    /**
     * Message providers allow push (airnotifier) for the reminder and the recording notice.
     */
    public function test_message_providers_allow_push(): void {
        global $CFG;
        $messageproviders = [];
        require($CFG->dirroot . '/mod/googlemeet/db/messages.php');
        foreach (['notification', 'recordingavailable'] as $provider) {
            $this->assertSame(MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
                $messageproviders[$provider]['defaults']['airnotifier']);
        }
        $this->assertArrayHasKey('autosyncfailed', $messageproviders);
    }
}
