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

namespace mod_googlemeet\local\attendance;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/lib.php');

/**
 * Fetches, matches and stores real attendance of the live sessions (ANA-03).
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class service {

    /** @var string Waiting for the first fetch. */
    public const STATUS_PENDING = 'pending';
    /** @var string Participants stored. */
    public const STATUS_DONE = 'done';
    /** @var string Google returned no conference for the session (yet). */
    public const STATUS_NODATA = 'nodata';
    /** @var string The organiser's token lacks the Meet scope: the teacher must link Google again. */
    public const STATUS_SCOPE = 'scope';
    /** @var string Any other failure. */
    public const STATUS_ERROR = 'error';

    /** @var int Attempts per session before giving up (the teacher can ask again). */
    public const MAX_ATTEMPTS = 4;
    /** @var int Seconds between attempts. */
    public const RETRY_INTERVAL = 2 * HOURSECS;
    /** @var int Seconds after the scheduled end before the first fetch. */
    public const FETCH_DELAY = 30 * MINSECS;
    /** @var int Sessions that ended earlier than this are not queued automatically. */
    public const LOOKBACK = 7 * DAYSECS;
    /** @var int Conferences that started this long before/after the scheduled session still belong to it. */
    public const WINDOW_MARGIN = HOURSECS;

    /**
     * Create pending fetch rows for sessions of opted-in activities that ended recently.
     *
     * @param int $now Current time.
     * @return int Rows created.
     */
    public static function queue_due_sessions(int $now): int {
        global $DB;

        if (!scope::site_enabled()) {
            return 0;
        }
        $sql = "SELECT ge.id, ge.googlemeetid, ge.eventdate, ge.duration
                  FROM {googlemeet_events} ge
                  JOIN {googlemeet} gm ON gm.id = ge.googlemeetid
             LEFT JOIN {googlemeet_attendance_sync} s ON s.eventid = ge.id
                 WHERE gm.attendanceenabled = 1
                   AND s.id IS NULL
                   AND ge.eventdate + ge.duration <= :latest
                   AND ge.eventdate + ge.duration >= :earliest";
        $rows = $DB->get_records_sql($sql, ['latest' => $now - self::FETCH_DELAY, 'earliest' => $now - self::LOOKBACK]);

        $created = 0;
        $cancelled = [];
        foreach ($rows as $row) {
            $gid = (int)$row->googlemeetid;
            if (!array_key_exists($gid, $cancelled)) {
                $cancelled[$gid] = googlemeet_get_cancelled($gid);
            }
            if (googlemeet_is_cancelled((int)$row->eventdate, $cancelled[$gid]) !== false) {
                continue;
            }
            $DB->insert_record('googlemeet_attendance_sync', (object)[
                'googlemeetid' => $gid,
                'eventid' => (int)$row->id,
                'status' => self::STATUS_PENDING,
                'attempts' => 0,
                'nextattempt' => 0,
                'timemodified' => $now,
            ]);
            $created++;
        }
        return $created;
    }

    /**
     * Fetch rows due now, grouped by activity id.
     *
     * @param int $now Current time.
     * @return array googlemeetid => sync rows (with eventdate and duration).
     */
    public static function get_due(int $now): array {
        global $DB;

        $sql = "SELECT s.*, ge.eventdate, ge.duration
                  FROM {googlemeet_attendance_sync} s
                  JOIN {googlemeet_events} ge ON ge.id = s.eventid
                  JOIN {googlemeet} gm ON gm.id = s.googlemeetid
                 WHERE gm.attendanceenabled = 1
                   AND s.status <> :done
                   AND s.attempts < :max
                   AND s.nextattempt <= :now
              ORDER BY s.googlemeetid, ge.eventdate";
        $grouped = [];
        foreach ($DB->get_records_sql($sql, ['done' => self::STATUS_DONE, 'max' => self::MAX_ATTEMPTS, 'now' => $now])
                as $row) {
            $grouped[(int)$row->googlemeetid][] = $row;
        }
        return $grouped;
    }

    /**
     * Fetch, match and store the attendance of one session, recording the outcome.
     *
     * @param \stdClass $googlemeet Activity record.
     * @param \stdClass $sync Sync row joined with eventdate and duration.
     * @param source $source Attendance source.
     * @param int|null $now Current time.
     * @return string Resulting status.
     */
    public static function process(\stdClass $googlemeet, \stdClass $sync, source $source, ?int $now = null): string {
        $now = $now ?? time();
        $counts = ['conferences' => 0, 'participants' => 0, 'matched' => 0];
        $message = null;

        $meetingcode = \mod_googlemeet\client::extract_meeting_code((string)($googlemeet->url ?? ''));
        if ($meetingcode === null) {
            self::record_outcome($sync, self::STATUS_ERROR, $counts, 'No valid Meet room URL.', $now, true);
            return self::STATUS_ERROR;
        }

        try {
            $start = (int)$sync->eventdate;
            $end = $start + (int)$sync->duration;
            $conferences = $source->list_conferences($meetingcode, $start - self::WINDOW_MARGIN, $end + self::WINDOW_MARGIN);
            $counts['conferences'] = count($conferences);
            if (!$conferences) {
                self::record_outcome($sync, self::STATUS_NODATA, $counts, null, $now);
                return self::STATUS_NODATA;
            }
            $lists = [];
            foreach ($conferences as $conference) {
                $lists[] = $source->list_participants($conference['name']);
            }
            $participants = self::aggregate($lists, $now);
            $participants = self::match($googlemeet, $participants);
            self::store($googlemeet, (int)$sync->eventid, $participants, $now);
            $counts['participants'] = count($participants);
            $counts['matched'] = count(array_filter($participants, static fn($p) => !empty($p['userid'])));
            self::record_outcome($sync, self::STATUS_DONE, $counts, null, $now);
            return self::STATUS_DONE;
        } catch (scope_exception $e) {
            self::record_outcome($sync, self::STATUS_SCOPE, $counts, $e->debuginfo ?? $e->getMessage(), $now);
            return self::STATUS_SCOPE;
        } catch (\Throwable $e) {
            self::record_outcome($sync, self::STATUS_ERROR, $counts, $e->getMessage(), $now);
            return self::STATUS_ERROR;
        }
    }

    /**
     * Store the outcome of an attempt.
     *
     * @param \stdClass $sync Sync row.
     * @param string $status Status.
     * @param array $counts Counters.
     * @param string|null $message Error message.
     * @param int $now Current time.
     * @param bool $final Do not retry.
     * @return void
     */
    public static function record_outcome(\stdClass $sync, string $status, array $counts, ?string $message, int $now,
            bool $final = false): void {
        global $DB;

        $attempts = (int)$sync->attempts + 1;
        if ($final) {
            $attempts = max($attempts, self::MAX_ATTEMPTS);
        }
        $DB->update_record('googlemeet_attendance_sync', (object)[
            'id' => $sync->id,
            'status' => $status,
            'attempts' => $attempts,
            'nextattempt' => $status === self::STATUS_DONE ? 0 : $now + self::RETRY_INTERVAL,
            'conferences' => (int)($counts['conferences'] ?? 0),
            'participants' => (int)($counts['participants'] ?? 0),
            'matched' => (int)($counts['matched'] ?? 0),
            'message' => $message === null ? null : \core_text::substr($message, 0, 1000),
            'timemodified' => $now,
        ]);
    }

    /**
     * Ask for a new fetch of a session on the next cron run (teacher action).
     *
     * @param int $googlemeetid Activity id.
     * @param int $eventid Session id.
     * @return void
     */
    public static function request_refetch(int $googlemeetid, int $eventid): void {
        global $DB;

        $row = $DB->get_record('googlemeet_attendance_sync', ['eventid' => $eventid, 'googlemeetid' => $googlemeetid]);
        if ($row) {
            $row->status = self::STATUS_PENDING;
            $row->attempts = 0;
            $row->nextattempt = 0;
            $row->timemodified = time();
            $DB->update_record('googlemeet_attendance_sync', $row);
            return;
        }
        if ($DB->record_exists('googlemeet_events', ['id' => $eventid, 'googlemeetid' => $googlemeetid])) {
            $DB->insert_record('googlemeet_attendance_sync', (object)[
                'googlemeetid' => $googlemeetid,
                'eventid' => $eventid,
                'status' => self::STATUS_PENDING,
                'timemodified' => time(),
            ]);
        }
    }

    /**
     * Merge the participants of several conferences: one entry per person, sessions combined.
     *
     * @param array[] $lists Participant lists as returned by source::list_participants().
     * @param int $now Used as the end of sessions that are still open.
     * @return array[] Each: type, displayname, googleuserid, email, timejoined, timeleft, durationseconds, sessions.
     */
    public static function aggregate(array $lists, int $now): array {
        $people = [];
        foreach ($lists as $list) {
            foreach ($list as $p) {
                $type = (string)($p['type'] ?? 'signedin');
                $googleuserid = (string)($p['googleuserid'] ?? '');
                $displayname = trim((string)($p['displayname'] ?? ''));
                $key = $googleuserid !== '' ? 'g:' . $googleuserid : $type . ':' . self::normalise_name($displayname);
                if (!isset($people[$key])) {
                    $people[$key] = [
                        'type' => $type,
                        'displayname' => $displayname,
                        'googleuserid' => $googleuserid,
                        'email' => (string)($p['email'] ?? ''),
                        'intervals' => [],
                    ];
                }
                foreach ((array)($p['sessions'] ?? []) as $session) {
                    $start = (int)($session[0] ?? 0);
                    $end = (int)($session[1] ?? 0);
                    if ($start <= 0) {
                        continue;
                    }
                    if ($end <= 0 || $end < $start) {
                        $end = max($start, $now);
                    }
                    $people[$key]['intervals'][] = [$start, $end];
                }
            }
        }

        $result = [];
        foreach ($people as $person) {
            $intervals = $person['intervals'];
            unset($person['intervals']);
            $person['sessions'] = count($intervals);
            $person['timejoined'] = 0;
            $person['timeleft'] = 0;
            $person['durationseconds'] = self::union_seconds($intervals);
            if ($intervals) {
                $person['timejoined'] = min(array_column($intervals, 0));
                $person['timeleft'] = max(array_column($intervals, 1));
            }
            $result[] = $person;
        }
        return $result;
    }

    /**
     * Seconds covered by a set of possibly overlapping intervals (two devices count once).
     *
     * @param array $intervals [[start, end], ...].
     * @return int
     */
    public static function union_seconds(array $intervals): int {
        usort($intervals, static fn($a, $b) => $a[0] <=> $b[0]);
        $total = 0;
        $curstart = null;
        $curend = null;
        foreach ($intervals as [$start, $end]) {
            if ($curstart === null) {
                [$curstart, $curend] = [$start, $end];
            } else if ($start <= $curend) {
                $curend = max($curend, $end);
            } else {
                $total += $curend - $curstart;
                [$curstart, $curend] = [$start, $end];
            }
        }
        if ($curstart !== null) {
            $total += $curend - $curstart;
        }
        return $total;
    }

    /**
     * Normalise a person name for matching: lower case, accents folded, punctuation removed.
     *
     * @param string $name Name.
     * @return string
     */
    public static function normalise_name(string $name): string {
        $name = googlemeet_fold($name);
        $name = strtr($name, ['à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u', 'ç' => 'c', 'ï' => 'i']);
        $name = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name);
        return trim(preg_replace('/\s+/u', ' ', $name));
    }

    /**
     * Users that can be matched: users enrolled in the course who can view the activity.
     *
     * @param \stdClass $googlemeet Activity record.
     * @return \stdClass[] id => user (id, email, name fields).
     */
    public static function get_candidates(\stdClass $googlemeet): array {
        $cm = get_coursemodule_from_instance('googlemeet', $googlemeet->id, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $fields = 'u.id, u.email, ' . implode(', ', array_map(static fn($f) => 'u.' . $f,
            \core_user\fields::get_name_fields()));
        return get_enrolled_users($context, 'mod/googlemeet:view', 0, $fields, null, 0, 0, true);
    }

    /**
     * Assign a Moodle user to each participant: e-mail, then a remembered Google id, then the name.
     *
     * A name only matches when exactly one candidate fits. Google never returns e-mails through the
     * Meet API today, but sources that provide them are matched first.
     *
     * @param \stdClass $googlemeet Activity record.
     * @param array[] $participants Aggregated participants.
     * @param \stdClass[]|null $candidates Users (defaults to get_candidates()).
     * @return array[] Participants with userid and matchedby.
     */
    public static function match(\stdClass $googlemeet, array $participants, ?array $candidates = null): array {
        global $DB;

        $candidates = $candidates ?? self::get_candidates($googlemeet);
        $byemail = [];
        $names = [];
        foreach ($candidates as $user) {
            if (!empty($user->email)) {
                $byemail[\core_text::strtolower(trim($user->email))] = (int)$user->id;
            }
            $first = self::normalise_name((string)($user->firstname ?? ''));
            $last = self::normalise_name((string)($user->lastname ?? ''));
            $names[(int)$user->id] = array_unique(array_filter([
                trim($first . ' ' . $last),
                trim($last . ' ' . $first),
                self::normalise_name(fullname($user)),
            ]));
        }

        // Google ids already linked (automatically or by a teacher) in any activity of the course.
        $googleids = array_values(array_unique(array_filter(array_column($participants, 'googleuserid'))));
        $byid = [];
        if ($googleids && $candidates) {
            [$gsql, $gparams] = $DB->get_in_or_equal($googleids, SQL_PARAMS_NAMED, 'g');
            [$usql, $uparams] = $DB->get_in_or_equal(array_keys($candidates), SQL_PARAMS_NAMED, 'u');
            $rows = $DB->get_records_sql("SELECT a.id, a.googleuserid, a.userid
                                             FROM {googlemeet_attendance} a
                                            WHERE a.googleuserid $gsql AND a.userid $usql
                                         ORDER BY a.timemodified DESC, a.id DESC", $gparams + $uparams);
            foreach ($rows as $row) {
                if (!isset($byid[$row->googleuserid])) {
                    $byid[$row->googleuserid] = (int)$row->userid;
                }
            }
        }

        foreach ($participants as &$p) {
            $p['userid'] = 0;
            $p['matchedby'] = '';
            $email = \core_text::strtolower(trim((string)($p['email'] ?? '')));
            if ($email !== '' && isset($byemail[$email])) {
                $p['userid'] = $byemail[$email];
                $p['matchedby'] = 'email';
                continue;
            }
            $gid = (string)($p['googleuserid'] ?? '');
            if ($gid !== '' && isset($byid[$gid])) {
                $p['userid'] = $byid[$gid];
                $p['matchedby'] = 'googleuser';
                continue;
            }
            if ($p['type'] === 'phone') {
                continue;
            }
            $userid = self::match_name((string)$p['displayname'], $names);
            if ($userid) {
                $p['userid'] = $userid;
                $p['matchedby'] = 'name';
            }
        }
        unset($p);
        return $participants;
    }

    /**
     * Unique candidate whose name equals the display name, or starts with it (2+ words, e.g. one
     * surname of two). 0 when none or ambiguous.
     *
     * @param string $displayname Meet display name.
     * @param array $names userid => normalised name variants.
     * @return int
     */
    public static function match_name(string $displayname, array $names): int {
        $needle = self::normalise_name($displayname);
        if ($needle === '') {
            return 0;
        }
        $exact = [];
        $prefix = [];
        $words = count(explode(' ', $needle));
        foreach ($names as $userid => $variants) {
            foreach ($variants as $variant) {
                if ($variant === $needle) {
                    $exact[$userid] = true;
                } else if ($words >= 2 && str_starts_with($variant . ' ', $needle . ' ')) {
                    $prefix[$userid] = true;
                }
            }
        }
        if (count($exact) === 1) {
            return (int)array_key_first($exact);
        }
        if (!$exact && count($prefix) === 1) {
            return (int)array_key_first($prefix);
        }
        return 0;
    }

    /**
     * Replace the stored attendance of a session, merging participants matched to the same user.
     *
     * Manual links made by the teacher on a previous fetch are kept for the same Google id/name.
     *
     * @param \stdClass $googlemeet Activity record.
     * @param int $eventid Session id.
     * @param array[] $participants Matched participants.
     * @param int $now Current time.
     * @return void
     */
    public static function store(\stdClass $googlemeet, int $eventid, array $participants, int $now): void {
        global $DB;

        $manual = [];
        foreach ($DB->get_records('googlemeet_attendance', ['eventid' => $eventid, 'matchedby' => 'manual']) as $old) {
            $manual[self::person_key((array)$old)] = (int)$old->userid;
        }

        $rows = [];
        foreach ($participants as $p) {
            $key = self::person_key($p);
            if (empty($p['userid']) && isset($manual[$key])) {
                $p['userid'] = $manual[$key];
                $p['matchedby'] = 'manual';
            }
            $rowkey = !empty($p['userid']) ? 'u:' . $p['userid'] : 'p:' . $key;
            if (isset($rows[$rowkey])) {
                // Same student from two Google identities/devices: combine.
                $row = $rows[$rowkey];
                $row->timejoined = min($row->timejoined, (int)$p['timejoined']);
                $row->timeleft = max($row->timeleft, (int)$p['timeleft']);
                $row->durationseconds = min($row->timeleft - $row->timejoined,
                    $row->durationseconds + (int)$p['durationseconds']);
                $row->sessions += (int)$p['sessions'];
                continue;
            }
            $rows[$rowkey] = (object)[
                'googlemeetid' => (int)$googlemeet->id,
                'eventid' => $eventid,
                'userid' => (int)($p['userid'] ?? 0),
                'email' => ($p['email'] ?? '') !== '' ? \core_text::substr((string)$p['email'], 0, 255) : null,
                'displayname' => \core_text::substr((string)$p['displayname'], 0, 255),
                'googleuserid' => ($p['googleuserid'] ?? '') !== '' ? \core_text::substr((string)$p['googleuserid'], 0, 100) : null,
                'participanttype' => (string)$p['type'],
                'matchedby' => (string)($p['matchedby'] ?? ''),
                'timejoined' => (int)$p['timejoined'],
                'timeleft' => (int)$p['timeleft'],
                'durationseconds' => (int)$p['durationseconds'],
                'sessions' => (int)$p['sessions'],
                'timecreated' => $now,
                'timemodified' => $now,
            ];
        }

        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records('googlemeet_attendance', ['eventid' => $eventid]);
        if ($rows) {
            $DB->insert_records('googlemeet_attendance', array_values($rows));
        }
        $transaction->allow_commit();
    }

    /**
     * Identity key of a participant across fetches.
     *
     * @param array $p Participant or attendance row.
     * @return string
     */
    protected static function person_key(array $p): string {
        $gid = (string)($p['googleuserid'] ?? '');
        if ($gid !== '') {
            return 'g:' . $gid;
        }
        return ($p['participanttype'] ?? $p['type'] ?? '') . ':' . self::normalise_name((string)($p['displayname'] ?? ''));
    }

    /**
     * Link an unmatched participant to a student (teacher action). The Google id is remembered,
     * so the same person is matched automatically in later sessions.
     *
     * @param \stdClass $googlemeet Activity record.
     * @param int $attendanceid Attendance row id.
     * @param int $userid User to link (0 to unlink).
     * @return void
     */
    public static function link_manually(\stdClass $googlemeet, int $attendanceid, int $userid): void {
        global $DB;

        $row = $DB->get_record('googlemeet_attendance', ['id' => $attendanceid, 'googlemeetid' => $googlemeet->id],
            '*', MUST_EXIST);
        if ($userid && !isset(self::get_candidates($googlemeet)[$userid])) {
            throw new \moodle_exception('invaliduser');
        }
        if ($userid && $DB->record_exists_select('googlemeet_attendance', 'eventid = ? AND userid = ? AND id <> ?',
                [$row->eventid, $userid, $row->id])) {
            throw new \moodle_exception('attendance_link_duplicate', 'googlemeet');
        }
        $row->userid = $userid;
        $row->matchedby = $userid ? 'manual' : '';
        $row->timemodified = time();
        $DB->update_record('googlemeet_attendance', $row);
    }

    /**
     * Sessions of an activity with their fetch state, newest first, for the teacher view.
     *
     * @param int $googlemeetid Activity id.
     * @param int $now Current time.
     * @return \stdClass[]
     */
    public static function get_sessions(int $googlemeetid, int $now): array {
        global $DB;

        return $DB->get_records_sql("SELECT ge.id, ge.eventdate, ge.duration, s.status, s.attempts, s.participants,
                                            s.matched, s.message, s.timemodified AS fetched
                                       FROM {googlemeet_events} ge
                                  LEFT JOIN {googlemeet_attendance_sync} s ON s.eventid = ge.id
                                      WHERE ge.googlemeetid = :googlemeetid AND ge.eventdate <= :now
                                   ORDER BY ge.eventdate DESC", ['googlemeetid' => $googlemeetid, 'now' => $now]);
    }

    /**
     * Attendance rows of a session, matched students first.
     *
     * @param int $googlemeetid Activity id.
     * @param int $eventid Session id.
     * @return \stdClass[]
     */
    public static function get_attendance(int $googlemeetid, int $eventid): array {
        global $DB;

        $fields = implode(', ', array_map(static fn($f) => 'u.' . $f, \core_user\fields::get_name_fields()));
        return $DB->get_records_sql("SELECT a.*, u.email AS useremail, $fields
                                       FROM {googlemeet_attendance} a
                                  LEFT JOIN {user} u ON u.id = a.userid
                                      WHERE a.googlemeetid = :googlemeetid AND a.eventid = :eventid
                                   ORDER BY CASE WHEN a.userid = 0 THEN 1 ELSE 0 END, u.lastname, u.firstname, a.displayname",
            ['googlemeetid' => $googlemeetid, 'eventid' => $eventid]);
    }

    /**
     * Delete all attendance data of an activity (instance deletion).
     *
     * @param int $googlemeetid Activity id.
     * @return void
     */
    public static function delete_for_activity(int $googlemeetid): void {
        global $DB;
        $DB->delete_records('googlemeet_attendance', ['googlemeetid' => $googlemeetid]);
        $DB->delete_records('googlemeet_attendance_sync', ['googlemeetid' => $googlemeetid]);
    }
}
