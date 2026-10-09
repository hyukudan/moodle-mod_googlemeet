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

/**
 * Operational log of Google syncs (PERF-02 manual sync status, DAT-04 calendar ops, OPS-04 admin page).
 *
 * One row per run: manual Drive sync, autosync tick per activity, Calendar patch/delete. Rows hold
 * no personal data (no user id) and are purged after RETENTION_DAYS.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_log {

    /** @var string Teacher-requested Drive sync (adhoc). */
    public const KIND_MANUAL = 'manual';
    /** @var string Scheduled autosync of one activity. */
    public const KIND_AUTO = 'auto';
    /** @var string Calendar events.patch. */
    public const KIND_CALENDAR_UPDATE = 'calupdate';
    /** @var string Calendar events.delete. */
    public const KIND_CALENDAR_DELETE = 'caldelete';

    /** @var string Waiting for cron. */
    public const STATUS_QUEUED = 'queued';
    /** @var string In progress. */
    public const STATUS_RUNNING = 'running';
    /** @var string Done. */
    public const STATUS_SUCCESS = 'success';
    /** @var string Autosync found nothing yet, will retry. */
    public const STATUS_RETRY = 'retry';
    /** @var string Autosync gave up on at least one session. */
    public const STATUS_EXHAUSTED = 'exhausted';
    /** @var string Failed. */
    public const STATUS_ERROR = 'error';

    /** @var int Days rows are kept. */
    public const RETENTION_DAYS = 60;

    /** @var string DB table. */
    public const TABLE = 'googlemeet_sync_log';

    /**
     * Create a row.
     *
     * @param int $googlemeetid
     * @param string $kind KIND_*
     * @param string $status STATUS_*
     * @param int|null $now
     * @return int Row id.
     */
    public static function start(int $googlemeetid, string $kind, string $status = self::STATUS_QUEUED, ?int $now = null): int {
        global $DB;
        $now = $now ?? time();
        self::purge($now);
        return (int)$DB->insert_record(self::TABLE, (object)[
            'googlemeetid' => $googlemeetid,
            'kind' => $kind,
            'status' => $status,
            'timequeued' => $now,
            'timestarted' => $status === self::STATUS_RUNNING ? $now : 0,
            'timefinished' => 0,
            'stats' => null,
            'message' => null,
        ]);
    }

    /**
     * Mark a row as running.
     *
     * @param int $id
     * @return void
     */
    public static function mark_running(int $id): void {
        global $DB;
        $DB->update_record(self::TABLE, (object)['id' => $id, 'status' => self::STATUS_RUNNING, 'timestarted' => time()]);
    }

    /**
     * Close a row.
     *
     * @param int $id
     * @param string $status STATUS_*
     * @param array|null $stats Sync counters.
     * @param string $message Human readable result / error.
     * @return void
     */
    public static function finish(int $id, string $status, ?array $stats = null, string $message = ''): void {
        global $DB;
        $DB->update_record(self::TABLE, (object)[
            'id' => $id,
            'status' => $status,
            'timefinished' => time(),
            'stats' => $stats === null ? null : json_encode($stats),
            'message' => \core_text::substr($message, 0, 2000),
        ]);
    }

    /**
     * Latest row of a kind for an activity.
     *
     * @param int $googlemeetid
     * @param string $kind
     * @return \stdClass|null
     */
    public static function latest(int $googlemeetid, string $kind): ?\stdClass {
        global $DB;
        $rows = $DB->get_records(self::TABLE, ['googlemeetid' => $googlemeetid, 'kind' => $kind], 'id DESC', '*', 0, 1);
        return $rows ? reset($rows) : null;
    }

    /**
     * Drop old rows.
     *
     * @param int $now
     * @return void
     */
    public static function purge(int $now): void {
        global $DB;
        $DB->delete_records_select(self::TABLE, 'timequeued < :cutoff', ['cutoff' => $now - self::RETENTION_DAYS * DAYSECS]);
    }
}
