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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/lib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * AI analysis travels with backups without user data; the transcript only with user data.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\backup_googlemeet_activity_structure_step::class)]
#[CoversClass(\restore_googlemeet_activity_structure_step::class)]
final class backup_ai_analysis_test extends \advanced_testcase {

    /**
     * Course, activity, one recording with a completed analysis and one with a pending analysis.
     *
     * @return array [course, cm]
     */
    private function create_fixture(): array {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $googlemeet = $this->getDataGenerator()->create_module('googlemeet', ['course' => $course->id, 'name' => 'AI room']);
        $cm = get_coursemodule_from_instance('googlemeet', $googlemeet->id, $course->id, false, MUST_EXIST);

        foreach (['done' => 'completed', 'queued' => 'pending'] as $driveid => $status) {
            $recordingid = $DB->insert_record('googlemeet_recordings', (object)[
                'googlemeetid' => $googlemeet->id,
                'recordingid' => $driveid,
                'name' => 'AI room ' . $driveid,
                'createdtime' => time(),
                'duration' => '1:56:51',
                'webviewlink' => 'https://drive.google.com/file/d/' . $driveid . '/view',
                'transcripttext' => 'Meet transcript of ' . $driveid,
                'notestext' => '<p>Notes</p>',
                'visible' => 1,
                'timemodified' => time(),
            ]);
            $DB->insert_record('googlemeet_ai_analysis', (object)[
                'recordingid' => $recordingid,
                'summary' => 'Summary of ' . $driveid,
                'keypoints' => json_encode(['Point A', 'Point B']),
                'transcript' => 'AI transcript with student voices',
                'topics' => json_encode(['Constitución']),
                'chapters' => json_encode([['time' => '00:00', 'title' => 'Intro']]),
                'language' => 'es',
                'status' => $status,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }
        return [$course, $cm];
    }

    /**
     * Analysis of the copied recording.
     *
     * @param int $googlemeetid Copy id.
     * @param string $driveid Drive id.
     * @return \stdClass|false
     */
    private function copied_analysis(int $googlemeetid, string $driveid) {
        global $DB;
        $recording = $DB->get_record('googlemeet_recordings', ['googlemeetid' => $googlemeetid, 'recordingid' => $driveid],
            '*', MUST_EXIST);
        return $DB->get_record('googlemeet_ai_analysis', ['recordingid' => $recording->id]);
    }

    /**
     * Duplicating an activity (no user data) keeps the summary but not the transcript.
     */
    public function test_duplicate_keeps_summary_not_transcript(): void {
        global $DB;
        [$course, $cm] = $this->create_fixture();

        $newcm = duplicate_module($course, get_fast_modinfo($course)->get_cm($cm->id));

        $analysis = $this->copied_analysis((int)$newcm->instance, 'done');
        $this->assertNotEmpty($analysis);
        $this->assertSame('Summary of done', $analysis->summary);
        $this->assertSame(['Point A', 'Point B'], json_decode($analysis->keypoints, true));
        $this->assertSame(['Constitución'], json_decode($analysis->topics, true));
        $this->assertNotEmpty($analysis->chapters);
        $this->assertSame('completed', $analysis->status);
        $this->assertEmpty($analysis->transcript);

        // A queued analysis is not copied (the copy would run its own paid AI job).
        $this->assertFalse($this->copied_analysis((int)$newcm->instance, 'queued'));

        // Recording-level personal data stays out too; the numeric duration is derived (DAT-05).
        $recording = $DB->get_record('googlemeet_recordings', ['googlemeetid' => $newcm->instance, 'recordingid' => 'done']);
        $this->assertEmpty($recording->transcripttext);
        $this->assertEmpty($recording->notestext);
        $this->assertEquals(7011, (int)$recording->durationseconds);
    }

    /**
     * A course backup with user data restores the transcript as well.
     */
    public function test_backup_with_userdata_keeps_transcript(): void {
        global $DB, $USER;
        [$course] = $this->create_fixture();

        $bc = new \backup_controller(\backup::TYPE_1COURSE, $course->id, \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO, \backup::MODE_GENERAL, $USER->id);
        $bc->get_plan()->get_setting('users')->set_value(true);
        $bc->execute_plan();
        $file = $bc->get_results()['backup_destination'];
        $bc->destroy();

        $backupdir = 'googlemeet-ai-' . random_string(6);
        $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), make_backup_temp_directory($backupdir));
        $newcourseid = \restore_dbops::create_new_course('Copy', 'aicopy', $course->category);
        $rc = new \restore_controller($backupdir, $newcourseid, \backup::INTERACTIVE_NO, \backup::MODE_GENERAL,
            $USER->id, \backup::TARGET_NEW_COURSE);
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        $newgooglemeet = $DB->get_record('googlemeet', ['course' => $newcourseid], '*', MUST_EXIST);
        $analysis = $this->copied_analysis((int)$newgooglemeet->id, 'done');
        $this->assertSame('Summary of done', $analysis->summary);
        $this->assertSame('AI transcript with student voices', $analysis->transcript);
        $this->assertNotEmpty($this->copied_analysis((int)$newgooglemeet->id, 'queued'));
    }
}
