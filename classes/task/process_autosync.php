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
use mod_googlemeet\helper;

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/lib.php');

/**
 * For each googlemeet activity that has autosynchours > 0, find events that are due for
 * an auto-sync attempt (either the first attempt or a scheduled retry), then run
 * the Drive sync impersonating the activity creator via their stored refresh token.
 *
 * Retry policy is controlled by site config:
 *   - mod_googlemeet/maxsyncattempts (int, default 3)
 *   - mod_googlemeet/syncretryinterval (int seconds, default 3600, min 60)
 *
 * Per-attempt outcome is decided by classify_outcome(): 'success', 'retry', 'permanent'
 * or 'infra_failure'.
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
        //   - retry cooldown has passed (nextsyncattempt <= now; 0 means immediately)
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
                   AND ge.nextsyncattempt <= :nowcooldown
                   AND (
                        (ge.syncattempts = 0
                            AND (ge.eventdate + ge.duration + (gm.autosynchours * 3600)) <= :now1)
                     OR ge.syncattempts > 0
                   )
              ORDER BY ge.googlemeetid, ge.eventdate";

        $rows = $DB->get_records_sql($sql, [
            'maxattempts' => $max,
            'nowcooldown' => $now,
            'now1' => $now,
        ]);

        if (empty($rows)) {
            return;
        }

        $rows = $this->close_cancelled_events($rows, $now);
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
     * Close due events whose session date is now marked as cancelled.
     *
     * Cancelled sessions intentionally remain in googlemeet_events so the activity can show
     * them in its schedule, but auto-sync should not import accidental short recordings for them.
     *
     * @param \stdClass[] $rows Due event rows.
     * @param int $now Current timestamp.
     * @return \stdClass[] Due rows that still need Drive sync.
     */
    private function close_cancelled_events(array $rows, int $now): array {
        $cancelledbyactivity = [];
        $remaining = [];

        foreach ($rows as $row) {
            $googlemeetid = (int)$row->googlemeetid;
            if (!array_key_exists($googlemeetid, $cancelledbyactivity)) {
                $cancelledbyactivity[$googlemeetid] = \googlemeet_get_cancelled($googlemeetid);
            }

            if (\googlemeet_is_cancelled((int)$row->eventdate, $cancelledbyactivity[$googlemeetid]) !== false) {
                $this->close_event((int)$row->eventid, (int)$row->syncattempts + 1, $now);
                mtrace("mod_googlemeet autosync: event #{$row->eventid} is cancelled; closed without sync.");
                continue;
            }

            $remaining[] = $row;
        }

        return $remaining;
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

        $lockfactory = \core\lock\lock_config::get_lock_factory('mod_googlemeet');
        $lock = $lockfactory->get_lock('sync_' . $googlemeetid, 5);
        if (!$lock) {
            mtrace("mod_googlemeet autosync: activity #{$googlemeetid} is already syncing; skipping this tick.");
            return;
        }

        try {
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
            $authfailure = false;

            if (empty($creatoremail)) {
                mtrace("  no creatoremail recorded for this activity.");
                $identitymissing = true;
            } else {
                $creator = $DB->get_record('user', ['email' => $creatoremail, 'deleted' => 0, 'suspended' => 0]);
                if (!$creator) {
                    mtrace("  no active Moodle user with email {$creatoremail}.");
                    $identitymissing = true;
                } else {
                    // Impersonate so core\oauth2\client resolves the refresh token for this user.
                    $previoususer = $GLOBALS['USER'] ?? null;
                    \core\session\manager::set_user($creator);

                    try {
                        $client = new client();
                        $hadtoken = $DB->record_exists('oauth2_refresh_token', [
                            'userid' => $creator->id,
                            'issuerid' => (int)get_config('googlemeet', 'issuerid'),
                        ]);
                        if (!$client->enabled || !$client->check_login()) {
                            $authfailure = $hadtoken;
                            mtrace("  creator {$creator->username} is not logged-in to Google "
                                . "(token missing/revoked).");
                            if ($authfailure) {
                                self::notify_auth_failure($creatoremail, 'oauth_token_expired');
                            }
                        } else {
                            $loggedin = true;
                            try {
                                $stats = $client->syncrecordings($googlemeet, true);
                                if (is_array($stats)) {
                                    mtrace("  sync ran: inserted={$stats['inserted']} "
                                        . "updated={$stats['updated']} deleted={$stats['deleted']} "
                                        . "trashed={$stats['trashed']} restored={$stats['restored']} "
                                        . "found={$stats['found']}.");
                                }
                            } catch (\Throwable $e) {
                                $exception = $e;
                                mtrace("  sync threw: " . $e->getMessage());
                                if ($e instanceof \moodle_exception && $e->errorcode === 'servicenotenabled') {
                                    self::notify_auth_failure($creatoremail, 'drive_api_disabled');
                                }
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
                    $authfailure,
                    $ev,
                    (int) $ev->syncattempts
                );

                if ($outcome === 'success' || $outcome === 'permanent') {
                    $this->close_event((int) $ev->eventid, $newattempts, $now);
                    mtrace("  event #{$ev->eventid}: closed (outcome={$outcome}, attempts={$newattempts}).");
                    continue;
                }

                if ($outcome === 'infra_failure') {
                    $next = $now + max($interval, 6 * 3600);
                    $DB->execute(
                        "UPDATE {googlemeet_events}
                            SET nextsyncattempt = :next
                          WHERE id = :id",
                        ['next' => $next, 'id' => $ev->eventid]
                    );
                    mtrace("  event #{$ev->eventid}: infra failure; kept OPEN, retry at "
                        . userdate($next) . ".");
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
        } finally {
            $lock->release();
        }
    }

    /**
     * Decide what to do with a sync attempt.
     *
     * Policy: retry recoverable content-timing failures until maxsyncattempts is exhausted,
     * close without further retries on success or a permanent identity error, and keep
     * infrastructure failures open without consuming attempts.
     *   - success       → the sync ran and brought in at least one recording
     *                     (inserted, updated or restored > 0); the event is done.
     *   - permanent     → a non-recoverable condition that retrying cannot fix: no creator
     *                     email recorded, or no active Moodle user for it. There is nobody
     *                     to authenticate as, so stop.
     *   - infra_failure → a recoverable platform/auth/API condition outside the event's
     *                     recording lifecycle; keep the event open and retry later without
     *                     consuming its bounded attempts.
     *   - retry         → the sync ran but Drive has no recordings yet (Google may still
     *                     be processing them). The attempt cap in process_activity()
     *                     bounds these retries.
     *
     * @param bool $loggedin True iff client->check_login() succeeded for the creator.
     *                       False covers: no creator email, no Moodle user, token revoked.
     * @param \Throwable|null $exception Set if syncrecordings() threw; null otherwise.
     * @param array|null $stats ['inserted','updated','deleted','trashed','restored','found'] when sync ran;
     *                          null if sync was skipped (e.g. not logged in).
     * @param bool $identitymissing True when there is no creator email or no Moodle user
     *                              for it (the only permanent, non-recoverable failures).
     * @param bool $authfailure True when a refresh token existed before check_login() but auth failed.
     * @param \stdClass $event Has fields eventid, eventdate, duration, syncattempts.
     * @param int $attemptsdone Attempts BEFORE this one (so this is attempt #attemptsdone+1).
     * @return string One of 'success', 'permanent', 'infra_failure', 'retry'.
     */
    private function classify_outcome(
        bool $loggedin,
        ?\Throwable $exception,
        ?array $stats,
        bool $identitymissing,
        bool $authfailure,
        \stdClass $event,
        int $attemptsdone
    ): string {
        // Got what we came for: at least one recording landed in the DB.
        if (is_array($stats)
            && ((int) ($stats['inserted'] ?? 0) > 0
                || (int) ($stats['updated'] ?? 0) > 0
                || (int) ($stats['restored'] ?? 0) > 0)) {
            return 'success';
        }

        // Permanent: nobody to authenticate as. Nothing about this can change by retrying.
        if ($identitymissing) {
            return 'permanent';
        }

        if ($authfailure) {
            return 'infra_failure';
        }

        if ($exception !== null && helper::is_infrastructure_error($exception)) {
            return 'infra_failure';
        }

        // Everything else is a bounded retry: missing first-time token, or the sync ran but
        // found no recordings yet.
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

    /**
     * Notify support that autosync is blocked by an auth/API infrastructure issue.
     *
     * @param string $creatoremail Google/Moodle account affected.
     * @param string $reason Machine-readable reason.
     */
    private static function notify_auth_failure(string $creatoremail, string $reason): void {
        $now = time();
        $lastalert = (int)get_config('googlemeet', 'lastauthalert');
        if ($lastalert > 0 && ($now - $lastalert) < DAYSECS) {
            return;
        }

        if ($reason === 'drive_api_disabled') {
            $action = 'API Drive/Calendar deshabilitada en Google Cloud Console';
        } else {
            $action = 'Re-vincula Google en una actividad Meet';
        }

        $subject = '[googlemeet] Autosync bloqueado: ' . $reason;
        $body = "Autosync de Google Meet bloqueado.\n\n"
            . "Motivo: {$reason}\n"
            . "Cuenta afectada: {$creatoremail}\n"
            . "Accion requerida: {$action}\n";

        email_to_user(
            \core_user::get_support_user(),
            \core_user::get_noreply_user(),
            $subject,
            $body
        );
        set_config('lastauthalert', $now, 'googlemeet');
    }
}
