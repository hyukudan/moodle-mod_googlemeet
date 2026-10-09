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
 * Google Meet task - warn admins about abandoned recurrences.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_googlemeet\task;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/locallib.php');

/**
 * Weekly check that warns site admins when a googlemeet recurrence looks abandoned.
 */
class check_stale_recurrence extends \core\task\scheduled_task {

    /**
     * @return string
     */
    public function get_name() {
        return get_string('stalerecurrence_task', 'mod_googlemeet');
    }

    /**
     * Detect abandoned recurrences, notify admins (respecting the re-notify window),
     * and clear state for instances that recovered.
     */
    public function execute() {
        global $DB;

        // Default ON: settings.php defaults are not persisted until the settings page is saved,
        // so an unset value (false) must be treated as enabled; only an explicit '0' disables.
        $enabled = get_config('googlemeet', 'stalerecurrence_enabled');
        if ($enabled === false) {
            $enabled = 1;
        }
        if (!$enabled) {
            return;
        }

        $weeks = (int) get_config('googlemeet', 'stalerecurrence_weeks');
        if ($weeks <= 0) {
            $weeks = 3;
        }
        $renotifydays = (int) get_config('googlemeet', 'stalerecurrence_renotifydays');
        if ($renotifydays <= 0) {
            $renotifydays = 28;
        }

        $stale = googlemeet_get_stale_recurrences($weeks);

        $stillstale = [];
        $sent = 0;
        foreach ($stale as $info) {
            $stillstale[(int) $info->id] = true;
            // DAT-06: the alert state lives in googlemeet.stalealerttime (0 = no open alert).
            $last = (int) $DB->get_field('googlemeet', 'stalealerttime', ['id' => $info->id]);
            if ($last === 0 || (time() - $last) > $renotifydays * DAYSECS) {
                googlemeet_send_stale_alert($info);
                $DB->set_field('googlemeet', 'stalealerttime', time(), ['id' => $info->id]);
                $sent++;
            }
        }

        // Re-arm: drop stored state for instances that are no longer stale.
        $alerted = $DB->get_fieldset_select('googlemeet', 'id', 'stalealerttime > 0');
        foreach ($alerted as $gmid) {
            if (empty($stillstale[(int) $gmid])) {
                $DB->set_field('googlemeet', 'stalealerttime', 0, ['id' => $gmid]);
            }
        }

        mtrace('mod_googlemeet stale recurrence check: ' . count($stale) . ' stale, ' . $sent . ' notified.');
    }
}
