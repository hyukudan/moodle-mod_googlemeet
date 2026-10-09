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

use PHPUnit\Framework\Attributes\CoversFunction;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/lib.php');
require_once($CFG->dirroot . '/mod/googlemeet/locallib.php');

/**
 * Recipients of pre-session reminders (NOT-01).
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversFunction('googlemeet_get_users_to_notify')]
final class notify_users_test extends \advanced_testcase {

    /**
     * Create a course with a googlemeet activity and one future event.
     *
     * @return array [course, googlemeet record, event id]
     */
    private function create_fixture(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $googlemeet = $this->getDataGenerator()->create_module('googlemeet', ['course' => $course->id]);
        $eventid = (int)$DB->insert_record('googlemeet_events', (object)[
            'googlemeetid' => $googlemeet->id,
            'eventdate' => time() + HOURSECS,
            'duration' => HOURSECS,
            'timemodified' => time(),
        ]);
        return [$course, $googlemeet, $eventid];
    }

    /**
     * Active student is notified; suspended, other-course, teacher and non-editing
     * teacher users are not.
     */
    public function test_recipients_basic(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();

        [$course, , $eventid] = $this->create_fixture();
        $othercourse = $gen->create_course();

        $active = $gen->create_user();
        $gen->enrol_user($active->id, $course->id, 'student');

        $suspendedenrol = $gen->create_user();
        $gen->enrol_user($suspendedenrol->id, $course->id, 'student', 'manual', 0, 0, ENROL_USER_SUSPENDED);

        $suspendeduser = $gen->create_user(['suspended' => 1]);
        $gen->enrol_user($suspendeduser->id, $course->id, 'student');

        $other = $gen->create_user();
        $gen->enrol_user($other->id, $othercourse->id, 'student');

        $teacher = $gen->create_user();
        $gen->enrol_user($teacher->id, $course->id, 'editingteacher');

        // Non-editing teacher: can view the activity but is not a student.
        $noneditingteacher = $gen->create_user();
        $gen->enrol_user($noneditingteacher->id, $course->id, 'teacher');

        $users = googlemeet_get_users_to_notify($eventid);
        $ids = array_keys($users);

        $this->assertSame([(int)$active->id], array_map('intval', $ids));
    }

    /**
     * A role assignment in a non-course context whose instanceid equals the course id
     * must not turn the user into a recipient (the original contextlevel bug).
     */
    public function test_non_course_context_with_matching_instanceid_is_ignored(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();

        [$course, , $eventid] = $this->create_fixture();

        // Create categories until one has the same id as the course.
        $category = null;
        for ($i = 0; $i < 50; $i++) {
            $category = $gen->create_category();
            if ($category->id >= $course->id) {
                break;
            }
        }
        if ((int)$category->id !== (int)$course->id) {
            $this->markTestSkipped('Could not align a category id with the course id.');
        }

        $intruder = $gen->create_user();
        $studentroleid = (int)$DB->get_field('role', 'id', ['shortname' => 'student']);
        role_assign($studentroleid, $intruder->id, \context_coursecat::instance($category->id)->id);

        $this->assertArrayNotHasKey($intruder->id, googlemeet_get_users_to_notify($eventid));
    }

    /**
     * Users who do not meet the activity's access restrictions are not notified.
     */
    public function test_availability_restricted_user_is_not_notified(): void {
        global $DB, $CFG;
        $this->resetAfterTest();
        $CFG->enableavailability = 1;
        $gen = $this->getDataGenerator();

        [$course, $googlemeet, $eventid] = $this->create_fixture();
        $student = $gen->create_user();
        $gen->enrol_user($student->id, $course->id, 'student');

        $this->assertArrayHasKey($student->id, googlemeet_get_users_to_notify($eventid));

        // Restrict the activity until a date in the future.
        $availability = json_encode([
            'op' => '&',
            'c' => [['type' => 'date', 'd' => '>=', 't' => time() + 30 * DAYSECS]],
            'showc' => [true],
        ]);
        $DB->set_field('course_modules', 'availability', $availability, ['id' => $googlemeet->cmid]);
        rebuild_course_cache($course->id, true);

        $this->assertArrayNotHasKey($student->id, googlemeet_get_users_to_notify($eventid));
    }

    /**
     * Users already recorded in googlemeet_notify_done are excluded.
     */
    public function test_already_notified_user_is_excluded(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();

        [$course, , $eventid] = $this->create_fixture();
        $student = $gen->create_user();
        $gen->enrol_user($student->id, $course->id, 'student');

        $this->assertArrayHasKey($student->id, googlemeet_get_users_to_notify($eventid));
        googlemeet_notify_done($student->id, $eventid);
        $this->assertArrayNotHasKey($student->id, googlemeet_get_users_to_notify($eventid));
    }

    /**
     * Hidden activities send no reminders.
     */
    public function test_hidden_activity_notifies_nobody(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();

        [$course, $googlemeet, $eventid] = $this->create_fixture();
        $student = $gen->create_user();
        $gen->enrol_user($student->id, $course->id, 'student');

        set_coursemodule_visible($googlemeet->cmid, 0);
        $this->assertSame([], googlemeet_get_users_to_notify($eventid));
    }
}
