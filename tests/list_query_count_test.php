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

use mod_googlemeet\local\list_aggregates;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/lib.php');
require_once($CFG->dirroot . '/mod/googlemeet/locallib.php');

/**
 * PERF-03: the recordings list and the hub run a constant number of DB queries.
 *
 * Measured with $DB->perf_get_queries() around the real render functions with an increasing
 * number of recordings, each with attached materials and published questions.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(list_aggregates::class)]
final class list_query_count_test extends \advanced_testcase {

    /** @var int Allowed drift between small and large pages (spec: ± 3). */
    private const TOLERANCE = 3;

    /**
     * Configure a Google OAuth issuer without network access so the list renders (client enabled).
     *
     * @return void
     */
    private function configure_issuer(): void {
        // Persist the issuer directly: api::create_issuer() would run endpoint discovery over HTTP.
        $issuer = new \core\oauth2\issuer(0, (object)[
            'name' => 'Google',
            'image' => '',
            'baseurl' => 'https://accounts.google.com',
            'clientid' => 'client',
            'clientsecret' => 'secret',
            'loginscopes' => 'openid profile email',
            'loginscopesoffline' => 'openid profile email',
            'loginparams' => '',
            'loginparamsoffline' => 'access_type=offline&prompt=consent',
            'showonloginpage' => 0,
        ]);
        $issuer->create();
        foreach ([
            'authorization_endpoint' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_endpoint' => 'https://oauth2.googleapis.com/token',
            'userinfo_endpoint' => 'https://openidconnect.googleapis.com/v1/userinfo',
        ] as $name => $url) {
            (new \core\oauth2\endpoint(0, (object)['issuerid' => $issuer->get('id'), 'name' => $name, 'url' => $url]))->create();
        }
        set_config('issuerid', $issuer->get('id'), 'googlemeet');
    }

    /**
     * Activity with $count recordings, each with 2 materials and 2 published questions.
     *
     * @param int $count
     * @return array [googlemeet, cm, context, course, recordingids]
     */
    private function make_activity(int $count): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $googlemeet = $this->getDataGenerator()->create_module('googlemeet', [
            'course' => $course->id,
            'name' => 'Clase',
            'url' => 'https://meet.google.com/abc-defg-hij',
            'maxrecordings' => 20,
        ]);
        $googlemeet = $DB->get_record('googlemeet', ['id' => $googlemeet->id]);
        $cm = get_coursemodule_from_instance('googlemeet', $googlemeet->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $service = new question_service();
        $fs = get_file_storage();

        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $id = (int)$DB->insert_record('googlemeet_recordings', (object)[
                'googlemeetid' => $googlemeet->id,
                'recordingid' => 'drive-' . $i . '-' . random_string(6),
                'name' => 'Clase ' . $i,
                'createdtime' => 1700000000 + $i * DAYSECS,
                'duration' => '1:00:00',
                'webviewlink' => 'https://drive.google.com/file/d/x' . $i . '/view',
                'transcripttext' => '',
                'visible' => 1,
                'deleted' => 0,
                'timemodified' => time(),
            ]);
            $ids[] = $id;
            foreach (['tema.pdf', 'esquema.pdf'] as $filename) {
                $fs->create_file_from_string([
                    'contextid' => $context->id, 'component' => 'mod_googlemeet', 'filearea' => 'recordingmaterial',
                    'itemid' => $id, 'filepath' => '/', 'filename' => $filename,
                ], 'content ' . $i);
            }
            for ($q = 0; $q < 2; $q++) {
                $qid = $service->create_draft_multichoice($googlemeet, $cm, $context, $id, [
                    'stem' => "Pregunta {$q} de la clase {$i}",
                    'options' => ['A', 'B', 'C', 'D'],
                    'correctindex' => 0,
                    'explanation' => 'Porque sí.',
                    'citation' => '00:01:00',
                ]);
                $DB->set_field('question_versions', 'status', 'ready', ['questionid' => $qid]);
            }
        }
        return [$googlemeet, $cm, $context, $course, $ids];
    }

    /**
     * Render the recordings list and return [html, queries].
     *
     * @param \stdClass $googlemeet
     * @param \stdClass $cm
     * @param \context_module $context
     * @return array
     */
    private function render_list(\stdClass $googlemeet, \stdClass $cm, \context_module $context): array {
        global $DB, $PAGE;
        $PAGE = new \moodle_page();
        $PAGE->set_url('/mod/googlemeet/view.php', ['id' => $cm->id]);
        $PAGE->set_cm($cm);
        $PAGE->set_context($context);
        // Warm caches that are not per recording (capabilities, strings, config).
        ob_start();
        googlemeet_print_recordings($googlemeet, $cm, $context);
        ob_end_clean();

        $before = $DB->perf_get_queries();
        ob_start();
        googlemeet_print_recordings($googlemeet, $cm, $context);
        $html = ob_get_clean();
        return [$html, $DB->perf_get_queries() - $before];
    }

    /**
     * Render the hub and return [html, queries].
     *
     * @param \stdClass $googlemeet
     * @param \stdClass $cm
     * @param \context_module $context
     * @param int $recordingid
     * @return array
     */
    private function render_hub(\stdClass $googlemeet, \stdClass $cm, \context_module $context, int $recordingid): array {
        global $DB, $PAGE;
        $recording = $DB->get_record('googlemeet_recordings', ['id' => $recordingid]);
        $render = function() use ($googlemeet, $cm, $context, $recording) {
            global $PAGE;
            $PAGE = new \moodle_page();
            $PAGE->set_url('/mod/googlemeet/view.php', ['id' => $cm->id, 'recording' => $recording->id]);
            $PAGE->set_cm($cm);
            $PAGE->set_context($context);
            ob_start();
            googlemeet_print_recording_hub($googlemeet, $cm, $context, $recording);
            return ob_get_clean();
        };
        $render();
        $before = $DB->perf_get_queries();
        $html = $render();
        return [$html, $DB->perf_get_queries() - $before];
    }

    /**
     * List query count does not grow with the number of recordings on the page (teacher and student).
     */
    public function test_list_query_count_is_constant(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->configure_issuer();

        [$small, $smallcm, $smallctx, $smallcourse] = $this->make_activity(2);
        [$large, $largecm, $largectx, $largecourse] = $this->make_activity(12);

        // Teacher.
        $teacher = $this->getDataGenerator()->create_and_enrol($smallcourse, 'editingteacher');
        $this->getDataGenerator()->enrol_user($teacher->id, $largecourse->id, 'editingteacher');
        $this->setUser($teacher);
        [$smallhtml, $smallq] = $this->render_list($small, $smallcm, $smallctx);
        [$largehtml, $largeq] = $this->render_list($large, $largecm, $largectx);
        $this->assertStringContainsString('esquema.pdf', $largehtml);
        $this->assertLessThanOrEqual($smallq + self::TOLERANCE, $largeq,
            "Teacher list: {$smallq} queries with 2 recordings, {$largeq} with 12.");

        // Student.
        $student = $this->getDataGenerator()->create_and_enrol($smallcourse, 'student');
        $this->getDataGenerator()->enrol_user($student->id, $largecourse->id, 'student');
        $this->setUser($student);
        [, $smallq] = $this->render_list($small, $smallcm, $smallctx);
        [$largehtml, $largeq] = $this->render_list($large, $largecm, $largectx);
        $this->assertStringContainsString('esquema.pdf', $largehtml);
        $this->assertLessThanOrEqual($smallq + self::TOLERANCE, $largeq,
            "Student list: {$smallq} queries with 2 recordings, {$largeq} with 12.");
    }

    /**
     * Hub query count does not depend on how many recordings the activity has.
     */
    public function test_hub_query_count_is_constant(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->configure_issuer();

        [$small, $smallcm, $smallctx, $smallcourse, $smallids] = $this->make_activity(3);
        [$large, $largecm, $largectx, $largecourse, $largeids] = $this->make_activity(15);
        $student = $this->getDataGenerator()->create_and_enrol($smallcourse, 'student');
        $this->getDataGenerator()->enrol_user($student->id, $largecourse->id, 'student');
        $this->setUser($student);

        [, $smallq] = $this->render_hub($small, $smallcm, $smallctx, $smallids[1]);
        [, $largeq] = $this->render_hub($large, $largecm, $largectx, $largeids[7]);
        $this->assertLessThanOrEqual($smallq + self::TOLERANCE, $largeq,
            "Hub: {$smallq} queries with 3 recordings, {$largeq} with 15.");
    }

    /**
     * Aggregated published-question counts match question_service::get_questions().
     */
    public function test_published_question_counts_match_service(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        [$googlemeet, $cm, $context, , $ids] = $this->make_activity(3);
        // One recording with an extra draft (not counted) and one without questions at all.
        $service = new question_service();
        $service->create_draft_multichoice($googlemeet, $cm, $context, $ids[0], [
            'stem' => 'Pregunta en borrador', 'options' => ['A', 'B', 'C', 'D'], 'correctindex' => 0,
            'explanation' => 'Explicación del borrador.', 'citation' => '00:02:00',
        ]);
        $empty = (int)$DB->insert_record('googlemeet_recordings', (object)[
            'googlemeetid' => $googlemeet->id, 'recordingid' => 'drive-empty', 'name' => 'Sin preguntas',
            'createdtime' => 1600000000, 'duration' => '1:00', 'webviewlink' => '', 'visible' => 1, 'deleted' => 0,
            'timemodified' => time(),
        ]);

        $counts = list_aggregates::published_question_counts($googlemeet, $cm, $context, array_merge($ids, [$empty]));
        foreach (array_merge($ids, [$empty]) as $id) {
            $expected = count($service->get_questions($googlemeet, $cm, $context, $id, true));
            $this->assertSame($expected, $counts[$id] ?? 0, "Recording {$id}");
        }
        $this->assertSame(2, $counts[$ids[0]]);
        $this->assertArrayNotHasKey($empty, $counts);
    }

    /**
     * Materials grouped by recording match the per-recording helper.
     */
    public function test_materials_by_recording_match_helper(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [, , $context, , $ids] = $this->make_activity(2);
        $grouped = list_aggregates::materials_by_recording($context, array_merge($ids, [999999]));
        foreach ($ids as $id) {
            $this->assertSame(googlemeet_get_recording_materials($context, $id), $grouped[$id]);
            $this->assertSame(['esquema.pdf', 'tema.pdf'], array_column($grouped[$id], 'name'));
        }
        $this->assertSame([], $grouped[999999]);
    }

    /**
     * Previous/next follow creation time (ties by id) and skip hidden/deleted recordings for students.
     */
    public function test_hub_neighbours(): void {
        global $DB;
        $this->resetAfterTest();

        $insert = function(int $created, int $visible = 1, int $deleted = 0) {
            global $DB;
            return (int)$DB->insert_record('googlemeet_recordings', (object)[
                'googlemeetid' => 77, 'recordingid' => random_string(8), 'name' => 'R' . $created,
                'createdtime' => $created, 'duration' => '1:00', 'webviewlink' => '', 'visible' => $visible,
                'deleted' => $deleted, 'timemodified' => 0,
            ]);
        };
        $a = $insert(100);
        $hidden = $insert(200, 0);
        $b = $insert(300);
        $tie = $insert(300);
        $insert(400, 1, 1); // Deleted.
        $c = $insert(500);

        $rec = fn(int $id) => $DB->get_record('googlemeet_recordings', ['id' => $id]);

        [$prev, $next] = list_aggregates::hub_neighbours($rec($b), true);
        $this->assertSame($a, (int)$prev->id);
        $this->assertSame($tie, (int)$next->id);

        [$prev, $next] = list_aggregates::hub_neighbours($rec($b), false);
        $this->assertSame($hidden, (int)$prev->id);

        [$prev, $next] = list_aggregates::hub_neighbours($rec($tie), true);
        $this->assertSame($b, (int)$prev->id);
        $this->assertSame($c, (int)$next->id);

        [$prev, $next] = list_aggregates::hub_neighbours($rec($a), true);
        $this->assertNull($prev);
        [$prev, $next] = list_aggregates::hub_neighbours($rec($c), true);
        $this->assertNull($next);
    }
}
