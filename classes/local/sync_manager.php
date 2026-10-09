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

use mod_googlemeet\client;
use mod_googlemeet\task\sync_recordings_task;
use stdClass;

/**
 * Manual "Sync with Google Drive" run in the background (PERF-02).
 *
 * The button only queues an adhoc task (one per activity at a time) and the page polls
 * get_status() through a web service. The task runs the same Drive sync as before, as the
 * teacher who asked for it, under the existing per-activity lock `sync_<id>` shared with autosync.
 *
 * Adhoc tasks depend on cron frequency: run cron at least every minute for a snappy UI.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_manager {

    /** @var int A queued/running row older than this no longer blocks a new request (lost task, dead cron). */
    public const STALE_SECONDS = 2 * HOURSECS;

    /** @var callable|null Test seam: fn(stdClass $googlemeet, stdClass $user): array stats. */
    private static $runner = null;

    /**
     * Override the Drive sync call (PHPUnit only).
     *
     * @param callable|null $runner fn(stdClass $googlemeet, stdClass $user): array, null restores the default.
     * @return void
     */
    public static function set_runner(?callable $runner): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('sync_manager::set_runner() is for unit tests only.');
        }
        self::$runner = $runner;
    }

    /**
     * The manual sync currently queued or running for an activity, if any.
     *
     * @param int $googlemeetid
     * @param int|null $now
     * @return stdClass|null sync_log row.
     */
    public static function get_active(int $googlemeetid, ?int $now = null): ?stdClass {
        $now = $now ?? time();
        $row = sync_log::latest($googlemeetid, sync_log::KIND_MANUAL);
        if (!$row || !in_array($row->status, [sync_log::STATUS_QUEUED, sync_log::STATUS_RUNNING], true)) {
            return null;
        }
        if ((int)$row->timequeued < $now - self::STALE_SECONDS) {
            return null;
        }
        return $row;
    }

    /**
     * Queue a manual sync unless one is already queued/running for this activity.
     *
     * @param stdClass $googlemeet Activity record.
     * @param int $userid Teacher whose Google token is used.
     * @return bool True when a new task was queued, false when one was already pending.
     */
    public static function request(stdClass $googlemeet, int $userid): bool {
        // Serialise concurrent clicks (two tabs, double click) on the check-then-insert.
        $lockfactory = \core\lock\lock_config::get_lock_factory('mod_googlemeet');
        $lock = $lockfactory->get_lock('syncqueue_' . $googlemeet->id, 5);
        if (!$lock) {
            return false;
        }
        try {
            $active = self::get_active((int)$googlemeet->id);
            if ($active) {
                return false;
            }
            $stale = sync_log::latest((int)$googlemeet->id, sync_log::KIND_MANUAL);
            if ($stale && in_array($stale->status, [sync_log::STATUS_QUEUED, sync_log::STATUS_RUNNING], true)) {
                sync_log::finish((int)$stale->id, sync_log::STATUS_ERROR, null,
                    get_string('sync_status_stale', 'googlemeet'));
            }

            $logid = sync_log::start((int)$googlemeet->id, sync_log::KIND_MANUAL, sync_log::STATUS_QUEUED);
            $task = new sync_recordings_task();
            $task->set_component('mod_googlemeet');
            $task->set_custom_data([
                'googlemeetid' => (int)$googlemeet->id,
                'userid' => $userid,
                'logid' => $logid,
            ]);
            \core\task\manager::queue_adhoc_task($task);
            return true;
        } finally {
            $lock->release();
        }
    }

    /**
     * Run a queued manual sync (adhoc task body).
     *
     * @param int $googlemeetid
     * @param int $userid
     * @param int $logid
     * @return string Final status (success|error).
     * @throws \moodle_exception When another sync holds the activity lock (the task is retried).
     */
    public static function run(int $googlemeetid, int $userid, int $logid): string {
        global $DB;

        $googlemeet = $DB->get_record('googlemeet', ['id' => $googlemeetid]);
        if (!$googlemeet) {
            mtrace("mod_googlemeet sync: activity={$googlemeetid} result=skipped detail=\"activity deleted\"");
            return sync_log::STATUS_ERROR;
        }
        if (!$logid || !$DB->record_exists(sync_log::TABLE, ['id' => $logid])) {
            $logid = sync_log::start($googlemeetid, sync_log::KIND_MANUAL, sync_log::STATUS_QUEUED);
        }

        $lockfactory = \core\lock\lock_config::get_lock_factory('mod_googlemeet');
        $lock = $lockfactory->get_lock('sync_' . $googlemeetid, 5);
        if (!$lock) {
            // Autosync (or another manual run) is busy with this activity: let the task API retry us.
            mtrace("mod_googlemeet sync: activity={$googlemeetid} result=deferred detail=\"activity lock busy\"");
            throw new \moodle_exception('sync_already_running', 'googlemeet');
        }

        $start = microtime(true);
        sync_log::mark_running($logid);
        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0, 'suspended' => 0]);
        $previoususer = $GLOBALS['USER'] ?? null;
        $status = sync_log::STATUS_ERROR;
        $stats = null;
        try {
            if (!$user) {
                throw new \moodle_exception('sync_status_nouser', 'googlemeet');
            }
            \core\session\manager::set_user($user);
            $stats = self::$runner !== null ? (self::$runner)($googlemeet, $user) : self::default_runner($googlemeet);
            $stats = is_array($stats) ? $stats : [];
            $status = sync_log::STATUS_SUCCESS;
            $message = self::build_message($stats);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
        } finally {
            if ($previoususer) {
                \core\session\manager::set_user($previoususer);
            }
            $lock->release();
        }

        sync_log::finish($logid, $status, $stats, $message);
        $elapsed = round(microtime(true) - $start, 1);
        $counters = $stats ? ' ' . http_build_query($stats, '', ' ') : '';
        mtrace("mod_googlemeet sync: kind=manual activity={$googlemeetid} user={$userid} result={$status}"
            . "{$counters} seconds={$elapsed}" . ($status !== sync_log::STATUS_SUCCESS ? ' detail="' . $message . '"' : ''));
        return $status;
    }

    /**
     * The real Drive sync, as the already-impersonated teacher.
     *
     * @param stdClass $googlemeet
     * @return array Stats.
     */
    private static function default_runner(stdClass $googlemeet): array {
        global $CFG;
        require_once($CFG->dirroot . '/mod/googlemeet/locallib.php');

        $client = new client();
        if (!$client->enabled || !$client->check_login()) {
            throw new \moodle_exception('sync_status_notlinked', 'googlemeet');
        }
        googlemeet_reset_exhausted_autosync_events((int)$googlemeet->id);
        return $client->syncrecordings($googlemeet, true, true) ?: [];
    }

    /**
     * User-facing result text (same wording as the former synchronous sync).
     *
     * @param array $stats
     * @return string
     */
    public static function build_message(array $stats): string {
        $parts = [];
        $map = ['inserted' => 'sync_new_recordings', 'updated' => 'sync_updated_recordings',
            'trashed' => 'sync_trashed_recordings', 'restored' => 'sync_restored_recordings',
            'deleted' => 'sync_deleted_recordings'];
        foreach ($map as $key => $string) {
            if ((int)($stats[$key] ?? 0) > 0) {
                $parts[] = get_string($string, 'googlemeet', (int)$stats[$key]);
            }
        }
        if (!$parts) {
            $found = (int)($stats['found'] ?? 0);
            return $found > 0 ? get_string('sync_no_changes', 'googlemeet', $found)
                : get_string('sync_no_recordings_found', 'googlemeet');
        }
        $message = implode('. ', $parts) . '.';
        if ((int)($stats['inserted'] ?? 0) > 0 || (int)($stats['restored'] ?? 0) > 0) {
            $message .= ' ' . get_string('sync_enrichment_queued', 'googlemeet');
        }
        return $message;
    }

    /**
     * Status for the polling UI / web service.
     *
     * @param stdClass $googlemeet Activity record.
     * @return array
     */
    public static function get_status(stdClass $googlemeet): array {
        global $DB;

        $row = sync_log::latest((int)$googlemeet->id, sync_log::KIND_MANUAL);
        $status = $row ? (string)$row->status : 'none';
        if ($row && in_array($status, [sync_log::STATUS_QUEUED, sync_log::STATUS_RUNNING], true)
                && !self::get_active((int)$googlemeet->id)) {
            $status = sync_log::STATUS_ERROR;
            $row->message = get_string('sync_status_stale', 'googlemeet');
        }
        $stats = $row && $row->stats ? (json_decode($row->stats, true) ?: []) : [];

        switch ($status) {
            case sync_log::STATUS_QUEUED:
                $message = get_string('sync_status_queued', 'googlemeet');
                break;
            case sync_log::STATUS_RUNNING:
                $message = get_string('sync_status_running', 'googlemeet');
                break;
            case 'none':
                $message = '';
                break;
            default:
                $message = (string)$row->message;
        }

        $lastsync = (int)$DB->get_field('googlemeet', 'lastsync', ['id' => $googlemeet->id]);
        return [
            'status' => $status,
            'active' => in_array($status, [sync_log::STATUS_QUEUED, sync_log::STATUS_RUNNING], true),
            'message' => $message,
            'timequeued' => $row ? (int)$row->timequeued : 0,
            'timefinished' => $row ? (int)$row->timefinished : 0,
            'lastsync' => $lastsync ? userdate($lastsync, get_string('timedate', 'googlemeet'))
                : get_string('never', 'googlemeet'),
            'haschanges' => ((int)($stats['inserted'] ?? 0) + (int)($stats['restored'] ?? 0)
                + (int)($stats['trashed'] ?? 0) + (int)($stats['deleted'] ?? 0)) > 0,
        ];
    }
}
