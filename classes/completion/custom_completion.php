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

declare(strict_types=1);

namespace mod_googlemeet\completion;

use core_completion\activity_custom_completion;

/**
 * Custom completion rules of mod_googlemeet (ANA-01).
 *
 * - completionrecordings: the student has watched at least N classes.
 * - completionwatchpercent: the student has watched at least X % of the visible classes.
 * - completionpractice: the student has answered at least N distinct practice questions.
 *
 * "Watched" is the existing per-recording progress flag (googlemeet_recording_progress.completed),
 * which with the Google Drive player is an approximation (visible-page heartbeats or "mark as
 * watched"), not real playback time. Only visible recordings that are not in the trash count.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class custom_completion extends activity_custom_completion {

    /**
     * Fetches the completion state for a given completion rule.
     *
     * @param string $rule The completion rule.
     * @return int The completion state.
     */
    public function get_state(string $rule): int {
        $this->validate_rule($rule);

        $target = (int)($this->cm->customdata['customcompletionrules'][$rule] ?? 0);
        $googlemeetid = (int)$this->cm->instance;

        switch ($rule) {
            case 'completionrecordings':
                $met = $target > 0 && self::count_watched($googlemeetid, $this->userid) >= $target;
                break;
            case 'completionwatchpercent':
                $met = $target > 0 && self::watched_percent_met($googlemeetid, $this->userid, $target);
                break;
            case 'completionpractice':
                $met = $target > 0 && self::count_answered_questions($googlemeetid, $this->userid) >= $target;
                break;
            default:
                $met = false;
        }

        return $met ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
    }

    /**
     * Number of visible, non-trashed recordings of the activity the user has watched.
     *
     * @param int $googlemeetid Activity instance id.
     * @param int $userid User id.
     * @return int
     */
    public static function count_watched(int $googlemeetid, int $userid): int {
        global $DB;

        return (int)$DB->count_records_sql(
            "SELECT COUNT(1)
               FROM {googlemeet_recording_progress} p
               JOIN {googlemeet_recordings} r ON r.id = p.recordingid
              WHERE r.googlemeetid = :googlemeetid
                AND r.visible = 1
                AND r.deleted = 0
                AND p.userid = :userid
                AND p.completed = 1",
            ['googlemeetid' => $googlemeetid, 'userid' => $userid]
        );
    }

    /**
     * Number of recordings students can see (visible and not in the trash).
     *
     * @param int $googlemeetid Activity instance id.
     * @return int
     */
    public static function count_visible(int $googlemeetid): int {
        global $DB;

        return $DB->count_records('googlemeet_recordings', ['googlemeetid' => $googlemeetid, 'visible' => 1, 'deleted' => 0]);
    }

    /**
     * Whether the user watched at least $percent % of the visible recordings.
     *
     * Without visible recordings the rule cannot be met (nothing to watch yet).
     *
     * @param int $googlemeetid Activity instance id.
     * @param int $userid User id.
     * @param int $percent Required percentage (1-100).
     * @return bool
     */
    public static function watched_percent_met(int $googlemeetid, int $userid, int $percent): bool {
        $total = self::count_visible($googlemeetid);
        if ($total <= 0) {
            return false;
        }
        $percent = max(1, min(100, $percent));
        return self::count_watched($googlemeetid, $userid) * 100 >= $percent * $total;
    }

    /**
     * Number of distinct practice questions the user has answered (right or wrong).
     *
     * @param int $googlemeetid Activity instance id.
     * @param int $userid User id.
     * @return int
     */
    public static function count_answered_questions(int $googlemeetid, int $userid): int {
        global $DB;

        return (int)$DB->count_records_sql(
            "SELECT COUNT(DISTINCT questionid)
               FROM {googlemeet_practice_attempts}
              WHERE googlemeetid = :googlemeetid
                AND userid = :userid",
            ['googlemeetid' => $googlemeetid, 'userid' => $userid]
        );
    }

    /**
     * Fetch the list of custom completion rules that this module defines.
     *
     * @return array
     */
    public static function get_defined_custom_rules(): array {
        return [
            'completionrecordings',
            'completionwatchpercent',
            'completionpractice',
        ];
    }

    /**
     * Returns an associative array of the descriptions of custom completion rules.
     *
     * @return array
     */
    public function get_custom_rule_descriptions(): array {
        $rules = $this->cm->customdata['customcompletionrules'] ?? [];
        return [
            'completionrecordings' => get_string('completiondetail:recordings', 'googlemeet',
                (int)($rules['completionrecordings'] ?? 0)),
            'completionwatchpercent' => get_string('completiondetail:watchpercent', 'googlemeet',
                (int)($rules['completionwatchpercent'] ?? 0)),
            'completionpractice' => get_string('completiondetail:practice', 'googlemeet',
                (int)($rules['completionpractice'] ?? 0)),
        ];
    }

    /**
     * Returns an array of all completion rules, in the order they should be displayed to users.
     *
     * @return array
     */
    public function get_sort_order(): array {
        return [
            'completionview',
            'completionrecordings',
            'completionwatchpercent',
            'completionpractice',
        ];
    }
}
