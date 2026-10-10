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

use mod_googlemeet\output\mobile;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/lib.php');

/**
 * Tests for the Moodle App views (UX-05).
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(mobile::class)]
final class mobile_test extends \advanced_testcase {

    /** @var \stdClass */
    private $course;
    /** @var \stdClass */
    private $googlemeet;
    /** @var \stdClass */
    private $cm;
    /** @var \stdClass */
    private $student;

    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course();
        $this->googlemeet = $this->getDataGenerator()->create_module('googlemeet', [
            'course' => $this->course->id,
            'name' => 'Derecho administrativo',
            'intro' => '<p>Bienvenida <img src="@@PLUGINFILE@@/cartel.png" alt="cartel"></p>',
            'introformat' => FORMAT_HTML,
        ]);
        $DB->set_field('googlemeet', 'url', 'https://meet.google.com/abc-defg-hij', ['id' => $this->googlemeet->id]);
        $this->googlemeet = $DB->get_record('googlemeet', ['id' => $this->googlemeet->id]);
        $this->cm = get_coursemodule_from_instance('googlemeet', $this->googlemeet->id, $this->course->id, false, MUST_EXIST);
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
    }

    /**
     * Insert a recording.
     *
     * @param int $n Sequence.
     * @param int $visible Visible flag.
     * @return int
     */
    private function create_recording(int $n, int $visible = 1): int {
        global $DB;
        return (int)$DB->insert_record('googlemeet_recordings', (object)[
            'googlemeetid' => $this->googlemeet->id,
            'recordingid' => 'drive-' . $n,
            'name' => 'Tema ' . $n . ' (2026-01-' . sprintf('%02d', ($n % 28) + 1) . ' 10:00 GMT+1) - Recording',
            'createdtime' => 1767225600 + $n * DAYSECS,
            'duration' => '1:00:00',
            'webviewlink' => 'https://drive.google.com/file/d/drive-' . $n . '/view',
            'visible' => $visible,
            'timemodified' => time(),
        ]);
    }

    /**
     * Args for the app calls.
     *
     * @param array $extra Extra args.
     * @return array
     */
    private function args(array $extra = []): array {
        return ['cmid' => $this->cm->id, 'courseid' => $this->course->id] + $extra;
    }

    public function test_course_view_intro_and_pagination(): void {
        global $DB;
        $ids = [];
        for ($i = 1; $i <= 23; $i++) {
            $ids[] = $this->create_recording($i);
        }
        $this->create_recording(99, 0);
        $DB->insert_record('googlemeet_recording_progress', (object)['recordingid' => $ids[22], 'userid' => $this->student->id,
            'watchedseconds' => 600, 'completed' => 1, 'timecreated' => time(), 'timemodified' => time()]);

        $this->setUser($this->student);
        $result = mobile::mobile_course_view($this->args());
        $html = $result['templates'][0]['html'];

        // The description goes through format_module_intro: embedded files get real URLs.
        $this->assertStringContainsString('pluginfile.php', $html);
        $this->assertStringNotContainsString('@@PLUGINFILE@@', $html);
        $this->assertStringContainsString('core-format-text', $html);
        // Joining logs the entry through the WS.
        $this->assertStringContainsString('mod_googlemeet_log_room_entered', $html);
        $this->assertSame('https://meet.google.com/abc-defg-hij', $result['otherdata']['roomurl']);
        // No per-class question loading at startup.
        $this->assertStringNotContainsString('check_practice_answer', $html);
        $this->assertStringNotContainsString('googlemeetPractice', $result['javascript']);

        $page = mobile::recordings_page($this->googlemeet, \context_module::instance($this->cm->id), 0);
        $this->assertCount(mobile::PERPAGE, $page['recordings']);
        $this->assertSame(23, $page['total']);
        $this->assertTrue($page['hasnext']);
        $this->assertFalse($page['hasprev']);
        // Newest first: the last created (watched) class leads the list.
        $this->assertSame($ids[22], $page['recordings'][0]['id']);
        $this->assertTrue($page['recordings'][0]['watched']);
        $this->assertFalse($page['recordings'][1]['watched']);
        // Titles come from the lesson display-title logic.
        $titles = googlemeet_get_lesson_titles($this->googlemeet, false);
        $this->assertSame(format_string($titles[$ids[22]]['title']), $page['recordings'][0]['title']);

        $last = mobile::recordings_page($this->googlemeet, \context_module::instance($this->cm->id), 2);
        $this->assertCount(3, $last['recordings']);
        $this->assertFalse($last['hasnext']);
        $this->assertTrue($last['hasprev']);
        $clamped = mobile::recordings_page($this->googlemeet, \context_module::instance($this->cm->id), 50);
        $this->assertSame(2, $clamped['page']);

        $result = mobile::mobile_course_view($this->args(['page' => 1]));
        $this->assertStringContainsString('page: 2', $result['templates'][0]['html']);
    }

    public function test_recording_view(): void {
        global $DB;
        $recordingid = $this->create_recording(1);
        $DB->insert_record('googlemeet_ai_analysis', (object)[
            'recordingid' => $recordingid,
            'summary' => "Primer párrafo.\n\nSegundo párrafo.",
            'keypoints' => json_encode(['Elementos del acto', 'Eficacia']),
            'topics' => json_encode(['Acto administrativo']),
            'chapters' => json_encode([['title' => 'Introducción', 'start' => '00:00'], ['title' => 'Eficacia', 'start' => '12:30']]),
            'status' => 'completed',
            // Published by a teacher (IA-04): students see it.
            'reviewed' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $context = \context_module::instance($this->cm->id);
        $fs = get_file_storage();
        $fs->create_file_from_string(['contextid' => $context->id, 'component' => 'mod_googlemeet',
            'filearea' => 'recordingmaterial', 'itemid' => $recordingid, 'filepath' => '/', 'filename' => 'esquema.pdf'], '%PDF');

        $this->setUser($this->student);
        $result = mobile::mobile_recording_view($this->args(['recordingid' => $recordingid]));
        $html = $result['templates'][0]['html'];

        $this->assertStringContainsString('Segundo párrafo', $html);
        $this->assertStringContainsString('Elementos del acto', $html);
        $this->assertStringContainsString('12:30', $html);
        $this->assertStringContainsString('mod_googlemeet_mark_recording_progress', $html);
        $this->assertSame('https://drive.google.com/file/d/drive-1/view', $result['otherdata']['webviewlink']);
        $urls = json_decode($result['otherdata']['chapterurls']);
        $this->assertSame('https://drive.google.com/file/d/drive-1/preview?t=750', $urls[1]);
        $this->assertCount(1, $result['files']);
        $this->assertSame('esquema.pdf', $result['files'][0]['filename']);
        $this->assertStringContainsString('webservice/pluginfile.php', $result['files'][0]['fileurl']);
    }

    public function test_hidden_recording_is_not_available(): void {
        $recordingid = $this->create_recording(1, 0);
        $this->setUser($this->student);
        $this->expectException(\moodle_exception::class);
        mobile::mobile_recording_view($this->args(['recordingid' => $recordingid]));
    }

    public function test_ai_review_flag(): void {
        global $DB;
        $context = \context_module::instance($this->cm->id);
        $completed = (object)['status' => 'completed'];
        $this->setUser($this->student);

        $this->assertFalse(mobile::ai_visible(false, $context));
        $this->assertFalse(mobile::ai_visible((object)['status' => 'failed', 'reviewed' => 1], $context));

        // Review not required: any completed analysis is visible.
        set_config('requireaireview', 0, 'googlemeet');
        $this->assertTrue(mobile::ai_visible($completed, $context));

        // Required (the default): only reviewed content; an unset flag counts as not reviewed.
        set_config('requireaireview', 1, 'googlemeet');
        $this->assertFalse(mobile::ai_visible($completed, $context));
        unset_config('requireaireview', 'googlemeet');
        $this->assertFalse(mobile::ai_visible((object)['status' => 'completed', 'reviewed' => 0], $context));
        set_config('requireaireview', 1, 'googlemeet');
        $this->assertFalse(mobile::ai_visible((object)['status' => 'completed', 'reviewed' => 0], $context));
        $this->assertTrue(mobile::ai_visible((object)['status' => 'completed', 'reviewed' => 1], $context));

        // Teachers always see it.
        $this->setAdminUser();
        $this->assertTrue(mobile::ai_visible((object)['status' => 'completed', 'reviewed' => 0], $context));
    }

    /**
     * Server text never reaches the app template as an Angular binding.
     */
    public function test_no_bindings(): void {
        $data = mobile::no_bindings(['title' => "Clase {{ googlemeetOpen('x') }}", 'list' => [(object)['t' => '{{a}}']], 'n' => 3]);
        $this->assertStringNotContainsString('{{', $data['title']);
        $this->assertStringNotContainsString('}}', $data['title']);
        $this->assertStringContainsString("googlemeetOpen('x')", $data['title']);
        $this->assertStringNotContainsString('{{', $data['list'][0]->t);
        $this->assertSame(3, $data['n']);
    }
}
