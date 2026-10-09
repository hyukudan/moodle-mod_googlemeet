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
 * Mobile output class for googlemeet
 *
 * @package     mod_googlemeet
 * @copyright   2023 Rone Santos <ronefel@hotmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_googlemeet\output;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/locallib.php');

/**
 * Mobile app (Moodle App site plugin) views (UX-05).
 *
 * - Activity view: description (with images, via format_module_intro + core-format-text), join
 *   button (logs room_entered through a WS, then opens Meet), upcoming sessions and a paginated
 *   list of classes with their lesson titles. Nothing per class (questions, AI) is loaded here.
 * - Class view: summary, key points and chapters (honouring the AI review flag when present),
 *   materials, Drive link, "mark as watched" and the practice questions of that class only.
 *
 * @package     mod_googlemeet
 * @copyright   2023 Rone Santos <ronefel@hotmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mobile {

    /** @var int Classes per page in the app. */
    public const PERPAGE = 10;

    /**
     * Resolve and check access to the activity.
     *
     * @param array|\stdClass $args App arguments (cmid, courseid).
     * @return array [cm, context, googlemeet, course]
     */
    protected static function load($args): array {
        global $DB;

        $args = (object)$args;
        $cm = get_coursemodule_from_id('googlemeet', $args->cmid, 0, false, MUST_EXIST);
        require_login($cm->course, false, $cm, true, true);
        $context = \context_module::instance($cm->id);
        require_capability('mod/googlemeet:view', $context);
        $googlemeet = $DB->get_record('googlemeet', ['id' => $cm->instance], '*', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        return [$cm, $context, $googlemeet, $course];
    }

    /**
     * Activity view.
     *
     * @param array $args cmid, courseid, page (optional).
     * @return array
     */
    public static function mobile_course_view($args): array {
        global $OUTPUT;

        [$cm, $context, $googlemeet, $course] = self::load($args);
        $page = max(0, (int)(((array)$args)['page'] ?? 0));

        $list = self::recordings_page($googlemeet, $context, $page);
        $roomurl = \mod_googlemeet\local\room_entry::room_url($googlemeet);

        $data = [
            'cmid' => (int)$cm->id,
            'courseid' => (int)$course->id,
            'intro' => format_module_intro('googlemeet', $googlemeet, $cm->id),
            'hasroom' => $roomurl !== null,
            'upcomingevent' => googlemeet_get_upcoming_events($googlemeet->id, $googlemeet->maxupcomingevents ?? 3),
            'recording' => $list,
        ];

        // Completion and trigger events (first page only: paging is not a new view).
        if ($page === 0) {
            googlemeet_view($googlemeet, $course, $cm, $context);
        }

        return [
            'templates' => [
                [
                    'id' => 'main',
                    'html' => $OUTPUT->render_from_template('mod_googlemeet/mobile_view_page', $data),
                ],
            ],
            'javascript' => 'this.showUpcomingEvents = ' . json_encode(empty($list['hasrecordings'])) . ';'
                . 'this.googlemeetOpen = function(url) { if (url) { window.open(url, "_system"); } };',
            'otherdata' => ['roomurl' => (string)$roomurl],
            'files' => [],
        ];
    }

    /**
     * One page of visible classes with display titles and the user's watched flag.
     *
     * @param \stdClass $googlemeet Activity record.
     * @param \context_module $context Module context.
     * @param int $page Page (0-based).
     * @return array Template data.
     */
    public static function recordings_page(\stdClass $googlemeet, \context_module $context, int $page): array {
        global $DB, $USER;

        $params = ['googlemeetid' => $googlemeet->id, 'visible' => 1];
        $total = googlemeet_count_recordings($params + ['deleted' => 0]);
        $pages = max(1, (int)ceil($total / self::PERPAGE));
        $page = min($page, $pages - 1);
        $order = ($googlemeet->recordingsorder ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        $recordings = googlemeet_list_recordings($params, false, $order, self::PERPAGE, $page * self::PERPAGE);

        $titles = googlemeet_get_lesson_titles($googlemeet, false);
        $watched = [];
        if ($recordings) {
            [$insql, $inparams] = $DB->get_in_or_equal(array_column($recordings, 'id'), SQL_PARAMS_NAMED);
            $watched = $DB->get_records_select_menu('googlemeet_recording_progress',
                "userid = :userid AND completed = 1 AND recordingid $insql",
                ['userid' => $USER->id] + $inparams, '', 'recordingid, completed');
        }

        $items = [];
        foreach ($recordings as $recording) {
            $title = $titles[$recording->id]['title'] ?? $recording->displayname;
            $items[] = [
                'id' => (int)$recording->id,
                'title' => format_string($title, true, ['context' => $context]),
                'subtitle' => !empty($titles[$recording->id]['hassubtitle'])
                    ? format_string($titles[$recording->id]['subtitle'], true, ['context' => $context]) : '',
                'date' => userdate($recording->createdtime, get_string('strftimedaydate', 'langconfig')),
                'duration' => (string)$recording->duration,
                'watched' => !empty($watched[$recording->id]),
            ];
        }

        return [
            'hasrecordings' => !empty($items),
            'recordings' => $items,
            'total' => $total,
            'page' => $page,
            'haspages' => $pages > 1,
            'hasprev' => $page > 0,
            'hasnext' => $page < $pages - 1,
            'prevpage' => max(0, $page - 1),
            'nextpage' => $page + 1,
            'pagelabel' => get_string('mobile_page_of', 'googlemeet', (object)['page' => $page + 1, 'pages' => $pages]),
        ];
    }

    /**
     * Whether students may see the AI analysis of a class.
     *
     * When the site requires teacher review of AI content (googlemeet/requireaireview) and the
     * analysis table has a "reviewed" flag, unreviewed analyses are hidden from students. Both the
     * setting and the column are optional (another feature adds them).
     *
     * @param \stdClass|false $analysis Analysis row.
     * @param \context_module $context Module context.
     * @return bool
     */
    public static function ai_visible($analysis, \context_module $context): bool {
        global $DB;

        if (!$analysis || ($analysis->status ?? '') !== 'completed') {
            return false;
        }
        if (has_capability('mod/googlemeet:editrecording', $context)) {
            return true;
        }
        if (empty(get_config('googlemeet', 'requireaireview'))) {
            return true;
        }
        if (!property_exists($analysis, 'reviewed')) {
            static $hascolumn = null;
            if ($hascolumn === null) {
                $hascolumn = $DB->get_manager()->field_exists('googlemeet_ai_analysis', 'reviewed');
            }
            return !$hascolumn;
        }
        return !empty($analysis->reviewed);
    }

    /**
     * Materials of a class in the format of the app's core-files component.
     *
     * @param \context_module $context Module context.
     * @param int $recordingid Recording id.
     * @return array
     */
    public static function materials(\context_module $context, int $recordingid): array {
        $files = get_file_storage()->get_area_files($context->id, 'mod_googlemeet', 'recordingmaterial', $recordingid,
            'filename', false);
        $out = [];
        foreach ($files as $file) {
            $out[] = [
                'filename' => $file->get_filename(),
                'filepath' => $file->get_filepath(),
                'filesize' => (int)$file->get_filesize(),
                'fileurl' => \moodle_url::make_webservice_pluginfile_url($context->id, 'mod_googlemeet', 'recordingmaterial',
                    $recordingid, $file->get_filepath(), $file->get_filename())->out(false),
                'timemodified' => (int)$file->get_timemodified(),
                'mimetype' => $file->get_mimetype(),
            ];
        }
        return $out;
    }

    /**
     * Class view.
     *
     * @param array $args cmid, courseid, recordingid.
     * @return array
     */
    public static function mobile_recording_view($args): array {
        global $DB, $OUTPUT, $USER;

        [$cm, $context, $googlemeet] = self::load($args);
        $recordingid = (int)(((array)$args)['recordingid'] ?? 0);
        $recording = googlemeet_get_accessible_recording($googlemeet, $context, $recordingid);
        if (!$recording || empty($recording->visible)) {
            throw new \moodle_exception('recordingnotfound', 'googlemeet');
        }

        $titles = googlemeet_get_lesson_titles($googlemeet, false);
        $title = $titles[$recording->id]['title'] ?? googlemeet_display_name((string)$recording->name);

        $analysis = $DB->get_record('googlemeet_ai_analysis', ['recordingid' => $recording->id]);
        $summary = [];
        $keypoints = [];
        $chapters = [];
        if (self::ai_visible($analysis, $context)) {
            foreach (googlemeet_summary_paragraphs((string)$analysis->summary) as $paragraph) {
                $summary[] = ['text' => format_text($paragraph, FORMAT_PLAIN, ['context' => $context])];
            }
            foreach (json_decode((string)$analysis->keypoints) ?: [] as $keypoint) {
                if (is_scalar($keypoint) && trim((string)$keypoint) !== '') {
                    $keypoints[] = ['text' => format_string((string)$keypoint, true, ['context' => $context])];
                }
            }
            foreach (googlemeet_normalise_chapters($analysis->chapters ?? '') as $index => $chapter) {
                $chapters[] = [
                    'index' => $index,
                    'title' => format_string($chapter['title'], true, ['context' => $context]),
                    'start' => $chapter['start'],
                    'url' => googlemeet_get_recording_seek_url((string)$recording->webviewlink, (int)$chapter['startseconds']),
                ];
            }
        }

        $watched = (bool)$DB->get_field('googlemeet_recording_progress', 'completed',
            ['recordingid' => $recording->id, 'userid' => $USER->id]);

        // Practice questions of this class only.
        $practicequestions = [];
        $service = new \mod_googlemeet\question_service();
        foreach ($service->get_ready_practice_questions($googlemeet, $cm, $context, (int)$recording->id) as $index => $q) {
            $options = [];
            foreach ($q['options'] as $option) {
                $options[] = ['answerid' => (int)$option['answerid'], 'text' => trim(html_to_text($option['text'], 0, false))];
            }
            $practicequestions[] = [
                'questionid' => (int)$q['questionid'],
                'index' => $index,
                'position' => $index + 1,
                'stem' => trim(html_to_text($q['stem'], 0, false)),
                'options' => $options,
            ];
        }
        $materials = self::materials($context, (int)$recording->id);

        $data = [
            'cmid' => (int)$cm->id,
            'id' => (int)$recording->id,
            'title' => format_string($title, true, ['context' => $context]),
            'subtitle' => !empty($titles[$recording->id]['hassubtitle'])
                ? format_string($titles[$recording->id]['subtitle'], true, ['context' => $context]) : '',
            'date' => userdate($recording->createdtime, get_string('strftimedaydate', 'langconfig')),
            'duration' => (string)$recording->duration,
            'watched' => $watched,
            'hassummary' => !empty($summary),
            'summary' => $summary,
            'haskeypoints' => !empty($keypoints),
            'keypoints' => $keypoints,
            'haschapters' => !empty($chapters),
            'chapters' => $chapters,
            'hasmaterials' => !empty($materials),
            'haspracticequestions' => !empty($practicequestions),
            'practicequestions' => $practicequestions,
            'practicequestioncount' => count($practicequestions),
        ];

        $practicestate = ['index' => 0, 'selected' => new \stdClass(), 'checked' => new \stdClass(),
            'answer' => new \stdClass()];

        return [
            'templates' => [
                [
                    'id' => 'main',
                    'html' => $OUTPUT->render_from_template('mod_googlemeet/mobile_recording_page', $data),
                ],
            ],
            'javascript' => 'this.googlemeetPractice = ' . json_encode($practicestate) . ';'
                . 'this.googlemeetWatched = ' . json_encode($watched) . ';'
                . 'this.googlemeetOpen = function(url) { if (url) { window.open(url, "_system"); } };',
            'otherdata' => [
                'webviewlink' => (string)$recording->webviewlink,
                'materials' => json_encode($materials),
                'chapterurls' => json_encode(array_column($chapters, 'url')),
            ],
            'files' => $materials,
        ];
    }
}
