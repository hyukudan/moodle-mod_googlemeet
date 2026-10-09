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

namespace mod_googlemeet\event;

/**
 * A user clicked "Join" to enter the live Google Meet room (ANA-02).
 *
 * It records the intention to join, not real attendance (see ANA-03 for that).
 *
 * @property-read array $other {
 *      - int sessionid: googlemeet_events id of the live/imminent session, 0 when none.
 *      - string via: "web" or "mobile".
 * }
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class room_entered extends \core\event\base {

    /**
     * Init method.
     *
     * @return void
     */
    protected function init() {
        $this->data['objecttable'] = 'googlemeet';
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventroomentered', 'mod_googlemeet');
    }

    /**
     * Non-localised description.
     *
     * @return string
     */
    public function get_description() {
        $session = !empty($this->other['sessionid']) ? " for the session with id '{$this->other['sessionid']}'" : '';
        return "The user with id '$this->userid' entered the Google Meet room of the activity with course module id "
            . "'$this->contextinstanceid'$session.";
    }

    /**
     * Activity URL.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/mod/googlemeet/view.php', ['id' => $this->contextinstanceid]);
    }

    /**
     * Validate the custom data.
     *
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->other['sessionid'])) {
            throw new \coding_exception('The \'sessionid\' value must be set in other.');
        }
    }

    /**
     * Restore mapping of objectid.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'googlemeet', 'restore' => 'googlemeet'];
    }

    /**
     * Restore mapping of the other fields.
     *
     * @return array
     */
    public static function get_other_mapping() {
        return ['sessionid' => ['db' => 'googlemeet_events', 'restore' => 'googlemeet_event']];
    }
}
