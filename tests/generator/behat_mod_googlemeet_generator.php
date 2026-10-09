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

/**
 * Behat data generators for mod_googlemeet.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Behat data generator for mod_googlemeet entities.
 */
class behat_mod_googlemeet_generator extends behat_generator_base {

    /**
     * Entities the "the following mod_googlemeet ... exist" step can create.
     *
     * @return array
     */
    protected function get_creatable_entities(): array {
        return [
            'recordings' => [
                'singular' => 'recording',
                'datagenerator' => 'recording',
                'required' => ['googlemeet', 'name'],
                'switchids' => ['googlemeet' => 'googlemeetid'],
            ],
            'aianalyses' => [
                'singular' => 'aianalysis',
                'datagenerator' => 'ai_analysis',
                'required' => ['recording'],
                'switchids' => ['recording' => 'recordingid'],
            ],
        ];
    }

    /**
     * Resolve an activity name or idnumber to googlemeet.id.
     *
     * @param string $name
     * @return int
     */
    protected function get_googlemeet_id(string $name): int {
        global $DB;
        if ($id = $DB->get_field('googlemeet', 'id', ['name' => $name], IGNORE_MULTIPLE)) {
            return (int) $id;
        }
        throw new Exception('The googlemeet activity "' . $name . '" does not exist');
    }

    /**
     * Resolve a recording name to googlemeet_recordings.id.
     *
     * @param string $name
     * @return int
     */
    protected function get_recording_id(string $name): int {
        global $DB;
        if ($id = $DB->get_field('googlemeet_recordings', 'id', ['name' => $name], IGNORE_MULTIPLE)) {
            return (int) $id;
        }
        throw new Exception('The recording "' . $name . '" does not exist');
    }
}
