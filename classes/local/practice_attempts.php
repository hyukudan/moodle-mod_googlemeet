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

use core_question\local\bank\question_version_status;
use mod_googlemeet\question_service;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/lib.php');

/**
 * Persisted practice attempts (ANA-05) and the activity-wide practice modes built on them.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class practice_attempts {

    /** @var string Table name. */
    public const TABLE = 'googlemeet_practice_attempts';

    /** @var string Practice mode: questions whose latest attempt was wrong. */
    public const MODE_FAILED = 'failed';

    /** @var string Practice mode: questions of the recordings that share one AI topic. */
    public const MODE_TOPIC = 'topic';

    /** @var int Maximum questions served in one activity-wide session. */
    public const SESSION_LIMIT = 30;

    /**
     * Store one practice answer.
     *
     * @param int $googlemeetid Activity instance id.
     * @param int $recordingid Recording id.
     * @param int $questionid Question id.
     * @param int $userid User id.
     * @param bool $correct Whether the answer was correct.
     * @param int|null $time Timestamp (defaults to now).
     * @return int New attempt id.
     */
    public static function record(int $googlemeetid, int $recordingid, int $questionid, int $userid, bool $correct,
            ?int $time = null): int {
        global $DB;

        return (int)$DB->insert_record(self::TABLE, (object)[
            'googlemeetid' => $googlemeetid,
            'recordingid' => $recordingid,
            'questionid' => $questionid,
            'userid' => $userid,
            'correct' => $correct ? 1 : 0,
            'timecreated' => $time ?? time(),
        ]);
    }

    /**
     * Questions whose LATEST attempt by the user was wrong, newest failure first.
     *
     * @param int $googlemeetid Activity instance id.
     * @param int $userid User id.
     * @return array questionid => recordingid
     */
    public static function get_failed_question_refs(int $googlemeetid, int $userid): array {
        global $DB;

        $sql = "SELECT a.id, a.questionid, a.recordingid, a.correct
                  FROM {" . self::TABLE . "} a
                 WHERE a.googlemeetid = :googlemeetid
                   AND a.userid = :userid
                   AND a.id = (SELECT MAX(a2.id)
                                 FROM {" . self::TABLE . "} a2
                                WHERE a2.googlemeetid = a.googlemeetid
                                  AND a2.userid = a.userid
                                  AND a2.questionid = a.questionid)
              ORDER BY a.id DESC";
        $rows = $DB->get_records_sql($sql, ['googlemeetid' => $googlemeetid, 'userid' => $userid]);

        $refs = [];
        foreach ($rows as $row) {
            if (empty($row->correct)) {
                $refs[(int)$row->questionid] = (int)$row->recordingid;
            }
        }
        return $refs;
    }

    /**
     * Recordings the current user may practise: not trashed, visible unless the user edits recordings.
     *
     * @param stdClass $googlemeet Activity record.
     * @param \context_module $context Module context.
     * @return stdClass[] Recordings (googlemeet_list_recordings() shape, with aitopics) keyed by id.
     */
    public static function get_practice_recordings(stdClass $googlemeet, \context_module $context): array {
        $params = ['googlemeetid' => $googlemeet->id];
        if (!has_capability('mod/googlemeet:editrecording', $context)) {
            $params['visible'] = 1;
        }
        $recordings = [];
        foreach (googlemeet_list_recordings($params, true, 'ASC') as $recording) {
            $recordings[(int)$recording->id] = $recording;
        }
        return $recordings;
    }

    /**
     * Map every published (ready) question of the activity to its recording, with one query.
     *
     * @param stdClass $googlemeet Activity record.
     * @param stdClass $cm Course-module record.
     * @param \context_module $context Module context.
     * @return array questionid => recordingid
     */
    public static function get_ready_question_map(stdClass $googlemeet, stdClass $cm, \context_module $context): array {
        global $DB;

        $category = (new question_service())->get_category($googlemeet, $cm, $context, false);
        if (!$category) {
            return [];
        }
        $sql = "SELECT ti.id, ti.itemid, t.name
                  FROM {question} q
                  JOIN {question_versions} qv ON qv.questionid = q.id
                  JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                  JOIN {tag_instance} ti ON ti.itemid = q.id
                  JOIN {tag} t ON t.id = ti.tagid
                 WHERE qbe.questioncategoryid = :categoryid
                   AND qv.status = :readystatus
                   AND ti.component = :component
                   AND ti.itemtype = :itemtype
                   AND " . $DB->sql_like('t.name', ':prefix', false, false);
        $rows = $DB->get_records_sql($sql, [
            'categoryid' => $category->id,
            'readystatus' => question_version_status::QUESTION_STATUS_READY,
            'component' => 'core_question',
            'itemtype' => 'question',
            'prefix' => $DB->sql_like_escape(question_service::TAG_PREFIX) . '%',
        ]);
        $map = [];
        $pattern = '/^' . preg_quote(question_service::TAG_PREFIX, '/') . '(\d+)$/';
        foreach ($rows as $row) {
            if (preg_match($pattern, \core_text::strtolower($row->name), $m)) {
                $map[(int)$row->itemid] = (int)$m[1];
            }
        }
        return $map;
    }

    /**
     * Count published (ready) questions per recording.
     *
     * @param stdClass $googlemeet Activity record.
     * @param stdClass $cm Course-module record.
     * @param \context_module $context Module context.
     * @return array recordingid => number of ready questions
     */
    public static function count_ready_questions_by_recording(stdClass $googlemeet, stdClass $cm,
            \context_module $context): array {
        return array_count_values(self::get_ready_question_map($googlemeet, $cm, $context));
    }

    /**
     * Number of questions the user would get in "review my failed questions" mode (before the session limit).
     *
     * @param stdClass $googlemeet Activity record.
     * @param stdClass $cm Course-module record.
     * @param \context_module $context Module context.
     * @param int $userid User id.
     * @return int
     */
    public static function count_failed_questions(stdClass $googlemeet, stdClass $cm, \context_module $context,
            int $userid): int {
        $refs = self::get_failed_question_refs((int)$googlemeet->id, $userid);
        if (!$refs) {
            return 0;
        }
        $ready = self::get_ready_question_map($googlemeet, $cm, $context);
        $recordings = self::get_practice_recordings($googlemeet, $context);
        $count = 0;
        foreach ($refs as $questionid => $recordingid) {
            if (($ready[$questionid] ?? 0) === $recordingid && isset($recordings[$recordingid])) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Topics (from the AI analysis) that have at least one recording with published questions.
     *
     * @param stdClass $googlemeet Activity record.
     * @param stdClass $cm Course-module record.
     * @param \context_module $context Module context.
     * @return array[] List of ['topic' => string, 'recordings' => int, 'questions' => int], naturally sorted.
     */
    public static function get_practice_topics(stdClass $googlemeet, stdClass $cm, \context_module $context): array {
        $counts = self::count_ready_questions_by_recording($googlemeet, $cm, $context);
        if (!$counts) {
            return [];
        }
        $topics = [];
        foreach (self::get_practice_recordings($googlemeet, $context) as $recording) {
            $n = $counts[(int)$recording->id] ?? 0;
            if ($n <= 0) {
                continue;
            }
            $seen = [];
            foreach (($recording->aitopics ?? []) as $topic) {
                $topic = trim((string)$topic);
                $key = googlemeet_fold($topic);
                if ($topic === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                if (!isset($topics[$key])) {
                    $topics[$key] = ['topic' => $topic, 'recordings' => 0, 'questions' => 0];
                }
                $topics[$key]['recordings']++;
                $topics[$key]['questions'] += $n;
            }
        }
        $topics = array_values($topics);
        usort($topics, static function($a, $b) {
            return strnatcasecmp($a['topic'], $b['topic']);
        });
        return $topics;
    }

    /**
     * Build the questions of an activity-wide practice session (no correctness data).
     *
     * Each question carries its recordingid, so the client can check answers with the existing
     * per-recording WS (mod_googlemeet_check_practice_answer), which also records the attempt.
     *
     * @param stdClass $googlemeet Activity record.
     * @param stdClass $cm Course-module record.
     * @param \context_module $context Module context.
     * @param int $userid User id.
     * @param string $mode self::MODE_FAILED or self::MODE_TOPIC.
     * @param string $topic Topic for MODE_TOPIC.
     * @param int $limit Maximum questions (0 = no limit).
     * @return array[] ['questionid', 'recordingid', 'recordingname', 'stem', 'options' => [...]]
     */
    public static function get_session_questions(stdClass $googlemeet, stdClass $cm, \context_module $context,
            int $userid, string $mode, string $topic = '', int $limit = self::SESSION_LIMIT): array {
        $recordings = self::get_practice_recordings($googlemeet, $context);

        $wanted = null; // Null = every ready question of the selected recordings.
        $order = [];
        if ($mode === self::MODE_FAILED) {
            $refs = self::get_failed_question_refs((int)$googlemeet->id, $userid);
            $wanted = [];
            foreach ($refs as $questionid => $recordingid) {
                if (isset($recordings[$recordingid])) {
                    $wanted[$recordingid][$questionid] = true;
                    $order[] = $questionid;
                }
            }
            $recordingids = array_keys($wanted);
        } else if ($mode === self::MODE_TOPIC) {
            if (trim($topic) === '') {
                return [];
            }
            $recordingids = array_map(static function($r) {
                return (int)$r->id;
            }, googlemeet_filter_recordings_by_topic(array_values($recordings), $topic));
        } else {
            throw new \moodle_exception('invalidparameter', 'debug');
        }

        $service = new question_service();
        $byid = [];
        foreach ($recordingids as $recordingid) {
            foreach ($service->get_ready_practice_questions($googlemeet, $cm, $context, $recordingid) as $question) {
                $qid = (int)$question['questionid'];
                if ($wanted !== null && empty($wanted[$recordingid][$qid])) {
                    continue;
                }
                $question['recordingid'] = $recordingid;
                $question['recordingname'] = format_string(googlemeet_display_name((string)$recordings[$recordingid]->name),
                    true, ['context' => $context]);
                $byid[$qid] = $question;
            }
        }

        if ($order) {
            // Failed mode: keep "most recently failed first".
            $sorted = [];
            foreach ($order as $qid) {
                if (isset($byid[$qid])) {
                    $sorted[] = $byid[$qid];
                }
            }
            $questions = $sorted;
        } else {
            $questions = array_values($byid);
        }

        if ($limit > 0) {
            $questions = array_slice($questions, 0, $limit);
        }
        return $questions;
    }

    /**
     * Per-question answer statistics for the teacher.
     *
     * @param int $googlemeetid Activity instance id.
     * @param int|null $recordingid Restrict to one recording.
     * @return array questionid => ['questionid', 'recordingid', 'attempts', 'wrong', 'users', 'correctpct']
     */
    public static function get_question_stats(int $googlemeetid, ?int $recordingid = null): array {
        global $DB;

        $params = ['googlemeetid' => $googlemeetid];
        $where = 'googlemeetid = :googlemeetid';
        if ($recordingid) {
            $where .= ' AND recordingid = :recordingid';
            $params['recordingid'] = $recordingid;
        }
        $sql = "SELECT questionid, recordingid,
                       COUNT(1) AS attempts,
                       SUM(CASE WHEN correct = 0 THEN 1 ELSE 0 END) AS wrong,
                       COUNT(DISTINCT userid) AS users
                  FROM {" . self::TABLE . "}
                 WHERE {$where}
              GROUP BY questionid, recordingid";
        $stats = [];
        foreach ($DB->get_recordset_sql($sql, $params) as $row) {
            $attempts = (int)$row->attempts;
            $wrong = (int)$row->wrong;
            $qid = (int)$row->questionid;
            // A question is bound to one recording; if it ever moved, merge the rows.
            if (isset($stats[$qid])) {
                $attempts += $stats[$qid]['attempts'];
                $wrong += $stats[$qid]['wrong'];
            }
            $stats[$qid] = [
                'questionid' => $qid,
                'recordingid' => (int)$row->recordingid,
                'attempts' => $attempts,
                'wrong' => $wrong,
                'users' => max((int)$row->users, $stats[$qid]['users'] ?? 0),
                'correctpct' => $attempts ? (int)round(($attempts - $wrong) * 100 / $attempts) : 0,
            ];
        }
        return $stats;
    }

    /**
     * Most failed questions per recording, worst first.
     *
     * @param int $googlemeetid Activity instance id.
     * @param int $limit Questions per recording.
     * @return array recordingid => list of stats rows (see get_question_stats()) with 'name' added.
     */
    public static function get_most_failed_by_recording(int $googlemeetid, int $limit = 5): array {
        global $DB;

        $byrecording = [];
        foreach (self::get_question_stats($googlemeetid) as $row) {
            if ($row['wrong'] > 0) {
                $byrecording[$row['recordingid']][] = $row;
            }
        }
        $questionids = [];
        foreach ($byrecording as $recordingid => $rows) {
            usort($rows, static function($a, $b) {
                return [$b['wrong'] / $b['attempts'], $b['wrong'], $a['questionid']]
                    <=> [$a['wrong'] / $a['attempts'], $a['wrong'], $b['questionid']];
            });
            $byrecording[$recordingid] = array_slice($rows, 0, $limit);
            foreach ($byrecording[$recordingid] as $row) {
                $questionids[] = $row['questionid'];
            }
        }
        $texts = $questionids ? $DB->get_records_list('question', 'id', $questionids, '', 'id, name, questiontext') : [];
        foreach ($byrecording as $recordingid => $rows) {
            foreach ($rows as $i => $row) {
                $q = $texts[$row['questionid']] ?? null;
                $text = $q ? trim(html_to_text((string)$q->questiontext, 0, false)) : '';
                if ($text === '' && $q) {
                    $text = (string)$q->name;
                }
                $byrecording[$recordingid][$i]['name'] = $text !== '' ? shorten_text($text, 140)
                    : get_string('report_question_deleted', 'googlemeet');
            }
        }
        return $byrecording;
    }

    /**
     * Template context for the practice entry point in the activity hero (students only).
     *
     * @param stdClass $googlemeet Activity record.
     * @param stdClass $cm Course-module record.
     * @param \context_module $context Module context.
     * @param int $userid User id.
     * @return array|null Null when there is nothing to practise or the user manages questions.
     */
    public static function hero_cta_context(stdClass $googlemeet, stdClass $cm, \context_module $context,
            int $userid): ?array {
        if (has_capability('mod/googlemeet:managequestions', $context)) {
            return null;
        }
        if (!self::get_ready_question_map($googlemeet, $cm, $context)) {
            return null;
        }
        $failed = self::count_failed_questions($googlemeet, $cm, $context, $userid);
        return [
            'practiceurl' => (new \moodle_url('/mod/googlemeet/practice.php', ['id' => $cm->id]))->out(false),
            'failedurl' => (new \moodle_url('/mod/googlemeet/practice.php',
                ['id' => $cm->id, 'mode' => self::MODE_FAILED]))->out(false),
            'hasfailed' => $failed > 0,
            'failedlabel' => get_string('practice_failed_button', 'googlemeet', $failed),
        ];
    }
}
