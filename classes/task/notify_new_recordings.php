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

namespace mod_googlemeet\task;

use mod_googlemeet\local\reminders;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/lib.php');

/**
 * Send notifications when new recordings are synced.
 *
 * The notice names each lesson with its readable title and, when the AI analysis is ready (and,
 * if the site requires it, reviewed), a short summary and the chapter list (NOT-05). While an
 * analysis is still queued or running, the notice is postponed for a bounded time so it can
 * include that content. All texts come from the language pack, in the recipient's language
 * (QA-03); the HTML body is the mod_googlemeet/email_new_recordings template, wrapped by the
 * optional local_achievements email template when that plugin is installed.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class notify_new_recordings extends \core\task\adhoc_task {

    /** @var int Longest time the notice waits for a pending AI analysis. */
    const AI_WAIT_MAX = 3 * HOURSECS;

    /** @var int Delay between checks while waiting for the AI analysis. */
    const AI_WAIT_STEP = 15 * MINSECS;

    /** @var int Maximum length of the summary excerpt. */
    const SUMMARY_LENGTH = 280;

    /** @var int Maximum chapters listed per lesson. */
    const MAX_CHAPTERS = 8;

    /**
     * Execute the task.
     */
    public function execute() {
        global $DB;

        $data = $this->get_custom_data();
        $googlemeetid = (int)($data->googlemeetid ?? 0);
        $newcount = (int)($data->newcount ?? 0);
        $recordingids = array_values(array_filter(array_map('intval', (array)($data->recordingids ?? []))));
        $queuedat = (int)($data->queuedat ?? 0) ?: time();

        if (!$googlemeetid || !$newcount) {
            return;
        }

        $googlemeet = $DB->get_record('googlemeet', ['id' => $googlemeetid]);
        $cm = $googlemeet ? get_coursemodule_from_instance('googlemeet', $googlemeetid, 0, false, IGNORE_MISSING) : null;
        if (!$googlemeet || !$cm) {
            return;
        }

        $lessons = [];
        if (!empty($recordingids)) {
            if ($this->should_wait_for_ai($recordingids, $queuedat)) {
                $this->postpone($googlemeetid, $newcount, $recordingids, $queuedat);
                return;
            }
            $lessons = self::get_lessons($googlemeet, (int)$cm->id, $recordingids);
            if (empty($lessons)) {
                // Removed or hidden in the meantime: nothing to announce.
                return;
            }
            $newcount = count($lessons);
        }

        $context = \context_module::instance($cm->id);
        $subscribers = $DB->get_records('googlemeet_recording_subs', ['googlemeetid' => $googlemeetid]);

        foreach ($subscribers as $subscriber) {
            try {
                $user = $DB->get_record('user', ['id' => $subscriber->userid, 'deleted' => 0, 'suspended' => 0], '*',
                    IGNORE_MISSING);
                if (!$user || !is_enrolled($context, $user, 'mod/googlemeet:view', true) ||
                        !has_capability('mod/googlemeet:view', $context, $user) ||
                        !\core_availability\info_module::is_user_visible($cm, $user->id, false)) {
                    continue;
                }
                message_send(self::build_message($user, $googlemeet, $cm, $newcount, $lessons));
            } catch (\Throwable $e) {
                mtrace('mod_googlemeet recording notification failed for user ' . $subscriber->userid . ': ' .
                    $e->getMessage());
            }
        }
    }

    /**
     * Whether a pending AI analysis is worth waiting for.
     *
     * @param int[] $recordingids New recordings.
     * @param int $queuedat When the notice was first queued.
     * @return bool
     */
    protected function should_wait_for_ai(array $recordingids, int $queuedat): bool {
        global $DB;
        if (time() - $queuedat >= self::AI_WAIT_MAX) {
            return false;
        }
        [$insql, $params] = $DB->get_in_or_equal($recordingids, SQL_PARAMS_NAMED);
        $params['pending'] = 'pending';
        $params['processing'] = 'processing';
        return $DB->record_exists_select('googlemeet_ai_analysis',
            "recordingid $insql AND status IN (:pending, :processing)", $params);
    }

    /**
     * Queue a copy of this notice to run again later.
     *
     * @param int $googlemeetid Activity id.
     * @param int $newcount Number of new recordings.
     * @param int[] $recordingids New recordings.
     * @param int $queuedat When the notice was first queued.
     * @return void
     */
    protected function postpone(int $googlemeetid, int $newcount, array $recordingids, int $queuedat): void {
        $task = new self();
        $task->set_custom_data([
            'googlemeetid' => $googlemeetid,
            'newcount' => $newcount,
            'recordingids' => $recordingids,
            'queuedat' => $queuedat,
        ]);
        $task->set_next_run_time(time() + self::AI_WAIT_STEP);
        \core\task\manager::queue_adhoc_task($task);
        mtrace("mod_googlemeet: recording notice for activity #{$googlemeetid} waits for the AI analysis.");
    }

    /**
     * Lessons to announce: visible, non-deleted new recordings with their readable title and,
     * when available and allowed, a short AI summary and the chapter list.
     *
     * @param \stdClass $googlemeet Activity record.
     * @param int $cmid Course module id.
     * @param int[] $recordingids New recordings.
     * @return array[] Lessons in chronological order.
     */
    public static function get_lessons(\stdClass $googlemeet, int $cmid, array $recordingids): array {
        global $DB;
        if (empty($recordingids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($recordingids, SQL_PARAMS_NAMED);
        $params['googlemeetid'] = (int)$googlemeet->id;
        $recordings = $DB->get_records_select('googlemeet_recordings',
            "googlemeetid = :googlemeetid AND deleted = 0 AND visible = 1 AND id $insql", $params,
            'createdtime ASC, id ASC', 'id, name, createdtime');
        if (!$recordings) {
            return [];
        }

        [$insql2, $params2] = $DB->get_in_or_equal(array_keys($recordings), SQL_PARAMS_NAMED);
        $analyses = [];
        foreach ($DB->get_records_select('googlemeet_ai_analysis', "recordingid $insql2 AND status = 'completed'",
                $params2) as $analysis) {
            $analyses[(int)$analysis->recordingid] = $analysis;
        }

        // IA-04: AI content not yet reviewed by a teacher is never quoted (same rule as the web pages).
        $titles = self::lesson_titles($googlemeet);

        $lessons = [];
        foreach ($recordings as $recording) {
            $id = (int)$recording->id;
            $title = $titles[$id]['title'] ?? \googlemeet_display_name((string)$recording->name);
            $summary = '';
            $chapters = [];
            $analysis = $analyses[$id] ?? null;
            if ($analysis && \mod_googlemeet\local\ai_review::is_visible_to_students($analysis)) {
                $summary = self::summary_excerpt((string)($analysis->summary ?? ''));
                foreach (array_slice(\googlemeet_normalise_chapters($analysis->chapters ?? null), 0,
                        self::MAX_CHAPTERS) as $chapter) {
                    $chapters[] = ['start' => $chapter['start'], 'title' => $chapter['title']];
                }
            }
            $lessons[] = [
                'id' => $id,
                'title' => $title,
                'createdtime' => (int)$recording->createdtime,
                'url' => (new \moodle_url('/mod/googlemeet/view.php', ['id' => $cmid, 'recording' => $id]))->out(false),
                'summary' => $summary,
                'chapters' => $chapters,
            ];
        }
        return $lessons;
    }

    /**
     * Readable lesson titles (PLY-06) for the visible recordings of the activity.
     *
     * Computed directly instead of through googlemeet_get_lesson_titles(), whose per-request
     * cache could predate the recordings just synced when several tasks share a cron process.
     *
     * @param \stdClass $googlemeet Activity record.
     * @return array Map recording id => ['title', 'subtitle', 'hassubtitle'].
     */
    protected static function lesson_titles(\stdClass $googlemeet): array {
        global $DB;
        $rows = $DB->get_records_sql(
            "SELECT r.id, r.name, a.topics, a.status, a.reviewed
               FROM {googlemeet_recordings} r
          LEFT JOIN {googlemeet_ai_analysis} a ON a.recordingid = r.id AND a.status = 'completed'
              WHERE r.googlemeetid = :googlemeetid AND r.deleted = 0 AND r.visible = 1",
            ['googlemeetid' => (int)$googlemeet->id]
        );
        $items = [];
        foreach ($rows as $row) {
            // IA-04: titles built from unreviewed AI topics would leak them into the email subject.
            $topics = [];
            if ($row->status !== null && !\mod_googlemeet\local\ai_review::is_pending_review($row)) {
                $topics = json_decode((string)$row->topics) ?: [];
            }
            $items[$row->id] = ['name' => (string)$row->name, 'topics' => $topics];
        }
        return \googlemeet_assign_lesson_titles($items,
            [(string)($googlemeet->name ?? ''), (string)($googlemeet->originalname ?? '')]);
    }

    /**
     * Plain-text excerpt of an AI summary (markup and markdown removed, truncated).
     *
     * @param string $summary Stored summary.
     * @return string
     */
    public static function summary_excerpt(string $summary): string {
        $text = html_to_text($summary, 0, false);
        $text = preg_replace('/[*_#`>]+/u', '', $text);
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return '';
        }
        return \googlemeet_truncate_summary($text, self::SUMMARY_LENGTH);
    }

    /**
     * Build the notice for one recipient, in the recipient's language.
     *
     * @param \stdClass $user Recipient.
     * @param \stdClass $googlemeet Activity record.
     * @param \stdClass|\cm_info $cm Course module.
     * @param int $newcount Number of new recordings.
     * @param array[] $lessons Lessons from get_lessons() (may be empty for legacy notices).
     * @return \core\message\message
     */
    public static function build_message(\stdClass $user, \stdClass $googlemeet, $cm, int $newcount,
            array $lessons): \core\message\message {
        global $PAGE;

        $previouslang = null;
        if (!empty($user->lang) && get_string_manager()->translation_exists($user->lang, false)) {
            $previouslang = force_current_language($user->lang);
        }
        try {
            $context = \context_module::instance($cm->id);
            $url = new \moodle_url('/mod/googlemeet/view.php', ['id' => $cm->id]);
            // Plain text and Mustache-escaped uses: format_string() must not HTML-escape here.
            $name = format_string($googlemeet->name, true, ['context' => $context, 'escape' => false]);
            $isone = ($newcount === 1);
            $a = (object)[
                'name' => $name,
                'count' => $newcount,
                'url' => $url->out(false),
                'user' => (string)($user->firstname ?? ''),
            ];

            $subject = get_string('recordingavailable_subject' . ($isone ? '_one' : '_many'), 'googlemeet', $a);
            $greeting = $a->user !== ''
                ? get_string('recordingnotice_greeting', 'googlemeet', $a->user)
                : get_string('recordingnotice_greeting_noname', 'googlemeet');
            $intro = $isone
                ? get_string('recordingnotice_intro_one', 'googlemeet', $name)
                : get_string('recordingnotice_intro_many', 'googlemeet', $a);
            $buttonlabel = get_string('recordingnotice_button' . ($isone ? '_one' : '_many'), 'googlemeet');
            $closing = get_string('recordingnotice_closing', 'googlemeet');
            $title = $isone
                ? get_string('recordingnotice_title_one', 'googlemeet')
                : get_string('recordingnotice_title_many', 'googlemeet', $newcount);

            $tplessons = [];
            $text = $greeting . "\n\n" . $intro . "\n";
            foreach ($lessons as $lesson) {
                $date = userdate($lesson['createdtime'], get_string('strftimedaydate', 'langconfig'), $user->timezone);
                $chapters = $lesson['chapters'];
                $tplessons[] = [
                    'title' => $lesson['title'],
                    'date' => $date,
                    'url' => $lesson['url'],
                    'summary' => $lesson['summary'],
                    'hassummary' => $lesson['summary'] !== '',
                    'chapters' => $chapters,
                    'haschapters' => !empty($chapters),
                ];
                $text .= "\n" . $lesson['title'] . ' (' . $date . ")\n";
                if ($lesson['summary'] !== '') {
                    $text .= $lesson['summary'] . "\n";
                }
                if ($chapters) {
                    $text .= get_string('recordingnotice_chapters', 'googlemeet') . ":\n";
                    foreach ($chapters as $chapter) {
                        $text .= '  ' . $chapter['start'] . ' ' . $chapter['title'] . "\n";
                    }
                }
                $text .= $lesson['url'] . "\n";
            }
            $text .= "\n" . $buttonlabel . ': ' . $url->out(false) . "\n\n" . $closing;

            $renderer = $PAGE->get_renderer('core');
            $inner = $renderer->render_from_template('mod_googlemeet/email_new_recordings', [
                'greeting' => $greeting,
                'intro' => $intro,
                'haslessons' => !empty($tplessons),
                'lessons' => $tplessons,
                'chapterslabel' => get_string('recordingnotice_chapters', 'googlemeet'),
                'buttonurl' => $url->out(false),
                'buttonlabel' => $buttonlabel,
                'closing' => $closing,
            ]);
            $html = $inner;
            // Optional campus-branded wrapper, only when that plugin is installed.
            if (class_exists('\\local_achievements\\email_template')) {
                try {
                    $html = \local_achievements\email_template::wrap($title, $inner);
                } catch (\Throwable $e) {
                    debugging('mod_googlemeet: branded recording notice wrapper failed: ' . $e->getMessage(),
                        DEBUG_DEVELOPER);
                    $html = $inner;
                }
            }

            $message = new \core\message\message();
            $message->component = 'mod_googlemeet';
            $message->name = 'recordingavailable';
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = $user;
            $message->subject = $subject;
            $message->fullmessage = $text;
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = $html;
            $message->smallmessage = $subject;
            $message->notification = 1;
            $message->contexturl = $url->out(false);
            $message->contexturlname = $name;
            $message->courseid = $googlemeet->course;
            // NOT-03: lets the Moodle app open the activity from the push notification.
            $message->customdata = reminders::app_customdata((int)$cm->id, (int)$googlemeet->course, $url);
            return $message;
        } finally {
            if ($previouslang !== null) {
                force_current_language($previouslang);
            }
        }
    }
}
