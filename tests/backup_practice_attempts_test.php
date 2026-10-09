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

use core_question\local\bank\question_version_status;
use mod_googlemeet\local\practice_attempts;
use PHPUnit\Framework\Attributes\CoversNothing;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Backup/restore of practice attempts (ANA-05): included with user data, ids remapped.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversNothing]
final class backup_practice_attempts_test extends \advanced_testcase {

    /**
     * A course backup with users restored as a new course keeps the attempts, bound to the new ids.
     */
    public function test_course_backup_restore_remaps_attempts(): void {
        global $DB, $USER;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('backup_general_users', 1, 'backup');

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $googlemeet = $gen->create_module('googlemeet', ['course' => $course->id, 'url' => 'https://meet.google.com/abc-defg-hij']);
        $cm = get_coursemodule_from_instance('googlemeet', $googlemeet->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $student = $gen->create_and_enrol($course, 'student');

        $recid = (int)$DB->insert_record('googlemeet_recordings', (object)[
            'googlemeetid' => $googlemeet->id, 'recordingid' => 'drive-x', 'name' => 'Class 1',
            'createdtime' => time(), 'duration' => '00:10:00', 'webviewlink' => 'https://drive.google.com/x',
            'visible' => 1, 'timemodified' => time(),
        ]);
        $qid = (new question_service())->create_draft_multichoice($googlemeet, $cm, $context, $recid, [
            'stem' => 'Restored question', 'options' => ['A', 'B', 'C', 'D'], 'correctindex' => 0,
            'explanation' => 'E', 'citation' => '',
        ]);
        $DB->set_field('question_versions', 'status', question_version_status::QUESTION_STATUS_READY, ['questionid' => $qid]);
        practice_attempts::record((int)$googlemeet->id, $recid, $qid, (int)$student->id, false, 1000);

        $bc = new \backup_controller(\backup::TYPE_1COURSE, $course->id, \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO, \backup::MODE_GENERAL, $USER->id);
        $bc->get_plan()->get_setting('users')->set_value(true);
        $bc->execute_plan();
        $file = $bc->get_results()['backup_destination'];
        $bc->destroy();

        $backupdir = 'googlemeet-ana05-' . random_string(6);
        $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), make_backup_temp_directory($backupdir));
        $newcourseid = \restore_dbops::create_new_course('Copy', 'ana05copy', $course->category);
        $rc = new \restore_controller($backupdir, $newcourseid, \backup::INTERACTIVE_NO, \backup::MODE_GENERAL,
            $USER->id, \backup::TARGET_NEW_COURSE);
        $rc->get_plan()->get_setting('users')->set_value(true);
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        $newgooglemeet = $DB->get_record('googlemeet', ['course' => $newcourseid], '*', MUST_EXIST);
        $newrec = $DB->get_record('googlemeet_recordings', ['googlemeetid' => $newgooglemeet->id], '*', MUST_EXIST);
        $attempts = $DB->get_records(practice_attempts::TABLE, ['googlemeetid' => $newgooglemeet->id]);
        $this->assertCount(1, $attempts);
        $attempt = reset($attempts);
        $this->assertEquals($newrec->id, $attempt->recordingid);
        $this->assertEquals($student->id, $attempt->userid);
        $this->assertEquals(0, $attempt->correct);
        $this->assertNotEquals($qid, $attempt->questionid);
        $this->assertTrue($DB->record_exists('question', ['id' => $attempt->questionid]));

        // The restored attempt drives "review my failed questions" in the copy.
        $newcm = get_coursemodule_from_instance('googlemeet', $newgooglemeet->id, $newcourseid, false, MUST_EXIST);
        $this->setUser($student);
        $questions = practice_attempts::get_session_questions($newgooglemeet, $newcm, \context_module::instance($newcm->id),
            (int)$student->id, practice_attempts::MODE_FAILED);
        $this->assertEquals([(int)$attempt->questionid], array_column($questions, 'questionid'));

        // Source untouched.
        $this->assertEquals(1, $DB->count_records(practice_attempts::TABLE, ['googlemeetid' => $googlemeet->id]));
    }
}
