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
 * Data for the admin "Google Meet sync status" page (OPS-04).
 *
 * Lets an admin see, without reading cron logs, which activities had autosync failures or gave
 * up in the last days, which AI analyses look stuck and when each activity last synced.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ops_status {

    /** @var int Look-back window for failures. */
    public const WINDOW_DAYS = 7;

    /** @var int Legacy fallback; the page uses ai_service::get_stuck_threshold() (googlemeet/aistuckminutes). */
    public const STUCK_PROCESSING_SECONDS = HOURSECS;

    /** @var int A "pending" analysis older than this is considered stuck (cron runs every 10 min). */
    public const STUCK_PENDING_SECONDS = 6 * HOURSECS;

    /** @var int Max rows of the "last sync" table. */
    public const LAST_SYNC_LIMIT = 300;

    /**
     * Activities with failed, retrying or exhausted autosync in the window.
     *
     * Combines the sync log (failed runs since this version) with googlemeet_events, which also
     * covers older history: sessions waiting for a retry and sessions closed after reaching the
     * maximum number of attempts.
     *
     * @param int|null $now
     * @return \stdClass[] Keyed by googlemeet id: id, name, course, coursename, cmid, creatoremail, failedruns,
     *                    lastfailure, lastmessage, laststatus, retrying, exhausted.
     */
    public static function autosync_problems(?int $now = null): array {
        global $DB;
        $now = $now ?? time();
        $since = $now - self::WINDOW_DAYS * DAYSECS;
        $max = max(1, (int)get_config('googlemeet', 'maxsyncattempts'));

        $rows = [];
        $logs = $DB->get_records_select(sync_log::TABLE,
            'kind = :kind AND timequeued >= :since AND status IN (:s1, :s2, :s3)',
            ['kind' => sync_log::KIND_AUTO, 'since' => $since, 's1' => sync_log::STATUS_ERROR,
                's2' => sync_log::STATUS_EXHAUSTED, 's3' => sync_log::STATUS_RETRY],
            'id ASC', 'id, googlemeetid, status, timequeued, message');
        foreach ($logs as $log) {
            $row = self::row($rows, (int)$log->googlemeetid);
            $row->failedruns++;
            $row->lastfailure = (int)$log->timequeued;
            $row->laststatus = $log->status;
            $row->lastmessage = (string)$log->message;
        }

        $retrying = $DB->get_records_sql(
            "SELECT googlemeetid, COUNT(1) AS n
               FROM {googlemeet_events}
              WHERE autosynced = 0 AND syncattempts > 0
           GROUP BY googlemeetid");
        foreach ($retrying as $r) {
            self::row($rows, (int)$r->googlemeetid)->retrying = (int)$r->n;
        }
        $exhausted = $DB->get_records_sql(
            "SELECT googlemeetid, COUNT(1) AS n
               FROM {googlemeet_events}
              WHERE autosynced >= :since AND syncattempts >= :max
           GROUP BY googlemeetid", ['since' => $since, 'max' => $max]);
        foreach ($exhausted as $r) {
            self::row($rows, (int)$r->googlemeetid)->exhausted = (int)$r->n;
        }

        // Exhausted events also include sessions that succeeded on their last attempt: only keep
        // activities whose log/events actually show trouble.
        foreach ($rows as $id => $row) {
            $haslogfailure = $row->failedruns > 0;
            if (!$haslogfailure && !$row->retrying && !self::exhausted_without_success((int)$id, $since, $row->exhausted)) {
                unset($rows[$id]);
            }
        }
        return self::attach_activities($rows);
    }

    /**
     * Whether "exhausted" events of an activity are real give-ups (no successful autosync after them).
     *
     * @param int $googlemeetid
     * @param int $since
     * @param int $exhausted
     * @return bool
     */
    private static function exhausted_without_success(int $googlemeetid, int $since, int $exhausted): bool {
        global $DB;
        if ($exhausted < 1) {
            return false;
        }
        // Without log history (older runs) trust the events table.
        return !$DB->record_exists_select(sync_log::TABLE,
            'googlemeetid = :id AND kind = :kind AND status = :status AND timequeued >= :since',
            ['id' => $googlemeetid, 'kind' => sync_log::KIND_AUTO, 'status' => sync_log::STATUS_SUCCESS, 'since' => $since]);
    }

    /**
     * Get or create the accumulator row of an activity.
     *
     * @param array $rows
     * @param int $id
     * @return \stdClass
     */
    private static function row(array &$rows, int $id): \stdClass {
        if (!isset($rows[$id])) {
            $rows[$id] = (object)['id' => $id, 'failedruns' => 0, 'lastfailure' => 0, 'laststatus' => '',
                'lastmessage' => '', 'retrying' => 0, 'exhausted' => 0];
        }
        return $rows[$id];
    }

    /**
     * Add activity name, course and cmid; drop rows of deleted activities.
     *
     * @param \stdClass[] $rows Keyed by googlemeet id.
     * @return \stdClass[]
     */
    private static function attach_activities(array $rows): array {
        global $DB;
        if (!$rows) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_keys($rows), SQL_PARAMS_NAMED);
        $params['modname'] = 'googlemeet';
        $activities = $DB->get_records_sql(
            "SELECT gm.id, gm.name, gm.course, gm.creatoremail, gm.lastsync, c.fullname AS coursename, cm.id AS cmid
               FROM {googlemeet} gm
               JOIN {course} c ON c.id = gm.course
               JOIN {modules} m ON m.name = :modname
               JOIN {course_modules} cm ON cm.instance = gm.id AND cm.module = m.id
              WHERE gm.id {$insql}", $params);
        $result = [];
        foreach ($rows as $id => $row) {
            if (!isset($activities[$id])) {
                continue;
            }
            foreach ((array)$activities[$id] as $key => $value) {
                $row->$key = $value;
            }
            $result[$id] = $row;
        }
        return $result;
    }

    /**
     * AI analyses that look stuck (processing too long, or pending far longer than the cron period).
     *
     * @param int|null $now
     * @return \stdClass[] id, recordingid, recordingname, status, timemodified, retrycount, error, googlemeetid,
     *                    name, coursename, cmid.
     */
    public static function stuck_ai_analyses(?int $now = null): array {
        global $DB;
        $now = $now ?? time();
        return $DB->get_records_sql(
            "SELECT a.id, a.recordingid, r.name AS recordingname, a.status, a.timemodified, a.retrycount, a.error,
                    gm.id AS googlemeetid, gm.name, c.fullname AS coursename, cm.id AS cmid
               FROM {googlemeet_ai_analysis} a
               JOIN {googlemeet_recordings} r ON r.id = a.recordingid
               JOIN {googlemeet} gm ON gm.id = r.googlemeetid
               JOIN {course} c ON c.id = gm.course
               JOIN {modules} m ON m.name = :modname
               JOIN {course_modules} cm ON cm.instance = gm.id AND cm.module = m.id
              WHERE r.deleted = 0
                AND ((a.status = :processing AND a.timemodified < :t1)
                  OR (a.status = :pending AND a.timemodified < :t2 AND a.retrycount < 99))
           ORDER BY a.timemodified ASC",
            ['modname' => 'googlemeet', 'processing' => 'processing', 'pending' => 'pending',
                't1' => $now - \mod_googlemeet\ai_service::get_stuck_threshold(), 't2' => $now - self::STUCK_PENDING_SECONDS],
            0, 200);
    }

    /**
     * Last sync of each activity (oldest first), with the latest manual and automatic run status.
     *
     * @return \stdClass[] id, name, coursename, cmid, lastsync, autosynchours, manualstatus, manualtime,
     *                    autostatus, autotime.
     */
    public static function last_syncs(): array {
        global $DB;
        $activities = $DB->get_records_sql(
            "SELECT gm.id, gm.name, gm.lastsync, gm.autosynchours, c.fullname AS coursename, cm.id AS cmid
               FROM {googlemeet} gm
               JOIN {course} c ON c.id = gm.course
               JOIN {modules} m ON m.name = :modname
               JOIN {course_modules} cm ON cm.instance = gm.id AND cm.module = m.id
           ORDER BY COALESCE(gm.lastsync, 0) ASC, gm.id ASC",
            ['modname' => 'googlemeet'], 0, self::LAST_SYNC_LIMIT);
        if (!$activities) {
            return [];
        }
        // Latest row per (activity, kind) in one query.
        [$insql, $params] = $DB->get_in_or_equal(array_keys($activities), SQL_PARAMS_NAMED);
        $params['k1'] = sync_log::KIND_MANUAL;
        $params['k2'] = sync_log::KIND_AUTO;
        $latest = $DB->get_records_sql(
            "SELECT l.id, l.googlemeetid, l.kind, l.status, l.timequeued
               FROM {" . sync_log::TABLE . "} l
               JOIN (SELECT googlemeetid, kind, MAX(id) AS maxid
                       FROM {" . sync_log::TABLE . "}
                      WHERE googlemeetid {$insql} AND kind IN (:k1, :k2)
                   GROUP BY googlemeetid, kind) x ON x.maxid = l.id", $params);
        foreach ($activities as $activity) {
            $activity->manualstatus = '';
            $activity->manualtime = 0;
            $activity->autostatus = '';
            $activity->autotime = 0;
        }
        foreach ($latest as $row) {
            $prefix = $row->kind === sync_log::KIND_MANUAL ? 'manual' : 'auto';
            $activities[$row->googlemeetid]->{$prefix . 'status'} = $row->status;
            $activities[$row->googlemeetid]->{$prefix . 'time'} = (int)$row->timequeued;
        }
        return $activities;
    }

    /**
     * Failed Google Calendar updates/deletions in the window.
     *
     * @param int|null $now
     * @return \stdClass[] sync_log rows plus name/cmid when the activity still exists.
     */
    public static function calendar_failures(?int $now = null): array {
        global $DB;
        $now = $now ?? time();
        return $DB->get_records_sql(
            "SELECT l.id, l.googlemeetid, l.kind, l.timequeued, l.message, gm.name, cm.id AS cmid
               FROM {" . sync_log::TABLE . "} l
          LEFT JOIN {googlemeet} gm ON gm.id = l.googlemeetid
          LEFT JOIN {modules} m ON m.name = :modname
          LEFT JOIN {course_modules} cm ON cm.instance = gm.id AND cm.module = m.id
              WHERE l.kind IN (:k1, :k2) AND l.status = :status AND l.timequeued >= :since
           ORDER BY l.timequeued DESC",
            ['modname' => 'googlemeet', 'k1' => sync_log::KIND_CALENDAR_UPDATE, 'k2' => sync_log::KIND_CALENDAR_DELETE,
                'status' => sync_log::STATUS_ERROR, 'since' => $now - self::WINDOW_DAYS * DAYSECS], 0, 200);
    }
}
