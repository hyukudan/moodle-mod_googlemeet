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

use mod_googlemeet\completion\custom_completion;
use mod_googlemeet\local\completion_updater;
use mod_googlemeet\local\practice_attempts;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/lib.php');
require_once($CFG->dirroot . '/mod/googlemeet/classes/external.php');
require_once($CFG->libdir . '/completionlib.php');

/**
 * Tests for the custom completion rules (ANA-01).
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(custom_completion::class)]
#[CoversClass(completion_updater::class)]
final class custom_completion_test extends \advanced_testcase {

    /**
     * Course with completion and an activity with the given rules.
     *
     * @param array $rules Rule values.
     * @return array [course, googlemeet, cm, student]
     */
    private function setup_activity(array $rules): array {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $googlemeet = $this->getDataGenerator()->create_module('googlemeet', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
        ] + $rules);
        $cm = get_coursemodule_from_instance('googlemeet', $googlemeet->id, $course->id, false, MUST_EXIST);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        return [$course, $googlemeet, $cm, $student];
    }

    /**
     * Insert a recording.
     *
     * @param int $googlemeetid Instance id.
     * @param int $visible Visible flag.
     * @param int $deleted Deleted flag.
     * @return int
     */
    private function create_recording(int $googlemeetid, int $visible = 1, int $deleted = 0): int {
        global $DB;
        static $n = 0;
        $n++;
        return (int)$DB->insert_record('googlemeet_recordings', (object)[
            'googlemeetid' => $googlemeetid,
            'recordingid' => 'drive-' . $n,
            'name' => 'Class ' . $n,
            'createdtime' => time() - $n,
            'duration' => '1:00:00',
            'webviewlink' => 'https://drive.google.com/file/d/drive-' . $n . '/view',
            'visible' => $visible,
            'deleted' => $deleted,
            'timemodified' => time(),
        ]);
    }

    /**
     * Mark a recording as watched for a user.
     *
     * @param int $recordingid Recording id.
     * @param int $userid User id.
     */
    private function watch(int $recordingid, int $userid): void {
        global $DB;
        $DB->insert_record('googlemeet_recording_progress', (object)[
            'recordingid' => $recordingid,
            'userid' => $userid,
            'watchedseconds' => 600,
            'completed' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Build the custom completion object for a user.
     *
     * @param \stdClass $course Course.
     * @param \stdClass $cm Course module.
     * @param int $userid User id.
     * @return custom_completion
     */
    private function completion_for($course, $cm, int $userid): custom_completion {
        $cminfo = get_fast_modinfo($course->id, $userid)->get_cm($cm->id);
        return new custom_completion($cminfo, $userid);
    }

    public function test_feature_and_customdata(): void {
        [$course, , $cm] = $this->setup_activity(['completionrecordings' => 2, 'completionwatchpercent' => 0,
            'completionpractice' => 5]);

        $this->assertTrue(googlemeet_supports(FEATURE_COMPLETION_HAS_RULES));
        $cminfo = get_fast_modinfo($course->id)->get_cm($cm->id);
        $this->assertSame(2, $cminfo->customdata['customcompletionrules']['completionrecordings']);
        $this->assertSame(0, $cminfo->customdata['customcompletionrules']['completionwatchpercent']);
        $this->assertSame(5, $cminfo->customdata['customcompletionrules']['completionpractice']);

        $completion = new custom_completion($cminfo, (int)get_admin()->id);
        $this->assertEquals(['completionrecordings', 'completionpractice'], $completion->get_available_custom_rules());
        $this->assertSame(['completionview', 'completionrecordings', 'completionwatchpercent', 'completionpractice'],
            $completion->get_sort_order());
        $descriptions = mod_googlemeet_get_completion_active_rule_descriptions($cminfo);
        $this->assertCount(2, $descriptions);
    }

    public function test_recordings_rule_counts_only_visible_classes(): void {
        [$course, $googlemeet, $cm, $student] = $this->setup_activity(['completionrecordings' => 2]);

        $visible = $this->create_recording($googlemeet->id);
        $hidden = $this->create_recording($googlemeet->id, 0);
        $trashed = $this->create_recording($googlemeet->id, 1, 1);
        $this->watch($visible, $student->id);
        $this->watch($hidden, $student->id);
        $this->watch($trashed, $student->id);

        $this->assertSame(1, custom_completion::count_watched($googlemeet->id, $student->id));
        $this->assertSame(COMPLETION_INCOMPLETE,
            $this->completion_for($course, $cm, $student->id)->get_state('completionrecordings'));

        $this->watch($this->create_recording($googlemeet->id), $student->id);
        $this->assertSame(COMPLETION_COMPLETE,
            $this->completion_for($course, $cm, $student->id)->get_state('completionrecordings'));
    }

    public function test_watch_percent_rule(): void {
        [$course, $googlemeet, $cm, $student] = $this->setup_activity(['completionwatchpercent' => 50]);

        // No visible recordings: nothing to watch, never complete.
        $this->assertFalse(custom_completion::watched_percent_met($googlemeet->id, $student->id, 50));

        $ids = [];
        for ($i = 0; $i < 4; $i++) {
            $ids[] = $this->create_recording($googlemeet->id);
        }
        $this->create_recording($googlemeet->id, 0); // Hidden: not in the denominator.

        $this->watch($ids[0], $student->id);
        $this->assertSame(COMPLETION_INCOMPLETE,
            $this->completion_for($course, $cm, $student->id)->get_state('completionwatchpercent'));

        $this->watch($ids[1], $student->id);
        $this->assertSame(COMPLETION_COMPLETE,
            $this->completion_for($course, $cm, $student->id)->get_state('completionwatchpercent'));
    }

    public function test_practice_rule_counts_distinct_questions(): void {
        [$course, $googlemeet, $cm, $student] = $this->setup_activity(['completionpractice' => 2]);
        $recordingid = $this->create_recording($googlemeet->id);

        practice_attempts::record($googlemeet->id, $recordingid, 101, $student->id, false);
        practice_attempts::record($googlemeet->id, $recordingid, 101, $student->id, true);
        $this->assertSame(1, custom_completion::count_answered_questions($googlemeet->id, $student->id));
        $this->assertSame(COMPLETION_INCOMPLETE,
            $this->completion_for($course, $cm, $student->id)->get_state('completionpractice'));

        practice_attempts::record($googlemeet->id, $recordingid, 102, $student->id, false);
        $this->assertSame(COMPLETION_COMPLETE,
            $this->completion_for($course, $cm, $student->id)->get_state('completionpractice'));
    }

    public function test_progress_ws_completes_activity_without_reload(): void {
        [$course, $googlemeet, $cm, $student] = $this->setup_activity(['completionrecordings' => 1]);
        $recordingid = $this->create_recording($googlemeet->id);

        $this->setUser($student);
        $completion = new \completion_info($course);
        $this->assertEquals(COMPLETION_INCOMPLETE, $completion->get_data($cm, false, $student->id)->completionstate);

        \mod_googlemeet_external::mark_recording_progress($recordingid, $cm->id, 0, true);

        $completion = new \completion_info($course);
        $this->assertEquals(COMPLETION_COMPLETE, $completion->get_data($cm, false, $student->id)->completionstate);
    }

    public function test_updater_is_noop_without_relevant_rule(): void {
        [, , $cm, $student] = $this->setup_activity(['completionpractice' => 3]);
        $this->assertFalse(completion_updater::progress_changed($cm, $student->id));
        $this->assertTrue(completion_updater::practice_changed($cm, $student->id));

        $cm->completion = COMPLETION_TRACKING_MANUAL;
        $this->assertFalse(completion_updater::practice_changed($cm, $student->id));
    }
}
