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
}
