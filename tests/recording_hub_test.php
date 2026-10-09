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
 * Recording hub transcript tab: source selection and rendering.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversFunction('googlemeet_hub_transcript')]
#[CoversFunction('googlemeet_print_recording_hub')]
final class recording_hub_test extends \advanced_testcase {

    /**
     * Analysis transcript wins; an empty one falls back to the recording transcript.
     */
    public function test_hub_transcript_source(): void {
        $recording = (object)['transcripttext' => "0:08\nMeet transcript"];
        $done = fn(string $t) => (object)['status' => 'completed', 'transcript' => $t];

        $this->assertSame('AI transcript', googlemeet_hub_transcript($done('AI transcript'), $recording));
        $this->assertSame("0:08\nMeet transcript", googlemeet_hub_transcript($done(''), $recording));
        $this->assertSame("0:08\nMeet transcript", googlemeet_hub_transcript($done("  \n"), $recording));
        $this->assertSame("0:08\nMeet transcript", googlemeet_hub_transcript(false, $recording));
        $this->assertSame("0:08\nMeet transcript",
            googlemeet_hub_transcript((object)['status' => 'pending', 'transcript' => 'partial'], $recording));
        $this->assertSame('', googlemeet_hub_transcript($done(''), (object)['transcripttext' => null]));
        $this->assertSame('', googlemeet_hub_transcript($done(''), (object)[]));
    }

    /**
     * Render the hub for one recording as the current user.
     *
     * @param \stdClass $googlemeet Instance.
     * @param \stdClass $recording Recording row.
     * @return string HTML.
     */
    private function render_hub(\stdClass $googlemeet, \stdClass $recording): string {
        global $PAGE;
        $cm = get_coursemodule_from_instance('googlemeet', $googlemeet->id, $googlemeet->course, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $PAGE = new \moodle_page();
        $PAGE->set_url('/mod/googlemeet/view.php', ['id' => $cm->id, 'recording' => $recording->id]);
        $PAGE->set_cm($cm);
        $PAGE->set_context($context);
        ob_start();
        googlemeet_print_recording_hub($googlemeet, $cm, $context, $recording);
        return ob_get_clean();
    }

    /**
     * A completed analysis with an empty transcript shows the Meet transcript to teachers only;
     * with no transcript at all the tab explains it instead of showing an empty search box.
     */
    public function test_transcript_tab_falls_back_to_recording_transcript(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $googlemeet = $this->getDataGenerator()->create_module('googlemeet', ['course' => $course->id]);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $now = time();
        $insert = function(string $transcripttext) use ($DB, $googlemeet, $now) {
            $id = $DB->insert_record('googlemeet_recordings', (object)[
                'googlemeetid' => $googlemeet->id, 'recordingid' => uniqid('drive-', true), 'name' => 'Clase',
                'createdtime' => $now, 'duration' => '01:00:00', 'webviewlink' => 'https://drive.google.com/file/d/x/view',
                'visible' => 1, 'timemodified' => $now, 'transcripttext' => $transcripttext,
            ]);
            $DB->insert_record('googlemeet_ai_analysis', (object)[
                'recordingid' => $id, 'summary' => 'Resumen de la clase', 'keypoints' => '["Punto"]', 'transcript' => '',
                'topics' => '[]', 'language' => 'es', 'status' => 'completed', 'retrycount' => 0, 'nextretry' => 0,
                'timecreated' => $now, 'timemodified' => $now,
            ]);
            return $DB->get_record('googlemeet_recordings', ['id' => $id]);
        };
        $withmeet = $insert("0:08\nBuenas tardes. Hablamos de la ley general de sanidad.");
        $without = $insert('');

        $this->setUser($teacher);
        $html = $this->render_hub($googlemeet, $withmeet);
        $this->assertStringContainsString('ley general de sanidad', $html);
        $this->assertStringContainsString('googlemeet-transcript-search-input', $html);
        $this->assertStringNotContainsString(get_string('ai_transcript_unavailable', 'googlemeet'), $html);

        $html = $this->render_hub($googlemeet, $without);
        $this->assertStringNotContainsString('googlemeet-transcript-search-input', $html);
        $this->assertStringContainsString(get_string('ai_transcript_unavailable', 'googlemeet'), $html);

        $this->setUser($student);
        $html = $this->render_hub($googlemeet, $withmeet);
        $this->assertStringNotContainsString('ley general de sanidad', $html);
        $this->assertStringNotContainsString('googlemeet-transcript-panel', $html);
    }
}
