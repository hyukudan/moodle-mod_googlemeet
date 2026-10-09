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
use mod_googlemeet\external\log_room_entered;
use mod_googlemeet\local\room_entry;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the room_entered event and its entry points (ANA-02).
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\mod_googlemeet\event\room_entered::class)]
#[CoversClass(room_entry::class)]
#[CoversClass(log_room_entered::class)]
final class room_entered_test extends \advanced_testcase {

    /**
     * Activity + student.
     *
     * @param string $url Meet URL.
     * @return array [googlemeet, cm, student]
     */
    private function setup_activity(string $url = 'https://meet.google.com/abc-defg-hij'): array {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $googlemeet = $this->getDataGenerator()->create_module('googlemeet', ['course' => $course->id]);
        $DB->set_field('googlemeet', 'url', $url, ['id' => $googlemeet->id]);
        $googlemeet = $DB->get_record('googlemeet', ['id' => $googlemeet->id]);
        $cm = get_coursemodule_from_instance('googlemeet', $googlemeet->id, $course->id, false, MUST_EXIST);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        return [$googlemeet, $cm, $student];
    }

    /**
     * Insert a session.
     *
     * @param int $googlemeetid Instance id.
     * @param int $start Start timestamp.
     * @param int $duration Duration in seconds.
     * @return int
     */
    private function create_session(int $googlemeetid, int $start, int $duration = HOURSECS): int {
        global $DB;
        return (int)$DB->insert_record('googlemeet_events', (object)[
            'googlemeetid' => $googlemeetid,
            'eventdate' => $start,
            'duration' => $duration,
            'timemodified' => time(),
        ]);
    }

    public function test_current_session_window(): void {
        [$googlemeet] = $this->setup_activity();
        $now = time();
        $this->create_session($googlemeet->id, $now + 2 * HOURSECS);
        $this->assertNull(room_entry::current_session($googlemeet->id, $now));

        $soon = $this->create_session($googlemeet->id, $now + 10 * MINSECS);
        $this->assertSame($soon, (int)room_entry::current_session($googlemeet->id, $now)->id);

        $live = $this->create_session($googlemeet->id, $now - 10 * MINSECS);
        $this->assertSame($live, (int)room_entry::current_session($googlemeet->id, $now)->id);
    }

    public function test_log_triggers_event_with_session(): void {
        [$googlemeet, $cm, $student] = $this->setup_activity();
        $sessionid = $this->create_session($googlemeet->id, time() - 5 * MINSECS);
        $this->setUser($student);

        $sink = $this->redirectEvents();
        $logged = room_entry::log($googlemeet, $cm, \context_module::instance($cm->id), 'web');
        $events = $sink->get_events();
        $sink->close();

        $this->assertSame($sessionid, $logged);
        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertInstanceOf(\mod_googlemeet\event\room_entered::class, $event);
        $this->assertEquals($student->id, $event->userid);
        $this->assertEquals($cm->id, $event->contextinstanceid);
        $this->assertEquals($googlemeet->id, $event->objectid);
        $this->assertSame($sessionid, $event->other['sessionid']);
        $this->assertSame('web', $event->other['via']);
        $this->assertStringContainsString((string)$sessionid, $event->get_description());
        $this->assertEquals(new \moodle_url('/mod/googlemeet/view.php', ['id' => $cm->id]), $event->get_url());
    }

    public function test_ws_logs_and_returns_url(): void {
        [$googlemeet, $cm, $student] = $this->setup_activity();
        $this->setUser($student);

        $sink = $this->redirectEvents();
        $result = log_room_entered::execute($cm->id, 'mobile');
        $result = external_api::clean_returnvalue(log_room_entered::execute_returns(), $result);
        $events = $sink->get_events();
        $sink->close();

        $this->assertTrue($result['logged']);
        $this->assertSame(0, $result['sessionid']);
        $this->assertSame('https://meet.google.com/abc-defg-hij', $result['url']);
        $this->assertCount(1, $events);
        $this->assertSame('mobile', reset($events)->other['via']);
    }

    public function test_ws_does_not_log_invalid_room(): void {
        [, $cm, $student] = $this->setup_activity('https://example.com/not-a-room');
        $this->setUser($student);

        $sink = $this->redirectEvents();
        $result = log_room_entered::execute($cm->id, 'mobile');
        $events = $sink->get_events();
        $sink->close();

        $this->assertFalse($result['logged']);
        $this->assertSame('', $result['url']);
        $this->assertCount(0, $events);
    }

    public function test_hero_room_cta_goes_through_entry_endpoint(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/googlemeet/locallib.php');
        [$googlemeet, $cm] = $this->setup_activity();
        $this->setAdminUser();

        $context = \context_module::instance($cm->id);
        $cta = googlemeet_get_room_cta_context($googlemeet, $context, null, true);
        $this->assertTrue($cta['hasroomcta']);
        $this->assertStringContainsString('/mod/googlemeet/enter.php?id=' . $cm->id, $cta['roomurl']);
        $this->assertStringContainsString('sesskey=' . sesskey(), $cta['roomurl']);
    }
}
