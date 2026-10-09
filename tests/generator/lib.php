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
 * mod_googlemeet test data generator.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * mod_googlemeet test data generator class.
 */
class mod_googlemeet_generator extends testing_module_generator {

    /**
     * Create a new googlemeet activity instance.
     *
     * Supplies the fields googlemeet_add_instance() needs when no Google account is linked:
     * a valid Meet URL and the (zeroed) event-time fields read by the event constructor.
     *
     * @param array|stdClass|null $record
     * @param array|null $options
     * @return stdClass
     */
    public function create_instance($record = null, ?array $options = null) {
        $record = (array) $record;

        $defaults = [
            'url' => 'https://meet.google.com/abc-defg-hij',
            'eventdate' => 0,
            'starthour' => 0,
            'startminute' => 0,
            'endhour' => 0,
            'endminute' => 0,
            'notify' => 0,
            'minutesbefore' => 0,
        ];
        foreach ($defaults as $field => $value) {
            if (!array_key_exists($field, $record)) {
                $record[$field] = $value;
            }
        }

        return parent::create_instance($record, (array) $options);
    }

    /**
     * Create a recording row for an activity (no Google round-trip).
     *
     * @param array|stdClass $record Needs googlemeetid; everything else is defaulted.
     * @return stdClass The stored recording.
     */
    public function create_recording($record): stdClass {
        global $DB;
        $record = (array) $record;
        $now = time();
        $record += [
            'recordingid' => uniqid('drive-', true),
            'name' => 'Recording ' . $now,
            'createdtime' => $now,
            'duration' => '01:00:00',
            'webviewlink' => 'https://drive.google.com/file/d/test/view',
            'visible' => 1,
            'deleted' => 0,
            'timedeleted' => 0,
            'timemodified' => $now,
        ];
        $id = $DB->insert_record('googlemeet_recordings', (object) $record);
        return $DB->get_record('googlemeet_recordings', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Create a completed AI analysis row for a recording.
     *
     * @param array|stdClass $record Needs recordingid (googlemeet_recordings.id).
     * @return stdClass The stored analysis.
     */
    public function create_ai_analysis($record): stdClass {
        global $DB;
        $record = (array) $record;
        $now = time();
        $record += [
            'summary' => '<p>Summary</p>',
            'keypoints' => json_encode(['Point one', 'Point two']),
            'topics' => json_encode(['Topic']),
            'chapters' => json_encode([]),
            'status' => 'completed',
            'retrycount' => 0,
            'nextretry' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $id = $DB->insert_record('googlemeet_ai_analysis', (object) $record);
        return $DB->get_record('googlemeet_ai_analysis', ['id' => $id], '*', MUST_EXIST);
    }
}
