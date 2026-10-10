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

use context_module;
use stdClass;

/**
 * IA-04: teacher review of AI-generated content before students see it.
 *
 * Single source of truth for the review rule, so every surface (hub, list, search, topic chips,
 * lesson titles, "continue watching", web services, notifications) applies the same test:
 *
 *     visible to students  <=>  status = 'completed' AND (NOT requireaireview OR reviewed = 1)
 *
 * Config: googlemeet/requireaireview (default 1). Column: googlemeet_ai_analysis.reviewed
 * (+ timereviewed, reviewedby). A row's "reviewed" flag means "a person approved the CURRENT
 * content": every AI (re)write clears it, a manual teacher edit sets it.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_review {

    /** @var string Capability that lets a user see unreviewed content and publish it. */
    const CAPABILITY = 'mod/googlemeet:editrecording';

    /**
     * Whether AI content needs a teacher review before students see it.
     *
     * An unset config (fresh install before the admin saves the settings page) counts as "required":
     * the safe default.
     *
     * @return bool
     */
    public static function is_required(): bool {
        $value = get_config('googlemeet', 'requireaireview');
        return $value === false || $value === null || (bool)$value;
    }

    /**
     * Whether an analysis row has content that has not been reviewed yet (and review is required).
     *
     * @param stdClass|null|false $analysis googlemeet_ai_analysis row.
     * @return bool
     */
    public static function is_pending_review($analysis): bool {
        return $analysis && ($analysis->status ?? '') === 'completed'
            && self::is_required() && empty($analysis->reviewed);
    }

    /**
     * Whether a students-level viewer may see the analysis content.
     *
     * @param stdClass|null|false $analysis googlemeet_ai_analysis row.
     * @return bool
     */
    public static function is_visible_to_students($analysis): bool {
        return $analysis && ($analysis->status ?? '') === 'completed' && !self::is_pending_review($analysis);
    }

    /**
     * Whether the current user may see the analysis content in the given context.
     *
     * @param stdClass|null|false $analysis googlemeet_ai_analysis row.
     * @param \context $context Module context.
     * @return bool
     */
    public static function is_visible_to_user($analysis, \context $context): bool {
        if (!$analysis || ($analysis->status ?? '') !== 'completed') {
            return false;
        }
        return self::is_visible_to_students($analysis) || has_capability(self::CAPABILITY, $context);
    }

    /**
     * Whether the current user may see unreviewed content of an activity instance.
     *
     * @param int $googlemeetid Instance id.
     * @return bool
     */
    public static function can_see_unreviewed(int $googlemeetid): bool {
        $cm = get_coursemodule_from_instance('googlemeet', $googlemeetid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return false;
        }
        $context = context_module::instance($cm->id, IGNORE_MISSING);
        return $context && has_capability(self::CAPABILITY, $context);
    }

    /**
     * Fields to merge into a row whenever AI (re)writes its content: the new text is unreviewed.
     *
     * @return array
     */
    public static function unreviewed_fields(): array {
        return ['reviewed' => 0, 'timereviewed' => 0, 'reviewedby' => 0];
    }

    /**
     * Fields to merge into a row when a person approves (or writes) its content.
     *
     * @param int $userid Reviewer.
     * @return array
     */
    public static function reviewed_fields(int $userid): array {
        return ['reviewed' => 1, 'timereviewed' => time(), 'reviewedby' => $userid];
    }

    /**
     * Mark one analysis as reviewed (published to students).
     *
     * When $seen is given (the timemodified of the content the teacher had on screen) the row is
     * only published if it still holds that same content: a retry or regeneration that finished in
     * between must be reviewed again.
     *
     * @param int $analysisid Row id.
     * @param int $userid Reviewer.
     * @param int $seen timemodified of the reviewed content, 0 = do not check.
     * @return bool True when the row was completed and is now reviewed.
     */
    public static function mark_reviewed(int $analysisid, int $userid, int $seen = 0): bool {
        global $DB;

        $row = $DB->get_record('googlemeet_ai_analysis', ['id' => $analysisid], 'id, status, reviewed, timemodified');
        if (!$row || $row->status !== 'completed') {
            return false;
        }
        if ($seen > 0 && (int)$row->timemodified !== $seen) {
            return false;
        }
        if (!empty($row->reviewed)) {
            return true;
        }
        $fields = self::reviewed_fields($userid);
        $params = ['id' => $analysisid, 'reviewed' => $fields['reviewed'], 'timereviewed' => $fields['timereviewed'],
            'reviewedby' => $fields['reviewedby'], 'status' => 'completed'];
        $where = 'id = :id AND status = :status';
        if ($seen > 0) {
            // Atomic with the check above: nothing may rewrite the row between reading and publishing.
            $where .= ' AND timemodified = :seen';
            $params['seen'] = $seen;
        }
        $DB->execute("UPDATE {googlemeet_ai_analysis}
                         SET reviewed = :reviewed, timereviewed = :timereviewed, reviewedby = :reviewedby
                       WHERE {$where}", $params);
        return (bool)$DB->get_field('googlemeet_ai_analysis', 'reviewed', ['id' => $analysisid]);
    }

    /**
     * Ids of the completed, unreviewed analyses of an instance (live recordings only).
     *
     * @param int $googlemeetid Instance id.
     * @return int[]
     */
    public static function get_pending_ids(int $googlemeetid): array {
        global $DB;

        $sql = "SELECT a.id
                  FROM {googlemeet_ai_analysis} a
                  JOIN {googlemeet_recordings} r ON r.id = a.recordingid
                 WHERE r.googlemeetid = :googlemeetid
                   AND r.deleted = 0
                   AND a.status = :completed
                   AND a.reviewed = 0";
        return array_map('intval', $DB->get_fieldset_sql($sql, ['googlemeetid' => $googlemeetid, 'completed' => 'completed']));
    }

    /**
     * Publish every reviewable (completed, unreviewed) analysis of an instance.
     *
     * With $seenbefore (when the teacher loaded the page that offered "Publish all"), content
     * written after that moment is left pending: the teacher confirmed what was there, not what a
     * retry produced meanwhile.
     *
     * @param int $googlemeetid Instance id.
     * @param int $userid Reviewer.
     * @param int $seenbefore Only publish content last written at or before this time (0 = no limit).
     * @return int Number of analyses published.
     */
    public static function mark_all_reviewed(int $googlemeetid, int $userid, int $seenbefore = 0): int {
        global $DB;

        $ids = self::get_pending_ids($googlemeetid);
        if (!$ids) {
            return 0;
        }
        $published = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            $params += ['userid' => $userid, 'now' => time(), 'completed' => 'completed'];
            $where = "id {$insql} AND reviewed = 0 AND status = :completed";
            if ($seenbefore > 0) {
                $where .= ' AND timemodified <= :seenbefore';
                $params['seenbefore'] = $seenbefore;
            }
            $published += $DB->count_records_select('googlemeet_ai_analysis', $where, $params);
            $DB->execute("UPDATE {googlemeet_ai_analysis}
                             SET reviewed = 1, timereviewed = :now, reviewedby = :userid
                           WHERE {$where}", $params);
        }
        return $published;
    }

    /**
     * Template context for the hub: content visibility, the teacher review banner and the F-8 stuck alert.
     *
     * @param stdClass|null|false $analysis googlemeet_ai_analysis row.
     * @param \context $context Module context.
     * @param int $cmid Course module id.
     * @param bool $cangenerate Whether the user may (re)generate analyses (retry button).
     * @return array
     */
    public static function hub_context($analysis, \context $context, int $cmid, bool $cangenerate): array {
        $canreview = has_capability(self::CAPABILITY, $context);
        $pending = self::is_pending_review($analysis);
        $stuck = $cangenerate && \mod_googlemeet\ai_service::is_stuck($analysis);
        return [
            'aicontentvisible' => self::is_visible_to_students($analysis) || ($canreview && $analysis
                && ($analysis->status ?? '') === 'completed'),
            'aipendingreview' => $pending && $canreview,
            'aireviewcmid' => $cmid,
            'aireviewrecordingid' => $analysis ? (int)$analysis->recordingid : 0,
            // IA-04: version of the content on screen, so publishing never approves a newer rewrite.
            'aireviewseen' => $analysis ? (int)($analysis->timemodified ?? 0) : 0,
            'aistatusisstuck' => $stuck,
            'aistuckmessage' => $stuck ? get_string('ai_status_stuck', 'googlemeet',
                (int)round(\mod_googlemeet\ai_service::get_stuck_threshold() / MINSECS)) : '',
        ];
    }

    /**
     * Activity-level banner for teachers: "N summaries pending review" + bulk publish button.
     *
     * @param stdClass $googlemeet Instance.
     * @param stdClass|\cm_info $cm Course module.
     * @param \context $context Module context.
     * @return string HTML ('' when nothing to show).
     */
    public static function render_bulk_banner(stdClass $googlemeet, $cm, \context $context): string {
        global $OUTPUT;

        if (!self::is_required() || !has_capability(self::CAPABILITY, $context)) {
            return '';
        }
        $count = count(self::get_pending_ids((int)$googlemeet->id));
        if ($count === 0) {
            return '';
        }
        return $OUTPUT->render_from_template('mod_googlemeet/ai_review_bulk', [
            'cmid' => $cm->id,
            'count' => $count,
            'seen' => time(),
            'message' => get_string($count === 1 ? 'aireview_bulk_message_one' : 'aireview_bulk_message', 'googlemeet', $count),
        ]);
    }
}
