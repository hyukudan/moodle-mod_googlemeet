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
 * Behat step definitions for mod_googlemeet.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here. This file is also included from behat_init.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

/**
 * Steps for mod_googlemeet.
 */
class behat_mod_googlemeet extends behat_base {

    /**
     * Visit a plugin script of an activity with an explicit recording id (e.g. a stale link).
     *
     * @Given /^I visit the "(?P<script_string>[^"]*)" script of googlemeet "(?P<name_string>[^"]*)" with recording id "(?P<recording_string>\d+)"$/
     * @param string $script view.php or material.php
     * @param string $name Activity name.
     * @param string $recordingid Recording id to request.
     */
    public function i_visit_script_of_googlemeet_with_recording(string $script, string $name, string $recordingid): void {
        global $DB;
        if (!in_array($script, ['view.php', 'material.php'], true)) {
            throw new Exception('Unsupported script ' . $script);
        }
        $instance = $DB->get_record('googlemeet', ['name' => $name], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('googlemeet', $instance->id, $instance->course, false, MUST_EXIST);
        $this->getSession()->visit($this->locate_path('/mod/googlemeet/' . $script . '?id=' . $cm->id .
            '&recording=' . $recordingid));
    }
}
