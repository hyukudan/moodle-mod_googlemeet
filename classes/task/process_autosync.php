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
 * Google Meet task - Auto-sync recordings N hours after a session ends, with retries.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_googlemeet\task;

defined('MOODLE_INTERNAL') || die();

use mod_googlemeet\client;

/**
 * For each googlemeet activity that has autosynchours > 0, find events that are due for
 * an auto-sync attempt (either the first attempt or a scheduled retry), then run
 * the Drive sync impersonating the activity creator via their stored refresh token.
 *
 * Retry policy is controlled by site config:
 *   - mod_googlemeet/maxsyncattempts (int, default 1)
 *   - mod_googlemeet/syncretryinterval (int seconds, default 3600, min 60)
 *
 * Per-attempt outcome is decided by classify_outcome(): 'success', 'retry' or 'permanent'.
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
        $max = (int) get_config('googlemeet', 'maxsyncattempts');
        if ($max < 1) {
            $max = 1;
        }
        $interval = (int) get_config('googlemeet', 'syncretryinterval');
        if ($interval < 60) {
            $interval = 60;
        }

        // Find events due for an auto-sync attempt.
        // Conditions:
        //   - activity opted-in (autosynchours > 0)
        //   - event still open (autosynced = 0)
        //   - attempts not exhausted yet (syncattempts < :maxattempts)
        //   - either first attempt due (syncattempts = 0 AND end + autosynchours <= now)
        //     or scheduled retry due (syncattempts > 0 AND nextsyncattempt <= now)
        $sql = "SELECT ge.id AS eventid,
                       ge.googlemeetid,
                       ge.eventdate,
                       ge.duration,
                       ge.syncattempts,
                       ge.nextsyncattempt,
                       gm.autosynchours,
                       gm.creatoremail
                  FROM {googlemeet_events} ge
                  JOIN {googlemeet} gm ON gm.id = ge.googlemeetid
                 WHERE gm.autosynchours > 0
                   AND ge.autosynced = 0
                   AND ge.syncattempts < :maxattempts
                   AND (
                        (ge.syncattempts = 0
                            AND (ge.eventdate + ge.duration + (gm.autosynchours * 3600)) <= :now1)
                     OR (ge.syncattempts > 0
                            AND ge.nextsyncattempt <= :now2)
                   )
              ORDER BY ge.googlemeetid, ge.eventdate";

        $rows = $DB->get_records_sql($sql, [
            'maxattempts' => $max,
            'now1' => $now,
            'now2' => $now,
        ]);

        if (empty($rows)) {
            return;
        }

        // Group events by activity so we run syncrecordings() once per activity per task tick.
        $byactivity = [];
        foreach ($rows as $row) {
            $byactivity[$row->googlemeetid]['creatoremail'] = $row->creatoremail;
            $byactivity[$row->googlemeetid]['events'][] = $row;
        }

        foreach ($byactivity as $googlemeetid => $info) {
            $this->process_activity((int) $googlemeetid, $info['creatoremail'], $info['events'], $now, $max, $interval);
        }
    }

    /**
     * Sync one activity impersonating its creator, then resolve the outcome for each
     * due event of this activity (success → close, retry → schedule, permanent → close).
     *
     * @param int $googlemeetid
     * @param string $creatoremail
     * @param \stdClass[] $events Due event rows for this activity
     * @param int $now
     * @param int $max Max attempts per event
     * @param int $interval Seconds between retries
     */
    private function process_activity(
        int $googlemeetid,
        string $creatoremail,
        array $events,
        int $now,
        int $max,
        int $interval
    ): void {
        global $DB;

        $googlemeet = $DB->get_record('googlemeet', ['id' => $googlemeetid]);
        if (!$googlemeet) {
            // Activity gone; close all stale pointers immediately (no point retrying).
            foreach ($events as $ev) {
                $this->close_event((int) $ev->eventid, (int) $ev->syncattempts + 1, $now);
            }
            return;
        }

        mtrace("mod_googlemeet autosync: activity #{$googlemeetid} ({$googlemeet->name}) "
            . count($events) . " event(s) due.");

        // Inputs we will pass to classify_outcome(). Default values cover the no-creatoremail and
        // no-Moodle-user paths so we still record an attempt even when sync cannot run.
        $loggedin = false;
        $exception = null;
        $stats = null;

        if (empty($creatoremail)) {
            mtrace("  no creatoremail recorded for this activity.");
        } else {
            $creator = $DB->get_record('user', ['email' => $creatoremail, 'deleted' => 0]);
            if (!$creator) {
                mtrace("  no active Moodle user with email {$creatoremail}.");
            } else {
                // Impersonate so core\oauth2\client resolves the refresh token for this user.
                $previoususer = $GLOBALS['USER'] ?? null;
                \core\session\manager::set_user($creator);

                try {
                    $client = new client();
                    if (!$client->enabled || !$client->check_login()) {
                        mtrace("  creator {$creator->username} is not logged-in to Google "
                            . "(token missing/revoked).");
                    } else {
                        $loggedin = true;
                        try {
                            $stats = $client->syncrecordings($googlemeet, true);
                            if (is_array($stats)) {
                                mtrace("  sync ran: inserted={$stats['inserted']} "
                                    . "updated={$stats['updated']} deleted={$stats['deleted']} "
                                    . "found={$stats['found']}.");
                            }
                        } catch (\Throwable $e) {
                            $exception = $e;
                            mtrace("  sync threw: " . $e->getMessage());
                        }
                    }
                } finally {
                    if ($previoususer) {
                        \core\session\manager::set_user($previoususer);
                    }
                }
            }
        }

        // Resolve outcome for each due event of this activity.
        foreach ($events as $ev) {
            $newattempts = (int) $ev->syncattempts + 1;
            $outcome = $this->classify_outcome($loggedin, $exception, $stats, $ev, (int) $ev->syncattempts);

            if ($outcome === 'success' || $outcome === 'permanent') {
                $this->close_event((int) $ev->eventid, $newattempts, $now);
                mtrace("  event #{$ev->eventid}: closed (outcome={$outcome}, attempts={$newattempts}).");
                continue;
            }

            // outcome === 'retry'
            if ($newattempts >= $max) {
                $this->close_event((int) $ev->eventid, $newattempts, $now);
                mtrace("  event #{$ev->eventid}: max attempts reached ({$newattempts}/{$max}); giving up.");
                continue;
            }

            $next = $now + $interval;
            $DB->execute(
                "UPDATE {googlemeet_events}
                    SET syncattempts = :attempts,
                        nextsyncattempt = :next
                  WHERE id = :id",
                ['attempts' => $newattempts, 'next' => $next, 'id' => $ev->eventid]
            );
            mtrace("  event #{$ev->eventid}: scheduled retry #{$newattempts} at "
                . userdate($next) . ".");
        }
    }

    /**
     * Decide what to do with a sync attempt.
     *
     * Returns one of:
     *   - 'success'   → grabbed what we needed; close this event forever
     *   - 'permanent' → no point retrying (e.g. cancelled class, deleted creator); close
     *   - 'retry'     → transient or recoverable failure; schedule another attempt
     *
     * Inputs:
     *   $loggedin   bool   True iff client->check_login() succeeded for the creator.
     *                      False covers: no creatoremail, no Moodle user, token revoked.
     *   $exception  \Throwable|null  Set if syncrecordings() threw; null otherwise.
     *   $stats      array|null  ['inserted','updated','deleted','found'] when sync ran;
     *                           null if sync was skipped (e.g. not logged in).
     *   $event      \stdClass   Has fields eventid, eventdate, duration, syncattempts.
     *   $attempts_done int      Attempts BEFORE this one (so this is attempt #attempts_done+1).
     *
     * --------------------------------------------------------------------
     * TODO (you decide): replace the body below with the policy you want.
     *
     * Building blocks you can mix:
     *   $hard_failure   = !$loggedin;                              // token issue (or no user)
     *   $api_failure    = $exception !== null;                     // exception during sync
     *   $got_recordings = is_array($stats)
     *                     && (($stats['inserted'] ?? 0) > 0 || ($stats['updated'] ?? 0) > 0);
     *   $found_zero     = is_array($stats) && (int)($stats['found'] ?? 0) === 0;
     *   $event_age_h    = (time() - ($event->eventdate + $event->duration)) / 3600;
     *
     * Sketches:
     *
     *   // (A) Aggressive retry — recommended for Tuesday-style cases:
     *   if ($got_recordings)            return 'success';
     *   if ($hard_failure || $api_failure || $found_zero) return 'retry';
     *   return 'success';   // sync ran, found something (updated/deleted only)
     *
     *   // (B) Conservative — only retry hard failures, accept "0 recordings" as final:
     *   if ($got_recordings)            return 'success';
     *   if ($hard_failure || $api_failure) return 'retry';
     *   return 'permanent'; // 0 recordings = class cancelled, don't keep checking
     *
     *   // (C) Time-bounded "0 recordings" handling:
     *   if ($got_recordings)            return 'success';
     *   if ($hard_failure || $api_failure) return 'retry';
     *   if ($found_zero && $event_age_h < 24) return 'retry';   // give Drive 24h
     *   return 'permanent';
     * --------------------------------------------------------------------
     *
     * @param bool $loggedin
     * @param \Throwable|null $exception
     * @param array|null $stats
     * @param \stdClass $event
     * @param int $attempts_done
     * @return string
     */
    private function classify_outcome(
        bool $loggedin,
        ?\Throwable $exception,
        ?array $stats,
        \stdClass $event,
        int $attempts_done
    ): string {
        // Placeholder: keep retrying until max-attempts caps it.
        // Replace with one of the sketches above (or your own variant).
        if (is_array($stats)
            && ((int) ($stats['inserted'] ?? 0) > 0 || (int) ($stats['updated'] ?? 0) > 0)) {
            return 'success';
        }
        return 'retry';
    }

    /**
     * Close an event: bump syncattempts and set autosynced = $now (no further retries).
     *
     * @param int $eventid
     * @param int $newattempts
     * @param int $now
     */
    private function close_event(int $eventid, int $newattempts, int $now): void {
        global $DB;
        $DB->execute(
            "UPDATE {googlemeet_events}
                SET autosynced = :now,
                    syncattempts = :attempts
              WHERE id = :id",
            ['now' => $now, 'attempts' => $newattempts, 'id' => $eventid]
        );
    }
}
