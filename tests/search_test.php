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

/**
 * Search coverage for the recordings list.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversFunction('googlemeet_fold')]
#[CoversFunction('googlemeet_filter_recordings_by_query')]
#[CoversFunction('googlemeet_filter_recordings_by_topic')]
#[CoversFunction('googlemeet_list_recordings')]
#[CoversFunction('googlemeet_load_recording_search_content')]
class search_test extends \advanced_testcase {

    /**
     * Build a minimal recording stdClass for pure filter tests.
     *
     * @param string $name Recording name.
     * @param bool $hasai Whether completed AI content is present.
     * @param array $topics AI topics.
     * @param string $summary AI summary.
     * @return \stdClass
     */
    private function rec(string $name, bool $hasai = false, array $topics = [], string $summary = ''): \stdClass {
        $r = new \stdClass();
        $r->name = $name;
        $r->hasai = $hasai;
        $r->aitopics = $topics;
        $r->aisummary = $summary;
        return $r;
    }

    /**
     * Insert a minimal Google Meet activity record.
     *
     * @return \stdClass
     */
    private function create_googlemeet(): \stdClass {
        global $DB;

        $googlemeet = (object)[
            'course' => 1,
            'name' => 'Search room',
            'originalname' => 'Search room',
            'url' => 'https://meet.google.com/abc-defg-hij',
            'timemodified' => time(),
        ];
        $googlemeet->id = (int)$DB->insert_record('googlemeet', $googlemeet);

        return $googlemeet;
    }

    /**
     * Insert a recording row.
     *
     * @param int $googlemeetid Activity instance id.
     * @param string $driveid Drive file id.
     * @param array $overrides Field overrides.
     * @return int Recording row id.
     */
    private function create_recording(int $googlemeetid, string $driveid, array $overrides = []): int {
        global $DB;

        $now = time();
        $record = (object)array_merge([
            'googlemeetid' => $googlemeetid,
            'recordingid' => $driveid,
            'name' => 'Recording ' . $driveid,
            'createdtime' => $now - 100,
            'duration' => '00:05:00',
            'webviewlink' => 'https://drive.google.com/file/d/' . $driveid . '/view',
            'visible' => 1,
            'deleted' => 0,
            'timedeleted' => 0,
            'timemodified' => $now,
        ], $overrides);

        return (int)$DB->insert_record('googlemeet_recordings', $record);
    }

    public function test_fold_handles_spanish_accents_and_plain_text(): void {
        $this->assertSame('cardiologia nandu unguento arbol', googlemeet_fold('CARDIOLOGÍA ÑANDÚ UNGÜENTO Árbol'));
        $this->assertSame('clase 123 sin cambios', googlemeet_fold('clase 123 sin cambios'));
    }

    public function test_filter_by_query_matches_names_with_and_without_accents(): void {
        $out = googlemeet_filter_recordings_by_query(
            [$this->rec('Clase de Cardiología'), $this->rec('Clase de traumatología')],
            'cardiologia'
        );
        $this->assertCount(1, $out);
        $this->assertSame('Clase de Cardiología', $out[0]->name);
        $this->assertSame('', $out[0]->matchsource);

        $out = googlemeet_filter_recordings_by_query(
            [$this->rec('Clase de cardiologia'), $this->rec('Clase de trauma')],
            'cardiología'
        );
        $this->assertCount(1, $out);
        $this->assertSame('Clase de cardiologia', $out[0]->name);
    }

    public function test_filter_by_query_matches_transcript_only_with_source_and_snippet(): void {
        $recording = $this->rec('Clase sin pista');
        $recording->transcripttext = str_repeat('previo ', 20)
            . 'Hoy revisamos cardiología preventiva en detalle. '
            . str_repeat('despues ', 20);

        $out = googlemeet_filter_recordings_by_query([$recording], 'cardiologia');

        $this->assertCount(1, $out);
        $this->assertSame(get_string('search_match_transcript', 'googlemeet'), $out[0]->matchsource);
        $this->assertStringContainsString('cardiología', $out[0]->matchsnippet);
    }

    public function test_filter_by_query_matches_notes_only_with_source_and_plain_snippet(): void {
        $recording = $this->rec('Clase sin pista');
        $recording->notestext = '<p>Resumen docente: la arritmía ventricular aparece en las notas.</p>';

        $out = googlemeet_filter_recordings_by_query([$recording], 'arritmia');

        $this->assertCount(1, $out);
        $this->assertSame(get_string('search_match_notes', 'googlemeet'), $out[0]->matchsource);
        $this->assertStringContainsString('arritmía', $out[0]->matchsnippet);
        $this->assertStringNotContainsString('<p>', $out[0]->matchsnippet);
    }

    public function test_deleted_recording_transcript_is_not_searchable_after_listing(): void {
        $this->resetAfterTest();
        $googlemeet = $this->create_googlemeet();

        $this->create_recording($googlemeet->id, 'drive-active', [
            'name' => 'Visible class',
            'transcripttext' => 'Contenido visible sin el termino privado.',
        ]);
        $this->create_recording($googlemeet->id, 'drive-deleted', [
            'name' => 'Deleted class',
            'transcripttext' => 'La transcripción contiene palabraunica.',
            'deleted' => 1,
            'timedeleted' => time(),
        ]);

        $recordings = googlemeet_list_recordings(['googlemeetid' => $googlemeet->id], false);
        $recordings = googlemeet_load_recording_search_content($recordings);
        $out = googlemeet_filter_recordings_by_query($recordings, 'palabraunica');

        $this->assertCount(0, $out);
    }

    public function test_filter_by_topic_matches_accents(): void {
        $out = googlemeet_filter_recordings_by_topic(
            [$this->rec('a', true, ['Cardiología']), $this->rec('b', true, ['Trauma'])],
            'cardiologia'
        );
        $this->assertCount(1, $out);
        $this->assertSame('a', $out[0]->name);

        $out = googlemeet_filter_recordings_by_topic([$this->rec('a', true, ['cardiologia'])], 'Cardiología');
        $this->assertCount(1, $out);
    }

    public function test_list_recordings_base_query_excludes_notes_and_transcript_fields(): void {
        $this->resetAfterTest();
        $googlemeet = $this->create_googlemeet();

        $this->create_recording($googlemeet->id, 'drive-heavy', [
            'notestext' => '<p>Notas internas con contenido buscable.</p>',
            'transcripttext' => 'Transcripción interna con contenido buscable.',
        ]);

        $recordings = googlemeet_list_recordings(['googlemeetid' => $googlemeet->id], false);

        $this->assertCount(1, $recordings);
        $this->assertFalse(property_exists($recordings[0], 'notestext'));
        $this->assertFalse(property_exists($recordings[0], 'transcripttext'));
    }
}
