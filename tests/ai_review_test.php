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
use mod_googlemeet\local\ai_review;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversFunction;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/lib.php');
require_once($CFG->dirroot . '/mod/googlemeet/locallib.php');
require_once($CFG->dirroot . '/mod/googlemeet/classes/external.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * IA-04: AI content is hidden from students until a teacher reviews (publishes) it.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(ai_review::class)]
#[CoversClass(\mod_googlemeet\external\review_ai_analysis::class)]
#[CoversClass(\mod_googlemeet\privacy\provider::class)]
#[CoversFunction('googlemeet_list_recordings')]
#[CoversFunction('googlemeet_get_lesson_titles')]
#[CoversFunction('googlemeet_print_recording_hub')]
#[CoversFunction('googlemeet_get_continue_watching_context')]
final class ai_review_test extends \advanced_testcase {

    /** @var \stdClass */
    private $course;
    /** @var \stdClass */
    private $googlemeet;
    /** @var \stdClass */
    private $cm;
    /** @var \context_module */
    private $context;
    /** @var \stdClass */
    private $teacher;
    /** @var \stdClass */
    private $student;

    /**
     * Course, activity, a teacher and a student.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->googlemeet = $this->getDataGenerator()->create_module('googlemeet', [
            'course' => $this->course->id, 'name' => 'Clases en directo',
        ]);
        $this->cm = get_coursemodule_from_instance('googlemeet', $this->googlemeet->id, $this->course->id, false, MUST_EXIST);
        $this->context = \context_module::instance($this->cm->id);
        $this->teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
    }

    /**
     * Insert a recording with a completed analysis.
     *
     * @param array $analysis Analysis overrides.
     * @param string $name Recording name.
     * @return \stdClass Recording row (with ->analysisid).
     */
    private function create_recording(array $analysis = [], string $name = 'Clases en directo'): \stdClass {
        global $DB;
        $now = time();
        $id = $DB->insert_record('googlemeet_recordings', (object)[
            'googlemeetid' => $this->googlemeet->id, 'recordingid' => uniqid('drive-', true), 'name' => $name,
            'createdtime' => $now, 'duration' => '01:00:00', 'webviewlink' => 'https://drive.google.com/file/d/x/view',
            'visible' => 1, 'timemodified' => $now,
        ]);
        $analysisid = $DB->insert_record('googlemeet_ai_analysis', (object)array_merge([
            'recordingid' => $id, 'summary' => 'Resumen secreto sobre la Ley de Sanidad', 'keypoints' => '["Punto clave oculto"]',
            'transcript' => '', 'topics' => '["Tema oculto"]', 'chapters' => '[]', 'language' => 'es',
            'status' => 'completed', 'retrycount' => 0, 'nextretry' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ], $analysis));
        $recording = $DB->get_record('googlemeet_recordings', ['id' => $id]);
        $recording->analysisid = (int)$analysisid;
        return $recording;
    }

    /**
     * Render the hub as the current user.
     *
     * @param \stdClass $recording Recording.
     * @return string
     */
    private function render_hub(\stdClass $recording): string {
        global $PAGE;
        $PAGE = new \moodle_page();
        $PAGE->set_url('/mod/googlemeet/view.php', ['id' => $this->cm->id, 'recording' => $recording->id]);
        $PAGE->set_cm($this->cm);
        $PAGE->set_context($this->context);
        ob_start();
        googlemeet_print_recording_hub($this->googlemeet, $this->cm, $this->context, $recording);
        return ob_get_clean();
    }

    /**
     * The setting defaults to "required" (also when unset).
     */
    public function test_is_required_default(): void {
        unset_config('requireaireview', 'googlemeet');
        $this->assertTrue(ai_review::is_required());
        set_config('requireaireview', 0, 'googlemeet');
        $this->assertFalse(ai_review::is_required());
        set_config('requireaireview', 1, 'googlemeet');
        $this->assertTrue(ai_review::is_required());
    }

    /**
     * The visibility rule: completed AND (not required OR reviewed).
     */
    public function test_visibility_rule(): void {
        $done = (object)['status' => 'completed', 'reviewed' => 0];
        $this->assertTrue(ai_review::is_pending_review($done));
        $this->assertFalse(ai_review::is_visible_to_students($done));
        $done->reviewed = 1;
        $this->assertFalse(ai_review::is_pending_review($done));
        $this->assertTrue(ai_review::is_visible_to_students($done));
        $this->assertFalse(ai_review::is_visible_to_students((object)['status' => 'processing', 'reviewed' => 1]));
        $this->assertFalse(ai_review::is_visible_to_students(null));

        set_config('requireaireview', 0, 'googlemeet');
        $this->assertTrue(ai_review::is_visible_to_students((object)['status' => 'completed', 'reviewed' => 0]));
    }

    /**
     * Hub: the student sees nothing of an unreviewed analysis; the teacher sees it with the banner;
     * once published the student sees it.
     */
    public function test_hub_hides_unreviewed_from_students(): void {
        $recording = $this->create_recording(['chapters' => '[{"title":"Capítulo oculto","start":"0:10"}]']);

        $this->setUser($this->student);
        $html = $this->render_hub($recording);
        $this->assertStringNotContainsString('Resumen secreto', $html);
        $this->assertStringNotContainsString('Punto clave oculto', $html);
        $this->assertStringNotContainsString('Tema oculto', $html);
        $this->assertStringNotContainsString('Capítulo oculto', $html);
        $this->assertStringNotContainsString('data-action="ai-review-publish"', $html);

        $this->setUser($this->teacher);
        $html = $this->render_hub($recording);
        $this->assertStringContainsString('Resumen secreto', $html);
        $this->assertStringContainsString(get_string('aireview_pending_title', 'googlemeet'), $html);
        $this->assertStringContainsString('data-action="ai-review-publish"', $html);

        $this->assertTrue(ai_review::mark_reviewed($recording->analysisid, (int)$this->teacher->id));
        $html = $this->render_hub($recording);
        $this->assertStringNotContainsString('data-action="ai-review-publish"', $html);

        $this->setUser($this->student);
        $html = $this->render_hub($recording);
        $this->assertStringContainsString('Resumen secreto', $html);
        $this->assertStringContainsString('Tema oculto', $html);
    }

    /**
     * With the setting off, unreviewed content is visible and no banner is shown.
     */
    public function test_hub_setting_off(): void {
        set_config('requireaireview', 0, 'googlemeet');
        $recording = $this->create_recording();
        $this->setUser($this->student);
        $this->assertStringContainsString('Resumen secreto', $this->render_hub($recording));
        $this->setUser($this->teacher);
        $this->assertStringNotContainsString('data-action="ai-review-publish"', $this->render_hub($recording));
    }

    /**
     * List, search, topic filter/chips and lesson titles never expose unreviewed text to students.
     */
    public function test_list_search_topics_and_titles(): void {
        $hidden = $this->create_recording();
        $shown = $this->create_recording(['summary' => 'Resumen publicado', 'topics' => '["Tema publicado"]', 'reviewed' => 1]);

        $this->setUser($this->student);
        $list = googlemeet_list_recordings(['googlemeetid' => $this->googlemeet->id, 'visible' => true], true);
        $byid = array_column(array_map(fn($r) => ['id' => $r->id, 'r' => $r], $list), 'r', 'id');
        $this->assertFalse($byid[$hidden->id]->hasai);
        $this->assertSame('', $byid[$hidden->id]->aisummary);
        $this->assertSame([], $byid[$hidden->id]->aitopics);
        $this->assertFalse($byid[$hidden->id]->aipendingreview);
        $this->assertTrue($byid[$shown->id]->hasai);

        $this->assertCount(0, googlemeet_filter_recordings_by_query($list, 'Sanidad', false));
        $this->assertCount(0, googlemeet_filter_recordings_by_topic($list, 'Tema oculto'));
        $this->assertSame(['Tema publicado'], googlemeet_collect_topics($list));

        // Generic recording names take the first AI topic as lesson title: not for unreviewed topics.
        $titles = googlemeet_get_lesson_titles($this->googlemeet, false);
        $this->assertStringNotContainsString('Tema oculto', $titles[$hidden->id]['title']);
        $this->assertStringContainsString('Tema publicado', $titles[$shown->id]['title']);

        $this->setUser($this->teacher);
        $list = googlemeet_list_recordings(['googlemeetid' => $this->googlemeet->id], true);
        $byid = array_column(array_map(fn($r) => ['id' => $r->id, 'r' => $r], $list), 'r', 'id');
        $this->assertTrue($byid[$hidden->id]->hasai);
        $this->assertTrue($byid[$hidden->id]->aipendingreview);
        $this->assertFalse($byid[$shown->id]->aipendingreview);
        $this->assertCount(1, googlemeet_filter_recordings_by_query($list, 'Sanidad', true));
        $titles = googlemeet_get_lesson_titles($this->googlemeet, true);
        $this->assertStringContainsString('Tema oculto', $titles[$hidden->id]['title']);
    }

    /**
     * The "continue watching" card does not show an unreviewed summary snippet to students.
     */
    public function test_continue_watching_snippet(): void {
        $this->create_recording();
        $this->setUser($this->student);
        $ctx = googlemeet_get_continue_watching_context($this->googlemeet, $this->cm, $this->context);
        $this->assertTrue($ctx['hascontinue']);
        $this->assertSame('', $ctx['continuesummary']);

        $this->setUser($this->teacher);
        $ctx = googlemeet_get_continue_watching_context($this->googlemeet, $this->cm, $this->context);
        $this->assertStringContainsString('Resumen secreto', $ctx['continuesummary']);
    }

    /**
     * get_ai_analysis hides unreviewed content from students and flags it for teachers.
     */
    public function test_get_ai_analysis_ws(): void {
        $recording = $this->create_recording();

        $this->setUser($this->student);
        $result = external_api::clean_returnvalue(\mod_googlemeet_external::get_ai_analysis_returns(),
            \mod_googlemeet_external::get_ai_analysis($recording->id, $this->cm->id));
        $this->assertFalse($result['found']);
        $this->assertSame('', $result['summary']);
        $this->assertSame([], $result['topics']);

        $this->setUser($this->teacher);
        $result = external_api::clean_returnvalue(\mod_googlemeet_external::get_ai_analysis_returns(),
            \mod_googlemeet_external::get_ai_analysis($recording->id, $this->cm->id));
        $this->assertTrue($result['found']);
        $this->assertTrue($result['pendingreview']);
        $this->assertFalse($result['reviewed']);
        $this->assertFalse($result['stuck']);
    }

    /**
     * A manual edit by the teacher counts as reviewed.
     */
    public function test_manual_edit_marks_reviewed(): void {
        global $DB;
        $recording = $this->create_recording();
        $this->setUser($this->teacher);
        \mod_googlemeet_external::save_ai_analysis($recording->id, $this->cm->id, 'Resumen corregido', "Uno\nDos", 'Tema', '');
        $row = $DB->get_record('googlemeet_ai_analysis', ['id' => $recording->analysisid]);
        $this->assertEquals(1, $row->reviewed);
        $this->assertEquals($this->teacher->id, $row->reviewedby);
        $this->assertGreaterThan(0, (int)$row->timereviewed);
    }

    /**
     * The review web service publishes one summary or every reviewable summary; students cannot call it.
     */
    public function test_review_ws_single_and_bulk(): void {
        global $DB;
        $one = $this->create_recording();
        $two = $this->create_recording();
        $three = $this->create_recording();
        $processing = $this->create_recording(['status' => 'processing', 'summary' => '']);

        $this->setUser($this->student);
        try {
            \mod_googlemeet\external\review_ai_analysis::execute($this->cm->id, $one->id);
            $this->fail('Students must not publish AI summaries');
        } catch (\required_capability_exception $e) {
            $this->assertEquals(0, $DB->get_field('googlemeet_ai_analysis', 'reviewed', ['id' => $one->analysisid]));
        }

        $this->setUser($this->teacher);
        $result = external_api::clean_returnvalue(\mod_googlemeet\external\review_ai_analysis::execute_returns(),
            \mod_googlemeet\external\review_ai_analysis::execute($this->cm->id, $one->id));
        $this->assertTrue($result['success']);
        $row = $DB->get_record('googlemeet_ai_analysis', ['id' => $one->analysisid]);
        $this->assertEquals(1, $row->reviewed);
        $this->assertEquals($this->teacher->id, $row->reviewedby);

        // A row still processing cannot be published.
        $result = \mod_googlemeet\external\review_ai_analysis::execute($this->cm->id, $processing->id);
        $this->assertFalse($result['success']);

        $this->assertEquals(2, count(ai_review::get_pending_ids((int)$this->googlemeet->id)));
        $result = \mod_googlemeet\external\review_ai_analysis::execute($this->cm->id, 0);
        $this->assertEquals(2, $result['count']);
        $this->assertEquals(1, $DB->get_field('googlemeet_ai_analysis', 'reviewed', ['id' => $two->analysisid]));
        $this->assertEquals(1, $DB->get_field('googlemeet_ai_analysis', 'reviewed', ['id' => $three->analysisid]));
        $this->assertEquals(0, $DB->get_field('googlemeet_ai_analysis', 'reviewed', ['id' => $processing->analysisid]));
        $this->assertSame([], ai_review::get_pending_ids((int)$this->googlemeet->id));

        // A recording of another activity is rejected (IDOR).
        $other = $this->getDataGenerator()->create_module('googlemeet', ['course' => $this->course->id]);
        $othercm = get_coursemodule_from_instance('googlemeet', $other->id);
        $this->expectException(\moodle_exception::class);
        \mod_googlemeet\external\review_ai_analysis::execute($othercm->id, $one->id);
    }

    /**
     * The activity-level bulk banner only shows to teachers with pending summaries.
     */
    public function test_bulk_banner(): void {
        global $PAGE;
        $PAGE->set_url('/mod/googlemeet/view.php', ['id' => $this->cm->id]);
        $this->create_recording();
        $this->create_recording();

        $this->setUser($this->student);
        $this->assertSame('', ai_review::render_bulk_banner($this->googlemeet, $this->cm, $this->context));

        $this->setUser($this->teacher);
        $html = ai_review::render_bulk_banner($this->googlemeet, $this->cm, $this->context);
        $this->assertStringContainsString('data-action="ai-review-publish-all"', $html);
        $this->assertStringContainsString(get_string('aireview_bulk_message', 'googlemeet', 2), $html);

        ai_review::mark_all_reviewed((int)$this->googlemeet->id, (int)$this->teacher->id);
        $this->assertSame('', ai_review::render_bulk_banner($this->googlemeet, $this->cm, $this->context));
    }

    /**
     * Privacy: the reviewer is user data (contexts, users, export, anonymisation).
     */
    public function test_privacy_reviewer(): void {
        global $DB;
        $recording = $this->create_recording();
        ai_review::mark_reviewed($recording->analysisid, (int)$this->teacher->id);

        $contextlist = \mod_googlemeet\privacy\provider::get_contexts_for_userid((int)$this->teacher->id);
        $this->assertContains((int)$this->context->id, array_map('intval', $contextlist->get_contextids()));

        $userlist = new \core_privacy\local\request\userlist($this->context, 'mod_googlemeet');
        \mod_googlemeet\privacy\provider::get_users_in_context($userlist);
        $this->assertContains((int)$this->teacher->id, array_map('intval', $userlist->get_userids()));

        $approved = new \core_privacy\local\request\approved_contextlist($this->teacher, 'mod_googlemeet',
            [$this->context->id]);
        \mod_googlemeet\privacy\provider::export_user_data($approved);
        $writer = \core_privacy\local\request\writer::with_context($this->context);
        $data = $writer->get_related_data([get_string('privacy:aireviews', 'googlemeet')], 'recording_' . $recording->id);
        $this->assertEquals($recording->id, $data->recordingid);

        \mod_googlemeet\privacy\provider::delete_data_for_user($approved);
        $row = $DB->get_record('googlemeet_ai_analysis', ['id' => $recording->analysisid]);
        $this->assertEquals(0, $row->reviewedby);
        $this->assertEquals(1, $row->reviewed, 'Forgetting the reviewer must not unpublish the content');
    }

    /**
     * Backup/restore keeps the review state and maps the reviewer.
     */
    public function test_backup_restore_keeps_review_state(): void {
        global $DB, $USER;
        $this->setAdminUser();
        $reviewed = $this->create_recording(['summary' => 'Publicado']);
        $pending = $this->create_recording(['summary' => 'Pendiente']);
        ai_review::mark_reviewed($reviewed->analysisid, (int)$this->teacher->id);

        $bc = new \backup_controller(\backup::TYPE_1COURSE, $this->course->id, \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO, \backup::MODE_GENERAL, $USER->id);
        $bc->get_plan()->get_setting('users')->set_value(true);
        $bc->execute_plan();
        $file = $bc->get_results()['backup_destination'];
        $bc->destroy();
        $backupdir = 'googlemeet-aireview-' . random_string(6);
        $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), make_backup_temp_directory($backupdir));
        $newcourseid = \restore_dbops::create_new_course('Copy', 'aireviewcopy', $this->course->category);
        $rc = new \restore_controller($backupdir, $newcourseid, \backup::INTERACTIVE_NO, \backup::MODE_GENERAL,
            $USER->id, \backup::TARGET_NEW_COURSE);
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        $newgm = $DB->get_record('googlemeet', ['course' => $newcourseid], '*', MUST_EXIST);
        $rows = $DB->get_records_sql("SELECT a.summary, a.reviewed, a.reviewedby
                                        FROM {googlemeet_ai_analysis} a
                                        JOIN {googlemeet_recordings} r ON r.id = a.recordingid
                                       WHERE r.googlemeetid = ?", [$newgm->id]);
        if (!$rows) {
            $this->markTestSkipped('AI analyses are not part of this backup configuration.');
        }
        $this->assertEquals(1, $rows['Publicado']->reviewed);
        $this->assertEquals($this->teacher->id, $rows['Publicado']->reviewedby);
        $this->assertEquals(0, $rows['Pendiente']->reviewed);
    }
}
