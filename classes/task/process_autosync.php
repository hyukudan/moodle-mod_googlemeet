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
        // Guard against overlapping cron runs picking up the same pending events.
        // If another run already holds the lock, skip this tick rather than wait.
        $lockfactory = \core\lock\lock_config::get_lock_factory('mod_googlemeet_autosync');
        $lock = $lockfactory->get_lock('process_autosync', 0);
        if (!$lock) {
            mtrace('mod_googlemeet autosync: another run holds the lock; skipping this tick.');
            return;
        }

        try {
            $this->run_due_events();
        } finally {
            $lock->release();
        }
    }

    /**
     * Find and process all events that are due for an auto-sync attempt.
     *
     * Split out from execute() so the cron lock acquired there always wraps the work
     * and is released in a finally block.
     */
    private function run_due_events(): void {
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
        // $identitymissing flags the permanent "nobody to authenticate as" case, which must be
        // kept distinct from a merely missing/revoked token (where $loggedin is also false but
        // the creator may re-link Google later, so we keep retrying).
        $loggedin = false;
        $exception = null;
        $stats = null;
        $identitymissing = false;

        if (empty($creatoremail)) {
            mtrace("  no creatoremail recorded for this activity.");
            $identitymissing = true;
        } else {
            $creator = $DB->get_record('user', ['email' => $creatoremail, 'deleted' => 0]);
            if (!$creator) {
                mtrace("  no active Moodle user with email {$creatoremail}.");
                $identitymissing = true;
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
            $outcome = $this->classify_outcome(
                $loggedin,
                $exception,
                $stats,
                $identitymissing,
                $ev,
                (int) $ev->syncattempts
            );

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
     * Policy: retry transient failures until maxsyncattempts is exhausted, and close
     * without further retries on success or on a permanent error.
     *   - success   → the sync ran and brought in at least one recording
     *                 (inserted or updated > 0); the event is done.
     *   - permanent → a non-recoverable condition that retrying cannot fix: no creator
     *                 email recorded, or no active Moodle user for it. There is nobody
     *                 to authenticate as, so stop.
     *   - retry     → any transient/recoverable condition: token missing/revoked or
     *                 not logged in (creator may re-link Google), an exception during
     *                 sync (network/Drive API error), or the sync ran but Drive has no
     *                 recordings yet (Google may still be processing them). The
     *                 attempt cap in process_activity() bounds these retries.
     *
     * @param bool $loggedin True iff client->check_login() succeeded for the creator.
     *                       False covers: no creator email, no Moodle user, token revoked.
     * @param \Throwable|null $exception Set if syncrecordings() threw; null otherwise.
     * @param array|null $stats ['inserted','updated','deleted','found'] when sync ran;
     *                          null if sync was skipped (e.g. not logged in).
     * @param bool $identitymissing True when there is no creator email or no Moodle user
     *                              for it (the only permanent, non-recoverable failures).
     * @param \stdClass $event Has fields eventid, eventdate, duration, syncattempts.
     * @param int $attemptsdone Attempts BEFORE this one (so this is attempt #attemptsdone+1).
     * @return string One of 'success', 'permanent', 'retry'.
     */
    private function classify_outcome(
        bool $loggedin,
        ?\Throwable $exception,
        ?array $stats,
        bool $identitymissing,
        \stdClass $event,
        int $attemptsdone
    ): string {
        // Got what we came for: at least one recording landed in the DB.
        if (is_array($stats)
            && ((int) ($stats['inserted'] ?? 0) > 0 || (int) ($stats['updated'] ?? 0) > 0)) {
            return 'success';
        }

        // Permanent: nobody to authenticate as. Nothing about this can change by retrying.
        if ($identitymissing) {
            return 'permanent';
        }

        // Everything else is transient: not logged in (token revoked/missing), an exception
        // during sync (network/Drive API), or the sync ran but found no recordings yet.
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
