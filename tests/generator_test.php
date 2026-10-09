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

/**
 * Tests for the plugin's test data generator helpers.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \mod_googlemeet_generator
 */
final class generator_test extends \advanced_testcase {

    /**
     * Recordings and AI analyses can be generated and linked.
     */
    public function test_create_recording_and_analysis(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $meet = $this->getDataGenerator()->create_module('googlemeet', ['course' => $course->id]);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_googlemeet');

        $recording = $generator->create_recording(['googlemeetid' => $meet->id, 'name' => 'Clase 1']);
        $this->assertSame('Clase 1', $recording->name);
        $this->assertEquals(0, $recording->deleted);

        $analysis = $generator->create_ai_analysis(['recordingid' => $recording->id]);
        $this->assertSame('completed', $analysis->status);
        $this->assertEquals($recording->id, $analysis->recordingid);
    }
}
