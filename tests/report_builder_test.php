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

use mod_googlemeet\local\practice_attempts;
use mod_googlemeet\local\report_builder;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/lib.php');

/**
 * Tests for the teacher viewing report data builder (ANA-04) and its capability.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\mod_googlemeet\local\report_builder::class)]
#[CoversClass(\mod_googlemeet\output\report_page::class)]
final class report_builder_test extends \advanced_testcase {

    /**
     * Insert a recording.
     *
     * @param int $googlemeetid Activity id.
     * @param string $duration Duration string.
     * @param int $deleted Soft-deleted flag.
     * @return int
     */
    private function create_recording(int $googlemeetid, string $duration = '00:10:00', int $deleted = 0): int {
        global $DB;
        static $n = 0;
        $n++;
        return (int)$DB->insert_record('googlemeet_recordings', (object)[
            'googlemeetid' => $googlemeetid,
            'recordingid' => 'drive-' . $n . '-' . random_string(6),
            'name' => 'Class ' . $n,
            'createdtime' => 1000 + $n,
            'duration' => $duration,
            'webviewlink' => 'https://drive.google.com/file/d/example/view',
            'visible' => 1,
            'deleted' => $deleted,
            'timemodified' => time(),
        ]);
    }

    /**
     * Insert a progress row.
     *
     * @param int $recordingid Recording.
     * @param int $userid User.
     * @param int $watched Watched seconds.
     * @param int $completed Completed flag.
     * @param int $time Timemodified.
     */
    private function progress(int $recordingid, int $userid, int $watched, int $completed, int $time): void {
        global $DB;
        $DB->insert_record('googlemeet_recording_progress', (object)[
            'recordingid' => $recordingid,
            'userid' => $userid,
            'watchedseconds' => $watched,
            'completed' => $completed,
            'timecreated' => $time,
            'timemodified' => $time,
        ]);
    }

    /**
     * Matrix counts match googlemeet_recording_progress; teachers and trashed recordings are excluded;
     * the inactivity and group filters work.
     */
    public function test_build_matrix_counts_and_filters(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $now = 2000000000;

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $googlemeet = $gen->create_module('googlemeet', ['course' => $course->id, 'url' => 'https://meet.google.com/abc-defg-hij']);
        $cm = get_coursemodule_from_instance('googlemeet', $googlemeet->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        $s1 = $gen->create_and_enrol($course, 'student', ['firstname' => 'Ana', 'lastname' => 'A']);
        $s2 = $gen->create_and_enrol($course, 'student', ['firstname' => 'Bea', 'lastname' => 'B']);
        $s3 = $gen->create_and_enrol($course, 'student', ['firstname' => 'Carla', 'lastname' => 'C']);
        $teacher = $gen->create_and_enrol($course, 'editingteacher');
        $group = $gen->create_group(['courseid' => $course->id]);
        $gen->create_group_member(['groupid' => $group->id, 'userid' => $s1->id]);

        // 10 min recording -> completion threshold 360 s (60 %, capped at 8 min).
        $r1 = $this->create_recording($googlemeet->id, '00:10:00');
        $r2 = $this->create_recording($googlemeet->id, '00:10:00');
        $trashed = $this->create_recording($googlemeet->id, '00:10:00', 1);

        $this->progress($r1, $s1->id, 500, 1, $now - DAYSECS);
        $this->progress($r2, $s1->id, 180, 0, $now - 2 * DAYSECS);
        $this->progress($r1, $s2->id, 36, 0, $now - 20 * DAYSECS);
        $this->progress($trashed, $s3->id, 400, 1, $now - DAYSECS);
        $this->progress($r1, $teacher->id, 400, 1, $now);

        $builder = new report_builder($googlemeet, $cm, $context);
        $data = $builder->build(0, false, $now);

        $this->assertEquals([$r1, $r2], array_keys($data['recordings']));
        $this->assertEquals(3, $data['totalstudents']);
        $this->assertArrayNotHasKey($teacher->id, $data['rows']);

        $row1 = $data['rows'][$s1->id];
        $this->assertEquals(2, $row1['opened']);
        $this->assertEquals(1, $row1['completed']);
        $this->assertEquals(100, $row1['cells'][$r1]['pct']);
        $this->assertEquals(50, $row1['cells'][$r2]['pct']);
        $this->assertEquals($now - DAYSECS, $row1['lastaccess']);
        $this->assertFalse($row1['inactive']);

        $row2 = $data['rows'][$s2->id];
        $this->assertEquals(10, $row2['cells'][$r1]['pct']);
        $this->assertTrue($row2['cells'][$r1]['opened']);
        $this->assertFalse($row2['cells'][$r2]['opened']);
        $this->assertTrue($row2['inactive']);

        // Progress only on a trashed recording: no visible activity, never accessed.
        $row3 = $data['rows'][$s3->id];
        $this->assertEquals(0, $row3['opened']);
        $this->assertEquals(0, $row3['lastaccess']);
        $this->assertTrue($row3['inactive']);

        $this->assertEquals(['opened' => 2, 'completed' => 1], $data['recordingstats'][$r1]);
        $this->assertEquals(['opened' => 1, 'completed' => 0], $data['recordingstats'][$r2]);

        // A recent practice attempt counts as activity.
        practice_attempts::record((int)$googlemeet->id, $r1, 1, (int)$s2->id, true, $now - 3600);
        $data = $builder->build(0, true, $now);
        $this->assertEquals([$s3->id], array_keys($data['rows']));

        $data = $builder->build((int)$group->id, false, $now);
        $this->assertEquals([$s1->id], array_keys($data['rows']));

        // Export table: one row per student, one column per recording with the percentage.
        $table = report_builder::export_table($builder->build(0, false, $now), ['email']);
        $this->assertCount(3, $table['rows']);
        $this->assertArrayHasKey('rec' . $r2, $table['columns']);
        $this->assertEquals('1/2', $table['rows'][0]['completed']);
        $this->assertEquals(50, $table['rows'][0]['rec' . $r2]);
        $this->assertEquals($s1->email, $table['rows'][0]['email']);

        // The template context renders without errors.
        $page = new output\report_page($builder->build(0, false, $now), $googlemeet, $cm, $context,
            ['groupid' => 0, 'groups' => [], 'inactive' => 0, 'page' => 0, 'perpage' => 50], []);
        $ctx = $page->export_for_template($GLOBALS['PAGE']->get_renderer('core'));
        $this->assertCount(3, $ctx['rows']);
        // 1 completed cell out of 3 students x 2 recordings.
        $this->assertEquals(17, $ctx['avgpct']);
    }

    /**
     * Only teachers/managers get mod/googlemeet:viewreports by default.
     */
    public function test_viewreports_capability(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $googlemeet = $gen->create_module('googlemeet', ['course' => $course->id, 'url' => 'https://meet.google.com/abc-defg-hij']);
        $context = \context_module::instance($googlemeet->cmid);

        $student = $gen->create_and_enrol($course, 'student');
        $teacher = $gen->create_and_enrol($course, 'teacher');
        $editingteacher = $gen->create_and_enrol($course, 'editingteacher');
        $manager = $gen->create_and_enrol($course, 'manager');

        $this->assertFalse(has_capability('mod/googlemeet:viewreports', $context, $student));
        $this->assertTrue(has_capability('mod/googlemeet:viewreports', $context, $teacher));
        $this->assertTrue(has_capability('mod/googlemeet:viewreports', $context, $editingteacher));
        $this->assertTrue(has_capability('mod/googlemeet:viewreports', $context, $manager));
    }
}
