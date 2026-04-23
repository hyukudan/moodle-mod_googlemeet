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
 * Google Meet task - Auto-sync recordings N hours after a session ends.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_googlemeet\task;

defined('MOODLE_INTERNAL') || die();

use mod_googlemeet\client;

/**
 * For each googlemeet activity that has autosynchours > 0, find events whose end time
 * was more than autosynchours hours ago and that have not been auto-synced yet, then run
 * the Drive sync impersonating the activity creator via their stored refresh token.
 *
 * Single-attempt policy: even if the sync returns 0 recordings or the creator's token
 * has been revoked, the events are marked as autosynced so the task never retries them.
 */
class process_autosync extends \core\task\scheduled_task {
    /**
     * @return string
     */
    public function get_name() {
        return get_string('process_autosync_task', 'mod_googlemeet');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        global $DB;

        $now = time();

        // Find events due for auto-sync, grouped by activity.
        // Condition: activity opted-in (autosynchours>0), event finished at least autosynchours ago,
        // and not yet auto-synced.
        $sql = "SELECT ge.id AS eventid,
                       ge.googlemeetid,
                       ge.eventdate,
                       ge.duration,
                       gm.autosynchours,
                       gm.creatoremail
                  FROM {googlemeet_events} ge
                  JOIN {googlemeet} gm ON gm.id = ge.googlemeetid
                 WHERE gm.autosynchours > 0
                   AND ge.autosynced = 0
                   AND (ge.eventdate + ge.duration + (gm.autosynchours * 3600)) <= :now
              ORDER BY ge.googlemeetid, ge.eventdate";

        $rows = $DB->get_records_sql($sql, ['now' => $now]);

        if (empty($rows)) {
            return;
        }

        // Group events by activity so we sync each activity at most once per run.
        $byactivity = [];
        foreach ($rows as $row) {
            $byactivity[$row->googlemeetid]['creatoremail'] = $row->creatoremail;
            $byactivity[$row->googlemeetid]['eventids'][] = $row->eventid;
        }

        foreach ($byactivity as $googlemeetid => $info) {
            $this->process_activity((int) $googlemeetid, $info['creatoremail'], $info['eventids'], $now);
        }
    }

    /**
     * Sync one activity impersonating its creator, then mark its due events as synced.
     *
     * @param int $googlemeetid
     * @param string $creatoremail Google account email stored on the activity
     * @param int[] $eventids Due event ids for this activity
     * @param int $now Current unix timestamp
     */
    private function process_activity(int $googlemeetid, string $creatoremail, array $eventids, int $now): void {
        global $DB;

        $googlemeet = $DB->get_record('googlemeet', ['id' => $googlemeetid]);
        if (!$googlemeet) {
            // Activity gone; nothing to do, drop the stale pointers.
            $this->mark_events_synced($eventids, $now);
            return;
        }

        mtrace("mod_googlemeet autosync: activity #{$googlemeetid} ({$googlemeet->name}) "
            . count($eventids) . " event(s) due.");

        if (empty($creatoremail)) {
            mtrace("  no creatoremail recorded; skipping and marking synced (single-attempt policy).");
            $this->mark_events_synced($eventids, $now);
            return;
        }

        // Locate a non-deleted Moodle user matching the creator's Google email.
        $creator = $DB->get_record('user', ['email' => $creatoremail, 'deleted' => 0]);
        if (!$creator) {
            mtrace("  no Moodle user with email {$creatoremail}; marking synced.");
            $this->mark_events_synced($eventids, $now);
            return;
        }

        // Impersonate so core\oauth2\client resolves the refresh token for this user.
        $previoususer = $GLOBALS['USER'] ?? null;
        \core\session\manager::set_user($creator);

        try {
            $client = new client();
            if (!$client->enabled || !$client->check_login()) {
                mtrace("  creator {$creator->username} is not logged-in to Google (token missing/revoked); marking synced.");
                $this->mark_events_synced($eventids, $now);
                return;
            }

            try {
                $stats = $client->syncrecordings($googlemeet, true);
                if (is_array($stats)) {
                    mtrace("  sync OK: inserted={$stats['inserted']} updated={$stats['updated']} "
                        . "deleted={$stats['deleted']} found={$stats['found']}.");
                } else {
                    mtrace("  sync completed.");
                }
            } catch (\Throwable $e) {
                // Never retry (single-attempt policy) but log the failure.
                mtrace("  sync failed: " . $e->getMessage());
            }

            $this->mark_events_synced($eventids, $now);
        } finally {
            if ($previoususer) {
                \core\session\manager::set_user($previoususer);
            }
        }
    }

    /**
     * Mark the given event ids as auto-synced with the given timestamp.
     *
     * @param int[] $eventids
     * @param int $now
     */
    private function mark_events_synced(array $eventids, int $now): void {
        global $DB;
        if (empty($eventids)) {
            return;
        }
        list($insql, $params) = $DB->get_in_or_equal($eventids, SQL_PARAMS_NAMED);
        $params['now'] = $now;
        $DB->execute("UPDATE {googlemeet_events} SET autosynced = :now WHERE id {$insql}", $params);
    }
}
