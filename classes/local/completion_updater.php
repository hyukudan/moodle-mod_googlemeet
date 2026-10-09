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

namespace mod_googlemeet\local;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/completionlib.php');

/**
 * Recalculates the custom completion state (ANA-01) when progress or practice data changes.
 *
 * Cheap no-op unless completion is automatic for the activity and one of the rules that depend on
 * the changed data is enabled.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class completion_updater {

    /** @var string[] Rules fed by recording progress. */
    public const PROGRESS_RULES = ['completionrecordings', 'completionwatchpercent'];

    /** @var string[] Rules fed by practice attempts. */
    public const PRACTICE_RULES = ['completionpractice'];

    /**
     * Recalculate the completion state of one user after recording progress changed.
     *
     * @param \stdClass|\cm_info $cm Course module.
     * @param int $userid User id.
     * @return bool True when a recalculation was requested.
     */
    public static function progress_changed($cm, int $userid): bool {
        return self::update($cm, $userid, self::PROGRESS_RULES);
    }

    /**
     * Recalculate the completion state of one user after a practice answer was stored.
     *
     * @param \stdClass|\cm_info $cm Course module.
     * @param int $userid User id.
     * @return bool True when a recalculation was requested.
     */
    public static function practice_changed($cm, int $userid): bool {
        return self::update($cm, $userid, self::PRACTICE_RULES);
    }

    /**
     * Ask core to recompute the state when any of the given rules is active.
     *
     * @param \stdClass|\cm_info $cm Course module.
     * @param int $userid User id.
     * @param string[] $rules Rules that depend on the changed data.
     * @return bool
     */
    protected static function update($cm, int $userid, array $rules): bool {
        if ((int)($cm->completion ?? 0) !== COMPLETION_TRACKING_AUTOMATIC) {
            return false;
        }

        $modinfo = get_fast_modinfo((int)$cm->course, $userid);
        $cminfo = $modinfo->get_cm((int)$cm->id);
        $active = $cminfo->customdata['customcompletionrules'] ?? [];
        $relevant = false;
        foreach ($rules as $rule) {
            if (!empty($active[$rule])) {
                $relevant = true;
                break;
            }
        }
        if (!$relevant) {
            return false;
        }

        $completion = new \completion_info($modinfo->get_course());
        if (!$completion->is_enabled($cminfo)) {
            return false;
        }
        $completion->update_state($cminfo, COMPLETION_UNKNOWN, $userid);
        return true;
    }
}
