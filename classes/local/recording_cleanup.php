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

use mod_googlemeet\question_service;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/questionlib.php');

/**
 * Central cascade delete of recordings and everything that hangs off them (DAT-01), the trash
 * retention purge (OPS-03), orphan detection for cli/cleanup_orphans.php and course reset.
 *
 * Every place that permanently removes recordings must go through purge_recordings() so no
 * dependent row (AI analysis, progress, practice attempts, material files, per-recording user
 * preferences, tagged practice questions) is left behind.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recording_cleanup {

    /** @var int Default trash retention in days when the setting was never saved. */
    public const DEFAULT_RETENTION_DAYS = 30;

    /** @var int Maximum recordings purged by one run of the retention task. */
    public const PURGE_BATCH = 500;

    /** @var int Chunk size for IN () lists. */
    protected const CHUNK = 500;

    /** @var string[] Per-recording user preference prefixes (followed by the recording id). */
    public const PREFERENCE_PREFIXES = ['mod_googlemeet_lastjump_', 'mod_googlemeet_kp_'];

    /**
     * Empty counters returned by the delete functions.
     *
     * @return array
     */
    public static function empty_stats(): array {
        return [
            'recordings' => 0,
            'analysis' => 0,
            'progress' => 0,
            'attempts' => 0,
            'files' => 0,
            'preferences' => 0,
            'questionsdeleted' => 0,
            'questionshidden' => 0,
        ];
    }

    /**
     * Permanently delete recordings and all their dependents.
     *
     * The caller is responsible for checking that the recordings belong to the activity of
     * $context. With a null context (the activity is already gone) material files and questions
     * are looked up site-wide by recording id.
     *
     * @param int[] $recordingids Recording ids.
     * @param \context_module|null $context Module context of the activity that owns them.
     * @param bool $includequestions Also delete (or hide when in use) the tagged practice questions.
     * @return array Counters, see empty_stats().
     */
    public static function purge_recordings(array $recordingids, ?\context_module $context,
            bool $includequestions = true): array {
        global $DB;

        $recordingids = self::clean_ids($recordingids);
        $stats = self::delete_dependents($recordingids, $context, $includequestions);
        foreach (array_chunk($recordingids, self::CHUNK) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk);
            $stats['recordings'] += $DB->count_records_select('googlemeet_recordings', "id $insql", $params);
            $DB->delete_records_select('googlemeet_recordings', "id $insql", $params);
        }
        return $stats;
    }

    /**
     * Delete everything that depends on the given recordings, but not the recording rows.
     *
     * @param int[] $recordingids Recording ids.
     * @param \context_module|null $context Module context (null when the activity is gone).
     * @param bool $includequestions Also delete (or hide when in use) the tagged practice questions.
     * @return array Counters, see empty_stats().
     */
    public static function delete_dependents(array $recordingids, ?\context_module $context,
            bool $includequestions = true): array {
        global $DB;

        $stats = self::empty_stats();
        $recordingids = self::clean_ids($recordingids);
        if (!$recordingids) {
            return $stats;
        }

        foreach (array_chunk($recordingids, self::CHUNK) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk);
            foreach ([
                'analysis' => 'googlemeet_ai_analysis',
                'progress' => 'googlemeet_recording_progress',
                'attempts' => 'googlemeet_practice_attempts',
            ] as $key => $table) {
                $stats[$key] += $DB->count_records_select($table, "recordingid $insql", $params);
                $DB->delete_records_select($table, "recordingid $insql", $params);
            }
        }

        $stats['files'] = self::delete_material_files($recordingids, $context);
        $stats['preferences'] = self::delete_preferences($recordingids);

        if ($includequestions) {
            [$deleted, $hidden] = self::delete_questions($recordingids, $context);
            $stats['questionsdeleted'] = $deleted;
            $stats['questionshidden'] = $hidden;
        }

        return $stats;
    }

    /**
     * Delete the "recordingmaterial" files of the given recordings.
     *
     * @param int[] $recordingids Recording ids.
     * @param \context_module|null $context Module context, null to look them up site-wide.
     * @return int Number of real (non-directory) files deleted.
     */
    protected static function delete_material_files(array $recordingids, ?\context_module $context): int {
        global $DB;

        $fs = get_file_storage();
        $count = 0;
        foreach (array_chunk($recordingids, self::CHUNK) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            $params += ['component' => 'mod_googlemeet', 'filearea' => 'recordingmaterial'];
            $where = "component = :component AND filearea = :filearea AND itemid $insql";
            if ($context) {
                $where .= ' AND contextid = :contextid';
                $params['contextid'] = $context->id;
            }
            $files = $DB->get_records_select('files', $where, $params, '', 'id, filename');
            foreach ($files as $filerecord) {
                $file = $fs->get_file_by_id($filerecord->id);
                if (!$file) {
                    continue;
                }
                if ($filerecord->filename !== '.') {
                    $count++;
                }
                $file->delete();
            }
        }
        return $count;
    }

    /**
     * Delete the per-recording user preferences (last jump, key point checklist).
     *
     * @param int[] $recordingids Recording ids.
     * @return int Number of preference rows deleted.
     */
    protected static function delete_preferences(array $recordingids): int {
        global $DB;

        $names = [];
        foreach ($recordingids as $recordingid) {
            foreach (self::PREFERENCE_PREFIXES as $prefix) {
                $names[] = $prefix . $recordingid;
            }
        }
        return self::delete_preferences_by_name($names);
    }

    /**
     * Delete user preference rows by exact name and invalidate the users' cached preferences.
     *
     * @param string[] $names Preference names.
     * @return int Rows deleted.
     */
    protected static function delete_preferences_by_name(array $names): int {
        global $DB;

        $count = 0;
        $userids = [];
        foreach (array_chunk(array_values(array_unique($names)), self::CHUNK) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk);
            $rows = $DB->get_records_select('user_preferences', "name $insql", $params, '', 'id, userid');
            if (!$rows) {
                continue;
            }
            foreach ($rows as $row) {
                $userids[(int)$row->userid] = true;
            }
            $count += count($rows);
            $DB->delete_records_select('user_preferences', "name $insql", $params);
        }
        foreach (array_keys($userids) as $userid) {
            mark_user_preferences_changed($userid);
        }
        return $count;
    }

    /**
     * Delete the practice questions tagged for the given recordings.
     *
     * Questions still used elsewhere (e.g. added to a quiz) are hidden by core instead of deleted;
     * the per-recording tag is then removed from them so they no longer count as orphans.
     *
     * @param int[] $recordingids Recording ids.
     * @param \context|null $context Restrict to questions in categories of this context (null: any).
     * @return int[] [deleted, hidden]
     */
    public static function delete_questions(array $recordingids, ?\context $context): array {
        global $DB;

        $deleted = 0;
        $hidden = 0;
        $tagnames = [];
        foreach (self::clean_ids($recordingids) as $recordingid) {
            $tagnames[] = \core_text::strtolower(question_service::tag_for_recording($recordingid));
        }

        foreach (array_chunk($tagnames, self::CHUNK) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'tag');
            $params += ['component' => 'core_question', 'itemtype' => 'question'];
            $contextsql = '';
            if ($context) {
                $contextsql = 'AND qc.contextid = :contextid';
                $params['contextid'] = $context->id;
            }
            $sql = "SELECT ti.id, ti.itemid AS questionid, t.name AS tagname
                      FROM {tag_instance} ti
                      JOIN {tag} t ON t.id = ti.tagid
                      JOIN {question_versions} qv ON qv.questionid = ti.itemid
                      JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                      JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                     WHERE ti.component = :component
                       AND ti.itemtype = :itemtype
                       AND t.name $insql
                       $contextsql";
            foreach ($DB->get_records_sql($sql, $params) as $row) {
                [$d, $h] = self::delete_question((int)$row->questionid, $row->tagname);
                $deleted += $d;
                $hidden += $h;
            }
        }

        return [$deleted, $hidden];
    }

    /**
     * Delete one question through the question bank API (hidden by core when in use).
     *
     * @param int $questionid Question id.
     * @param string $tagname Per-recording tag to drop when the question survives.
     * @return int[] [deleted, hidden]
     */
    protected static function delete_question(int $questionid, string $tagname): array {
        global $DB;

        if (!$DB->record_exists('question', ['id' => $questionid])) {
            return [0, 0];
        }
        question_delete_question($questionid);
        if (!$DB->record_exists('question', ['id' => $questionid])) {
            return [1, 0];
        }
        // In use: core marked it hidden. Unbind it from the (gone) recording.
        \core_tag_tag::remove_item_tag('core_question', 'question', $questionid, $tagname);
        return [0, 1];
    }

    /**
     * Delete all recordings of one activity instance and their dependents.
     *
     * @param int $googlemeetid Activity instance id.
     * @param \context_module|null $context Module context (null when unknown).
     * @param bool $includequestions Also delete the tagged questions. Instance deletion passes
     *     false: core deletes the whole module question bank itself right after.
     * @return array Counters, see empty_stats().
     */
    public static function purge_instance_recordings(int $googlemeetid, ?\context_module $context,
            bool $includequestions = true): array {
        global $DB;

        $ids = $DB->get_fieldset_select('googlemeet_recordings', 'id', 'googlemeetid = ?', [$googlemeetid]);
        return self::purge_recordings($ids, $context, $includequestions);
    }

    /**
     * Delete the user data of the given activity instances (course reset).
     *
     * Recordings, AI content and questions are kept; viewing progress, practice attempts,
     * recording subscriptions, notification bookkeeping and per-recording preferences go.
     *
     * @param int[] $googlemeetids Activity instance ids.
     * @return array Counters keyed by kind.
     */
    public static function delete_user_data(array $googlemeetids): array {
        global $DB;

        $stats = ['progress' => 0, 'attempts' => 0, 'subscriptions' => 0, 'preferences' => 0, 'notifications' => 0];
        $googlemeetids = self::clean_ids($googlemeetids);
        if (!$googlemeetids) {
            return $stats;
        }

        [$insql, $params] = $DB->get_in_or_equal($googlemeetids);
        $recordingids = $DB->get_fieldset_select('googlemeet_recordings', 'id', "googlemeetid $insql", $params);
        foreach (array_chunk(self::clean_ids($recordingids), self::CHUNK) as $chunk) {
            [$rinsql, $rparams] = $DB->get_in_or_equal($chunk);
            $stats['progress'] += $DB->count_records_select('googlemeet_recording_progress', "recordingid $rinsql", $rparams);
            $DB->delete_records_select('googlemeet_recording_progress', "recordingid $rinsql", $rparams);
        }
        $stats['preferences'] = self::delete_preferences(self::clean_ids($recordingids));

        $stats['attempts'] = $DB->count_records_select('googlemeet_practice_attempts', "googlemeetid $insql", $params);
        $DB->delete_records_select('googlemeet_practice_attempts', "googlemeetid $insql", $params);
        $stats['subscriptions'] = $DB->count_records_select('googlemeet_recording_subs', "googlemeetid $insql", $params);
        $DB->delete_records_select('googlemeet_recording_subs', "googlemeetid $insql", $params);

        $eventselect = "eventid IN (SELECT id FROM {googlemeet_events} WHERE googlemeetid $insql)";
        $stats['notifications'] = $DB->count_records_select('googlemeet_notify_done', $eventselect, $params);
        $DB->delete_records_select('googlemeet_notify_done', $eventselect, $params);

        return $stats;
    }

    /**
     * Trash retention in days (0 = never purge automatically).
     *
     * @return int
     */
    public static function get_retention_days(): int {
        $value = get_config('googlemeet', 'trashretentiondays');
        if ($value === false || $value === '' || $value === null) {
            return self::DEFAULT_RETENTION_DAYS;
        }
        return max(0, (int)$value);
    }

    /**
     * When a recording moved to the trash at $timedeleted will be purged automatically.
     *
     * @param int $timedeleted Trash timestamp (0 = unknown, the clock starts at the next task run).
     * @param int|null $retentiondays Retention, defaults to the site setting.
     * @return int Timestamp, or 0 when automatic purge is disabled.
     */
    public static function purge_time(int $timedeleted, ?int $retentiondays = null): int {
        $retentiondays = $retentiondays ?? self::get_retention_days();
        if ($retentiondays <= 0) {
            return 0;
        }
        $base = $timedeleted > 0 ? $timedeleted : time();
        return $base + $retentiondays * DAYSECS;
    }

    /**
     * Teacher-facing notice "Will be deleted on dd/mm" for one trashed recording.
     *
     * @param int $timedeleted Trash timestamp.
     * @param int|null $retentiondays Retention, defaults to the site setting.
     * @param int|null $now Current time (tests).
     * @return string '' when automatic purge is disabled.
     */
    public static function purge_notice(int $timedeleted, ?int $retentiondays = null, ?int $now = null): string {
        $purgetime = self::purge_time($timedeleted, $retentiondays);
        if (!$purgetime) {
            return '';
        }
        if ($purgetime <= ($now ?? time())) {
            return get_string('recordings_trash_purgesoon', 'googlemeet');
        }
        return get_string('recordings_trash_purgeon', 'googlemeet',
            userdate($purgetime, get_string('trash_purge_dateformat', 'googlemeet')));
    }

    /**
     * Purge recordings that have been in the trash longer than the retention period.
     *
     * Trashed rows without a timestamp (legacy data) get "now" as their trash time, so they are
     * purged one full retention period later instead of immediately.
     *
     * @param int|null $now Current time (tests).
     * @param int|null $retentiondays Retention, defaults to the site setting.
     * @return array Counters, see empty_stats().
     */
    public static function purge_expired_trash(?int $now = null, ?int $retentiondays = null): array {
        global $DB;

        $now = $now ?? time();
        $retentiondays = $retentiondays ?? self::get_retention_days();
        $stats = self::empty_stats();
        if ($retentiondays <= 0) {
            return $stats;
        }

        $DB->set_field_select('googlemeet_recordings', 'timedeleted', $now, 'deleted = 1 AND timedeleted = 0');

        $cutoff = $now - $retentiondays * DAYSECS;
        $rows = $DB->get_records_select('googlemeet_recordings', 'deleted = 1 AND timedeleted > 0 AND timedeleted < ?',
            [$cutoff], 'googlemeetid, id', 'id, googlemeetid', 0, self::PURGE_BATCH);
        $bygooglemeet = [];
        foreach ($rows as $row) {
            $bygooglemeet[(int)$row->googlemeetid][] = (int)$row->id;
        }

        foreach ($bygooglemeet as $googlemeetid => $ids) {
            $cm = get_coursemodule_from_instance('googlemeet', $googlemeetid, 0, false, IGNORE_MISSING);
            $context = $cm ? \context_module::instance($cm->id, IGNORE_MISSING) : null;
            $result = self::purge_recordings($ids, $context ?: null);
            foreach ($result as $key => $value) {
                $stats[$key] += $value;
            }
        }
        return $stats;
    }

    /**
     * Find data left behind by recordings or activities that no longer exist.
     *
     * @return array Map kind => list of ids (question ids, file ids, preference names, row ids...).
     */
    public static function find_orphans(): array {
        global $DB;

        $orphans = [];

        // Recordings of activities that no longer exist (purged with all their dependents).
        $orphans['recordings'] = self::ids_sql(
            "SELECT r.id FROM {googlemeet_recordings} r
          LEFT JOIN {googlemeet} g ON g.id = r.googlemeetid
              WHERE g.id IS NULL");

        // Rows whose recording no longer exists.
        foreach ([
            'analysis' => 'googlemeet_ai_analysis',
            'progress' => 'googlemeet_recording_progress',
        ] as $key => $table) {
            $orphans[$key] = self::ids_sql(
                "SELECT x.id FROM {{$table}} x
              LEFT JOIN {googlemeet_recordings} r ON r.id = x.recordingid
                  WHERE r.id IS NULL");
        }
        $orphans['attempts'] = self::ids_sql(
            "SELECT x.id FROM {googlemeet_practice_attempts} x
          LEFT JOIN {googlemeet_recordings} r ON r.id = x.recordingid
          LEFT JOIN {googlemeet} g ON g.id = x.googlemeetid
              WHERE r.id IS NULL OR g.id IS NULL");

        // Rows whose activity no longer exists.
        foreach ([
            'subscriptions' => 'googlemeet_recording_subs',
            'events' => 'googlemeet_events',
            'holidays' => 'googlemeet_holidays',
            'cancelled' => 'googlemeet_cancelled',
        ] as $key => $table) {
            $orphans[$key] = self::ids_sql(
                "SELECT x.id FROM {{$table}} x
              LEFT JOIN {googlemeet} g ON g.id = x.googlemeetid
                  WHERE g.id IS NULL");
        }
        $orphans['notifications'] = self::ids_sql(
            "SELECT x.id FROM {googlemeet_notify_done} x
          LEFT JOIN {googlemeet_events} e ON e.id = x.eventid
              WHERE e.id IS NULL");

        // Material files whose recording no longer exists.
        $orphans['files'] = self::ids_sql(
            "SELECT f.id FROM {files} f
          LEFT JOIN {googlemeet_recordings} r ON r.id = f.itemid
              WHERE f.component = :component AND f.filearea = :filearea AND r.id IS NULL",
            ['component' => 'mod_googlemeet', 'filearea' => 'recordingmaterial']);

        // Per-recording preferences whose recording no longer exists.
        $orphans['preferences'] = [];
        foreach (self::PREFERENCE_PREFIXES as $prefix) {
            $names = $DB->get_fieldset_sql(
                'SELECT DISTINCT name FROM {user_preferences} WHERE ' . $DB->sql_like('name', ':prefix'),
                ['prefix' => $DB->sql_like_escape($prefix) . '%']);
            $ids = [];
            foreach ($names as $name) {
                $suffix = substr($name, strlen($prefix));
                if (ctype_digit($suffix)) {
                    $ids[(int)$suffix][] = $name;
                }
            }
            $existing = self::existing_recording_ids(array_keys($ids));
            foreach ($ids as $id => $idnames) {
                if (!isset($existing[$id])) {
                    $orphans['preferences'] = array_merge($orphans['preferences'], $idnames);
                }
            }
        }

        // Questions tagged for a recording that no longer exists.
        $orphans['questions'] = [];
        $tags = $DB->get_records_sql(
            "SELECT DISTINCT t.id, t.name
               FROM {tag} t
               JOIN {tag_instance} ti ON ti.tagid = t.id
              WHERE ti.component = :component AND ti.itemtype = :itemtype
                AND " . $DB->sql_like('t.name', ':prefix', false),
            ['component' => 'core_question', 'itemtype' => 'question',
                'prefix' => $DB->sql_like_escape(question_service::TAG_PREFIX) . '%']);
        $tagbyrecording = [];
        foreach ($tags as $tag) {
            $suffix = substr($tag->name, strlen(question_service::TAG_PREFIX));
            if (ctype_digit($suffix)) {
                $tagbyrecording[(int)$suffix] = $tag;
            }
        }
        $existing = self::existing_recording_ids(array_keys($tagbyrecording));
        foreach ($tagbyrecording as $recordingid => $tag) {
            if (isset($existing[$recordingid])) {
                continue;
            }
            $questionids = $DB->get_fieldset_select('tag_instance', 'itemid',
                'tagid = ? AND component = ? AND itemtype = ?', [$tag->id, 'core_question', 'question']);
            foreach ($questionids as $questionid) {
                $orphans['questions'][(int)$questionid] = $tag->name;
            }
        }

        return $orphans;
    }

    /**
     * Delete the orphans found by find_orphans().
     *
     * @param array $orphans Result of find_orphans().
     * @return array Map kind => number removed (questions: also 'questionshidden').
     */
    public static function delete_orphans(array $orphans): array {
        global $DB;

        $result = [];
        if (!empty($orphans['recordings'])) {
            $stats = self::purge_recordings($orphans['recordings'], null);
            $result['recordings'] = $stats['recordings'];
        }

        $tables = [
            'analysis' => 'googlemeet_ai_analysis',
            'progress' => 'googlemeet_recording_progress',
            'attempts' => 'googlemeet_practice_attempts',
            'subscriptions' => 'googlemeet_recording_subs',
            'notifications' => 'googlemeet_notify_done',
            'events' => 'googlemeet_events',
            'holidays' => 'googlemeet_holidays',
            'cancelled' => 'googlemeet_cancelled',
        ];
        foreach ($tables as $key => $table) {
            $result[$key] = 0;
            foreach (array_chunk($orphans[$key] ?? [], self::CHUNK) as $chunk) {
                [$insql, $params] = $DB->get_in_or_equal($chunk);
                $result[$key] += $DB->count_records_select($table, "id $insql", $params);
                $DB->delete_records_select($table, "id $insql", $params);
            }
        }

        $fs = get_file_storage();
        $result['files'] = 0;
        foreach ($orphans['files'] ?? [] as $fileid) {
            if ($file = $fs->get_file_by_id($fileid)) {
                if (!$file->is_directory()) {
                    $result['files']++;
                }
                $file->delete();
            }
        }

        $result['preferences'] = self::delete_preferences_by_name($orphans['preferences'] ?? []);

        $result['questions'] = 0;
        $result['questionshidden'] = 0;
        foreach ($orphans['questions'] ?? [] as $questionid => $tagname) {
            [$d, $h] = self::delete_question((int)$questionid, (string)$tagname);
            $result['questions'] += $d;
            $result['questionshidden'] += $h;
        }

        return $result;
    }

    /**
     * Run a query returning ids.
     *
     * @param string $sql SQL selecting one id column.
     * @param array $params Parameters.
     * @return int[]
     */
    protected static function ids_sql(string $sql, array $params = []): array {
        global $DB;
        return array_map('intval', $DB->get_fieldset_sql($sql, $params));
    }

    /**
     * Which of the given recording ids still exist.
     *
     * @param int[] $ids Recording ids.
     * @return array Map id => true.
     */
    protected static function existing_recording_ids(array $ids): array {
        global $DB;

        $existing = [];
        foreach (array_chunk(self::clean_ids($ids), self::CHUNK) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk);
            foreach ($DB->get_fieldset_select('googlemeet_recordings', 'id', "id $insql", $params) as $id) {
                $existing[(int)$id] = true;
            }
        }
        return $existing;
    }

    /**
     * Normalise a list of ids (positive, unique ints).
     *
     * @param array $ids Ids.
     * @return int[]
     */
    protected static function clean_ids(array $ids): array {
        return array_values(array_unique(array_filter(array_map('intval', $ids), static function(int $id): bool {
            return $id > 0;
        })));
    }
}
