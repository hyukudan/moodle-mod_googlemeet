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

use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/lib.php');

/**
 * Data builder for the teacher viewing report (ANA-04): students x recordings.
 *
 * Percentages use exactly the same rule as the student UI (googlemeet_recording_progress_state()):
 * 100 when completed, otherwise watchedseconds / completion threshold capped at 99.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_builder {

    /** @var int Days without activity for the "inactive" filter. */
    public const INACTIVE_DAYS = 14;

    /** @var stdClass Activity record. */
    protected $googlemeet;

    /** @var stdClass Course-module record. */
    protected $cm;

    /** @var \context_module Module context. */
    protected $context;

    /**
     * Constructor.
     *
     * @param stdClass $googlemeet Activity record.
     * @param stdClass $cm Course-module record.
     * @param \context_module $context Module context.
     */
    public function __construct(stdClass $googlemeet, stdClass $cm, \context_module $context) {
        $this->googlemeet = $googlemeet;
        $this->cm = $cm;
        $this->context = $context;
    }

    /**
     * Recordings of the activity (not in the trash), oldest first.
     *
     * @return stdClass[] keyed by id
     */
    public function get_recordings(): array {
        global $DB;

        return $DB->get_records('googlemeet_recordings',
            ['googlemeetid' => $this->googlemeet->id, 'deleted' => 0], 'createdtime ASC, id ASC',
            'id, name, duration, visible, createdtime');
    }

    /**
     * Identity fields (e.g. email) the current user may see, limited to standard user columns.
     *
     * @return string[]
     */
    public function get_identity_fields(): array {
        if (!has_capability('moodle/site:viewuseridentity', $this->context)) {
            return [];
        }
        return \core_user\fields::get_identity_fields($this->context, false);
    }

    /**
     * Students: active enrolments that can view the activity, minus report viewers (teachers).
     *
     * @param int $groupid Group id, 0 for all.
     * @return stdClass[] keyed by user id
     */
    public function get_students(int $groupid = 0): array {
        $names = array_unique(array_merge(['id'], \core_user\fields::get_name_fields(), $this->get_identity_fields()));
        $fields = implode(', ', array_map(static function($f) {
            return 'u.' . $f;
        }, $names));
        $users = get_enrolled_users($this->context, 'mod/googlemeet:view', $groupid, $fields,
            'u.lastname ASC, u.firstname ASC, u.id ASC', 0, 0, true);
        $staff = get_users_by_capability($this->context, 'mod/googlemeet:viewreports', 'u.id');
        return array_diff_key($users, $staff);
    }

    /**
     * Build the matrix.
     *
     * @param int $groupid Group id, 0 for all.
     * @param bool $inactiveonly Only students without activity in INACTIVE_DAYS.
     * @param int|null $now Reference time.
     * @return array ['recordings' => stdClass[], 'rows' => array[], 'recordingstats' => array, 'totalstudents' => int]
     */
    public function build(int $groupid = 0, bool $inactiveonly = false, ?int $now = null): array {
        global $DB;

        $now = $now ?? time();
        $recordings = $this->get_recordings();
        $students = $this->get_students($groupid);

        $progress = [];
        $lastaccess = [];
        if ($recordings && $students) {
            [$rinsql, $rparams] = $DB->get_in_or_equal(array_keys($recordings), SQL_PARAMS_NAMED, 'rec');
            [$uinsql, $uparams] = $DB->get_in_or_equal(array_keys($students), SQL_PARAMS_NAMED, 'usr');
            $rs = $DB->get_recordset_select('googlemeet_recording_progress',
                "recordingid {$rinsql} AND userid {$uinsql}", $rparams + $uparams, '',
                'id, recordingid, userid, watchedseconds, completed, timemodified');
            foreach ($rs as $row) {
                $progress[(int)$row->userid][(int)$row->recordingid] = $row;
                $lastaccess[(int)$row->userid] = max($lastaccess[(int)$row->userid] ?? 0, (int)$row->timemodified);
            }
            $rs->close();

            $sql = "SELECT userid, MAX(timecreated) AS lasttime
                      FROM {googlemeet_practice_attempts}
                     WHERE googlemeetid = :googlemeetid AND userid {$uinsql}
                  GROUP BY userid";
            foreach ($DB->get_records_sql($sql, ['googlemeetid' => $this->googlemeet->id] + $uparams) as $row) {
                $lastaccess[(int)$row->userid] = max($lastaccess[(int)$row->userid] ?? 0, (int)$row->lasttime);
            }
        }

        $thresholds = [];
        foreach ($recordings as $recording) {
            $thresholds[(int)$recording->id] = googlemeet_recording_completion_threshold($recording->duration ?? '');
        }

        $cutoff = $now - self::INACTIVE_DAYS * DAYSECS;
        $rows = [];
        $recordingstats = [];
        foreach ($recordings as $recording) {
            $recordingstats[(int)$recording->id] = ['opened' => 0, 'completed' => 0];
        }

        foreach ($students as $userid => $user) {
            $last = $lastaccess[$userid] ?? 0;
            $inactive = $last < $cutoff;
            if ($inactiveonly && !$inactive) {
                continue;
            }
            $cells = [];
            $completedcount = 0;
            $openedcount = 0;
            foreach ($recordings as $recordingid => $recording) {
                $p = $progress[$userid][$recordingid] ?? null;
                $watched = $p ? max(0, (int)$p->watchedseconds) : 0;
                $completed = $p && !empty($p->completed);
                $opened = $completed || $watched > 0;
                $pct = 0;
                if ($completed) {
                    $pct = 100;
                } else if ($watched > 0 && $thresholds[$recordingid] > 0) {
                    $pct = min(99, max(1, (int)floor(($watched / $thresholds[$recordingid]) * 100)));
                }
                $cells[$recordingid] = [
                    'opened' => $opened,
                    'completed' => $completed,
                    'pct' => $pct,
                    'timemodified' => $p ? (int)$p->timemodified : 0,
                ];
                if ($opened) {
                    $openedcount++;
                    $recordingstats[$recordingid]['opened']++;
                }
                if ($completed) {
                    $completedcount++;
                    $recordingstats[$recordingid]['completed']++;
                }
            }
            $rows[$userid] = [
                'user' => $user,
                'cells' => $cells,
                'completed' => $completedcount,
                'opened' => $openedcount,
                'lastaccess' => $last,
                'inactive' => $inactive,
            ];
        }

        return [
            'recordings' => $recordings,
            'rows' => $rows,
            'recordingstats' => $recordingstats,
            'totalstudents' => count($rows),
        ];
    }

    /**
     * Groups the current user may filter by.
     *
     * The activity does not support group mode, so course groups are used; users without
     * moodle/site:accessallgroups in a SEPARATEGROUPS course only see their own groups.
     *
     * @param stdClass $course Course record.
     * @param int $userid Current user id.
     * @return array groupid => name ([] when the course has no groups)
     */
    public function get_allowed_groups(stdClass $course, int $userid): array {
        $all = has_capability('moodle/site:accessallgroups', $this->context, $userid)
            || (int)$course->groupmode !== SEPARATEGROUPS;
        $groups = groups_get_all_groups($course->id, $all ? 0 : $userid);
        $out = [];
        foreach ($groups as $group) {
            $out[(int)$group->id] = format_string($group->name, true, ['context' => $this->context]);
        }
        return $out;
    }

    /**
     * Rows for \core\dataformat::download_data().
     *
     * @param array $data Result of build().
     * @param string[] $identityfields Extra identity fields (e.g. email) to export.
     * @return array ['columns' => array, 'rows' => array[]]
     */
    public static function export_table(array $data, array $identityfields = []): array {
        $columns = ['fullname' => get_string('fullnameuser')];
        foreach ($identityfields as $field) {
            $columns[$field] = \core_user\fields::get_display_name($field);
        }
        $columns['completed'] = get_string('report_col_completed', 'googlemeet');
        $columns['lastaccess'] = get_string('report_col_lastaccess', 'googlemeet');
        foreach ($data['recordings'] as $recording) {
            $columns['rec' . $recording->id] = googlemeet_display_name((string)$recording->name)
                . ' (' . userdate($recording->createdtime, get_string('strftimedatefullshort', 'langconfig')) . ')';
        }

        $total = count($data['recordings']);
        $rows = [];
        foreach ($data['rows'] as $row) {
            $out = ['fullname' => fullname($row['user'])];
            foreach ($identityfields as $field) {
                $out[$field] = (string)($row['user']->$field ?? '');
            }
            $out['completed'] = $row['completed'] . '/' . $total;
            $out['lastaccess'] = $row['lastaccess'] ? userdate($row['lastaccess'], get_string('strftimedatetimeshort',
                'langconfig')) : get_string('never');
            foreach ($row['cells'] as $recordingid => $cell) {
                $out['rec' . $recordingid] = $cell['pct'];
            }
            $rows[] = $out;
        }
        return ['columns' => $columns, 'rows' => $rows];
    }
}
