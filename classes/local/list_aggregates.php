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

/**
 * Page-level aggregated queries for the recordings list and the hub (PERF-03).
 *
 * Replaces per-recording lookups (published question count, attached materials, the full
 * recording list loaded just to find previous/next) with a constant number of queries.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class list_aggregates {

    /**
     * Number of published (ready) questions per recording, in one grouped query.
     *
     * Same rows as question_service::get_questions($readyonly = true) counts, without loading
     * every question and its answers.
     *
     * @param stdClass $googlemeet Activity record.
     * @param stdClass $cm Course module.
     * @param \context_module $context Module context.
     * @param int[] $recordingids Recordings on the page.
     * @return int[] recordingid => count (missing ids mean 0).
     */
    public static function published_question_counts(stdClass $googlemeet, stdClass $cm, \context_module $context,
            array $recordingids): array {
        global $DB;

        if (!$recordingids) {
            return [];
        }
        $category = (new question_service())->get_category($googlemeet, $cm, $context, false);
        if (!$category) {
            return [];
        }

        $tagtorecording = [];
        foreach ($recordingids as $recordingid) {
            $tagtorecording[\core_text::strtolower(question_service::tag_for_recording((int)$recordingid))] = (int)$recordingid;
        }
        [$insql, $inparams] = $DB->get_in_or_equal(array_keys($tagtorecording), SQL_PARAMS_NAMED, 'qtag');
        $params = $inparams + [
            'categoryid' => $category->id,
            'component' => 'core_question',
            'itemtype' => 'question',
            'readystatus' => question_version_status::QUESTION_STATUS_READY,
        ];
        $rows = $DB->get_records_sql(
            "SELECT t.name AS tagname, COUNT(q.id) AS questioncount
               FROM {question} q
               JOIN {question_versions} qv ON qv.questionid = q.id
               JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
               JOIN {tag_instance} ti ON ti.itemid = q.id
               JOIN {tag} t ON t.id = ti.tagid
              WHERE qbe.questioncategoryid = :categoryid
                AND ti.component = :component
                AND ti.itemtype = :itemtype
                AND qv.status = :readystatus
                AND t.name {$insql}
           GROUP BY t.name", $params);

        $counts = [];
        foreach ($rows as $row) {
            $key = \core_text::strtolower($row->tagname);
            if (isset($tagtorecording[$key])) {
                $counts[$tagtorecording[$key]] = (int)$row->questioncount;
            }
        }
        return $counts;
    }

    /**
     * Attached materials of several recordings, with one file-area read for the module context.
     *
     * @param \context_module $context Module context.
     * @param int[] $recordingids Recordings wanted.
     * @return array recordingid => list of material arrays (see material_entry()); every id is present.
     */
    public static function materials_by_recording(\context_module $context, array $recordingids): array {
        $materials = array_fill_keys(array_map('intval', $recordingids), []);
        if (!$materials) {
            return [];
        }
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'mod_googlemeet', 'recordingmaterial', false, 'itemid, filename', false);
        foreach ($files as $file) {
            $itemid = (int)$file->get_itemid();
            if ($file->is_directory() || !array_key_exists($itemid, $materials)) {
                continue;
            }
            $materials[$itemid][] = self::material_entry($context, $file);
        }
        return $materials;
    }

    /**
     * Template data for one attached material file.
     *
     * @param \context_module $context
     * @param \stored_file $file
     * @return array
     */
    public static function material_entry(\context_module $context, \stored_file $file): array {
        global $OUTPUT;

        $filepath = $file->get_filepath();
        return [
            'name' => $file->get_filename(),
            'icon' => $OUTPUT->image_url(file_file_icon($file), 'moodle')->out(false),
            'size' => display_size($file->get_filesize()),
            'modified' => userdate($file->get_timemodified(), get_string('strftimedatetimeshort')),
            'filepath' => ($filepath !== '/' && $filepath !== '') ? trim($filepath, '/') : '',
            'url' => \moodle_url::make_pluginfile_url(
                $context->id,
                'mod_googlemeet',
                'recordingmaterial',
                $file->get_itemid(),
                $filepath,
                $file->get_filename(),
                true
            )->out(false),
        ];
    }

    /**
     * Chronologically previous and next recordings of the hub, with two LIMIT 1 queries.
     *
     * Previous = the latest recording created before this one; next = the earliest created after
     * it (ties broken by id), whatever the list order, as the hub always navigated in time.
     *
     * @param stdClass $recording Current recording (id, googlemeetid, createdtime).
     * @param bool $visibleonly Students only navigate visible recordings.
     * @return array [?stdClass $previous, ?stdClass $next] rows with id, name, createdtime.
     */
    public static function hub_neighbours(stdClass $recording, bool $visibleonly): array {
        global $DB;

        $params = [
            'googlemeetid' => (int)$recording->googlemeetid,
            'created1' => (int)$recording->createdtime,
            'created2' => (int)$recording->createdtime,
            'id' => (int)$recording->id,
        ];
        $where = 'googlemeetid = :googlemeetid AND deleted = 0' . ($visibleonly ? ' AND visible = 1' : '');
        $fields = 'id, googlemeetid, name, createdtime';

        $previous = $DB->get_records_select('googlemeet_recordings',
            "{$where} AND (createdtime < :created1 OR (createdtime = :created2 AND id < :id))",
            $params, 'createdtime DESC, id DESC', $fields, 0, 1);
        $next = $DB->get_records_select('googlemeet_recordings',
            "{$where} AND (createdtime > :created1 OR (createdtime = :created2 AND id > :id))",
            $params, 'createdtime ASC, id ASC', $fields, 0, 1);

        return [$previous ? reset($previous) : null, $next ? reset($next) : null];
    }
}
