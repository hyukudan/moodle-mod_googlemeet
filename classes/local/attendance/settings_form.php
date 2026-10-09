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

namespace mod_googlemeet\local\attendance;

/**
 * Activity form fields of real attendance (ANA-03).
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class settings_form {

    /**
     * Add the per-activity opt-in (only while the site setting is on).
     *
     * @param \MoodleQuickForm $mform Form.
     * @return void
     */
    public static function add_activity_fields(\MoodleQuickForm $mform): void {
        if (!scope::site_enabled()) {
            return;
        }
        $mform->addElement('header', 'attendanceheader', get_string('attendance_header', 'googlemeet'));
        $mform->addElement('advcheckbox', 'attendanceenabled', get_string('attendance_activity', 'googlemeet'));
        $mform->addHelpButton('attendanceenabled', 'attendance_activity', 'googlemeet');
        $mform->setDefault('attendanceenabled', 0);
        $mform->addElement('static', 'attendancerelinknote', '', get_string('attendance_activity_relink', 'googlemeet'));
    }
}
