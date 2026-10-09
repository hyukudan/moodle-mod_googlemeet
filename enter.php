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
 * Lightweight "Join the live class" endpoint (ANA-02): logs room_entered and redirects to Meet.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_googlemeet\local\room_entry;

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);

$cm = get_coursemodule_from_id('googlemeet', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$googlemeet = $DB->get_record('googlemeet', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/googlemeet:view', $context);

$roomurl = room_entry::room_url($googlemeet);
if ($roomurl === null) {
    redirect(new moodle_url('/mod/googlemeet/view.php', ['id' => $cm->id]),
        get_string('roomunavailable', 'googlemeet'), null, \core\output\notification::NOTIFY_WARNING);
}

// Only log requests coming from the activity's own button: a forged cross-site link must not
// create entries in the user's log. The redirect to the room happens either way.
if (confirm_sesskey()) {
    room_entry::log($googlemeet, $cm, $context, 'web');
}

redirect($roomurl);
