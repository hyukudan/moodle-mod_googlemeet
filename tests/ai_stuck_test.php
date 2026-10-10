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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversFunction;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/lib.php');
require_once($CFG->dirroot . '/mod/googlemeet/locallib.php');

/**
 * F-8: analyses stuck in 'processing' are visible to teachers, retryable and cleaned up by cron.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(ai_service::class)]
#[CoversClass(\mod_googlemeet\task\process_ai_analysis::class)]
#[CoversFunction('googlemeet_list_recordings')]
#[CoversFunction('googlemeet_print_recording_hub')]
final class ai_stuck_test extends \advanced_testcase {

    /** @var \stdClass */
    private $course;
    /** @var \stdClass */
    private $googlemeet;
    /** @var \stdClass */
    private $cm;

    /**
     * Course and activity.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->googlemeet = $this->getDataGenerator()->create_module('googlemeet', ['course' => $this->course->id]);
        $this->cm = get_coursemodule_from_instance('googlemeet', $this->googlemeet->id, $this->course->id, false, MUST_EXIST);
    }

    /**
     * Insert a recording and its analysis.
     *
     * @param string $status Analysis status.
     * @param int $age Seconds since the last status change.
     * @param int $retrycount Retry counter.
     * @return \stdClass Analysis row.
     */
    private function create_analysis(string $status, int $age, int $retrycount = 0): \stdClass {
        global $DB;
        $now = time();
        $recordingid = $DB->insert_record('googlemeet_recordings', (object)[
            'googlemeetid' => $this->googlemeet->id, 'recordingid' => uniqid('drive-', true), 'name' => 'Clase',
            'createdtime' => $now, 'duration' => '01:00:00', 'webviewlink' => 'https://drive.google.com/file/d/x/view',
            'visible' => 1, 'timemodified' => $now,
        ]);
        $id = $DB->insert_record('googlemeet_ai_analysis', (object)[
            'recordingid' => $recordingid, 'status' => $status, 'retrycount' => $retrycount, 'nextretry' => 0,
            'timecreated' => $now - $age, 'timemodified' => $now - $age,
        ]);
        return $DB->get_record('googlemeet_ai_analysis', ['id' => $id]);
    }

    /**
     * Threshold: default 60 min, configurable, never below 45 min.
     */
    public function test_threshold_and_is_stuck(): void {
        $this->assertSame(60 * MINSECS, ai_service::get_stuck_threshold());
        set_config('aistuckminutes', 90, 'googlemeet');
        $this->assertSame(90 * MINSECS, ai_service::get_stuck_threshold());
        set_config('aistuckminutes', 15, 'googlemeet');
        $this->assertSame(45 * MINSECS, ai_service::get_stuck_threshold());
        set_config('aistuckminutes', 60, 'googlemeet');

        $this->assertTrue(ai_service::is_stuck($this->create_analysis('processing', 2 * HOURSECS)));
        $this->assertFalse(ai_service::is_stuck($this->create_analysis('processing', 10 * MINSECS)));
        $this->assertFalse(ai_service::is_stuck($this->create_analysis('completed', 2 * HOURSECS)));
        $this->assertFalse(ai_service::is_stuck($this->create_analysis('pending', 2 * HOURSECS)));
        $this->assertFalse(ai_service::is_stuck(null));
    }

    /**
     * The scheduled cleanup marks stale 'processing' rows failed (with a clear error and a bounded retry)
     * and leaves healthy rows alone, even when AI is disabled.
     */
    public function test_scheduled_cleanup_marks_stale_failed(): void {
        global $DB;
        $stale = $this->create_analysis('processing', 2 * HOURSECS);
        $exhausted = $this->create_analysis('processing', 2 * HOURSECS, ai_service::MAX_TRANSIENT_RETRIES);
        $fresh = $this->create_analysis('processing', 5 * MINSECS);
        set_config('enableai', 0, 'googlemeet');

        $task = new \mod_googlemeet\task\process_ai_analysis();
        ob_start();
        $task->execute();
        ob_end_clean();

        $row = $DB->get_record('googlemeet_ai_analysis', ['id' => $stale->id]);
        $this->assertSame('failed', $row->status);
        $this->assertSame(get_string('ai_error_stuck', 'googlemeet', 60), $row->error);
        $this->assertEquals(1, $row->retrycount);
        $this->assertGreaterThan(time(), (int)$row->nextretry);

        $row = $DB->get_record('googlemeet_ai_analysis', ['id' => $exhausted->id]);
        $this->assertSame('failed', $row->status);
        $this->assertEquals(ai_service::PERMANENT_RETRYCOUNT, $row->retrycount);

        $this->assertSame('processing', $DB->get_field('googlemeet_ai_analysis', 'status', ['id' => $fresh->id]));
    }

    /**
     * A teacher can retry a stuck analysis (it is re-queued); a healthy one is not double-queued.
     */
    public function test_generate_requeues_only_stuck_rows(): void {
        global $DB;
        $stuck = $this->create_analysis('processing', 2 * HOURSECS, 2);
        $healthy = $this->create_analysis('processing', 5 * MINSECS);
        $service = new ai_service();

        $result = $service->generate_analysis((int)$healthy->recordingid, true);
        $this->assertEquals($healthy->timemodified, $result->timemodified);
        $this->assertEmpty(\core\task\manager::get_adhoc_tasks('\\mod_googlemeet\\task\\process_video_analysis'));

        $service->generate_analysis((int)$stuck->recordingid, true);
        $row = $DB->get_record('googlemeet_ai_analysis', ['id' => $stuck->id]);
        $this->assertSame('processing', $row->status);
        $this->assertEquals(0, $row->retrycount);
        $this->assertGreaterThanOrEqual(time() - 5, (int)$row->timemodified);
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks('\\mod_googlemeet\\task\\process_video_analysis'));
    }

    /**
     * Teachers see "stuck" (list chip / hub alert with retry); students only see "in progress".
     */
    public function test_teacher_sees_stuck(): void {
        global $PAGE;
        set_config('enableai', 1, 'googlemeet');
        set_config('geminiapikey', 'test-key', 'googlemeet');
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $stuck = $this->create_analysis('processing', 2 * HOURSECS);
        $context = \context_module::instance($this->cm->id);
        $recording = $GLOBALS['DB']->get_record('googlemeet_recordings', ['id' => $stuck->recordingid]);

        $render = function() use ($context, $recording) {
            global $PAGE;
            $PAGE = new \moodle_page();
            $PAGE->set_url('/mod/googlemeet/view.php', ['id' => $this->cm->id, 'recording' => $recording->id]);
            $PAGE->set_cm($this->cm);
            $PAGE->set_context($context);
            ob_start();
            googlemeet_print_recording_hub($this->googlemeet, $this->cm, $context, $recording);
            return ob_get_clean();
        };

        $this->setUser($teacher);
        $list = googlemeet_list_recordings(['googlemeetid' => $this->googlemeet->id], true);
        $this->assertTrue($list[0]->aistatusisstuck);
        $this->assertFalse($list[0]->aistatusisprocessing);
        $html = $render();
        $this->assertStringContainsString('data-action="ai-stuck-retry"', $html);
        $this->assertStringContainsString(s(get_string('ai_status_stuck', 'googlemeet', 60)), $html);

        $this->setUser($student);
        $list = googlemeet_list_recordings(['googlemeetid' => $this->googlemeet->id], true);
        $this->assertFalse($list[0]->aistatusisstuck);
        $this->assertTrue($list[0]->aistatusisprocessing);
        $this->assertStringNotContainsString('data-action="ai-stuck-retry"', $render());
    }
}
