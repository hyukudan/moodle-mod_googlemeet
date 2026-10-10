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

namespace mod_googlemeet;

use mod_googlemeet\task\notify_new_recordings;
use mod_googlemeet\task\process_autosync;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/lib.php');

/**
 * New-recording notice with lesson content (NOT-05, QA-03) and the teacher alert when
 * auto-sync gives up without a recording (NOT-05).
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(notify_new_recordings::class)]
#[CoversClass(process_autosync::class)]
final class notify_new_recordings_test extends \advanced_testcase {

    /**
     * Course, activity, subscribed student, unsubscribed student and teacher.
     *
     * @return array [course, googlemeet, subscriber, other student, teacher]
     */
    private function create_fixture(): array {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['fullname' => 'Auxiliar administrativo']);
        $googlemeet = $gen->create_module('googlemeet', ['course' => $course->id, 'name' => 'Constitution']);
        $subscriber = $gen->create_user(['firstname' => 'Ana', 'lang' => 'en']);
        $gen->enrol_user($subscriber->id, $course->id, 'student');
        $other = $gen->create_user();
        $gen->enrol_user($other->id, $course->id, 'student');
        $teacher = $gen->create_user();
        $gen->enrol_user($teacher->id, $course->id, 'editingteacher');
        $DB->insert_record('googlemeet_recording_subs', (object)[
            'googlemeetid' => $googlemeet->id,
            'userid' => $subscriber->id,
            'timecreated' => time(),
        ]);
        // A subscriber who is no longer enrolled is skipped.
        $gone = $gen->create_user();
        $DB->insert_record('googlemeet_recording_subs', (object)[
            'googlemeetid' => $googlemeet->id,
            'userid' => $gone->id,
            'timecreated' => time(),
        ]);
        return [$course, $googlemeet, $subscriber, $other, $teacher];
    }

    /**
     * Insert a recording.
     *
     * @param int $googlemeetid Activity id.
     * @param string $name Drive name.
     * @param array $overrides Field overrides.
     * @return int Recording id.
     */
    private function create_recording(int $googlemeetid, string $name, array $overrides = []): int {
        global $DB;
        return (int)$DB->insert_record('googlemeet_recordings', (object)array_merge([
            'googlemeetid' => $googlemeetid,
            'recordingid' => 'drive-' . random_string(8),
            'name' => $name,
            'createdtime' => time() - HOURSECS,
            'duration' => '01:00:00',
            'webviewlink' => 'https://drive.google.com/file/d/x/view',
            'visible' => 1,
            'deleted' => 0,
            'timedeleted' => 0,
            'timemodified' => time(),
        ], $overrides));
    }

    /**
     * Insert an AI analysis.
     *
     * @param int $recordingid Recording id.
     * @param string $status Status.
     * @param int $reviewed IA-04 review flag (1 = published to students).
     * @return int Analysis id.
     */
    private function create_analysis(int $recordingid, string $status = 'completed', int $reviewed = 1): int {
        global $DB;
        return (int)$DB->insert_record('googlemeet_ai_analysis', (object)[
            'recordingid' => $recordingid,
            'summary' => "**Territorial organisation**: autonomous communities, provinces and municipalities.\n\nMore.",
            'keypoints' => '[]',
            'topics' => json_encode(['Title VIII: territorial organisation']),
            'chapters' => json_encode([
                ['title' => 'Introduction', 'start' => '0:00'],
                ['title' => 'Article 137', 'start' => '12:30'],
            ]),
            'status' => $status,
            'reviewed' => $reviewed,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Run the adhoc task with the given custom data.
     *
     * @param array $data Custom data.
     * @return void
     */
    private function run_task(array $data): void {
        $task = new notify_new_recordings();
        $task->set_custom_data($data);
        ob_start();
        $task->execute();
        ob_end_clean();
    }

    /**
     * The notice names the lesson, quotes a short summary and lists the chapters; only enrolled
     * subscribers receive it; texts come from the language pack (no emoji, English for "en").
     */
    public function test_notice_content_and_recipients(): void {
        $this->resetAfterTest();
        [, $googlemeet, $subscriber] = $this->create_fixture();
        $recordingid = $this->create_recording($googlemeet->id, 'Constitution - 2026/10/08 17:00 CEST - Recording');
        $hidden = $this->create_recording($googlemeet->id, 'Constitution hidden', ['visible' => 0]);
        $this->create_analysis($recordingid);

        $sink = $this->redirectMessages();
        $this->run_task(['googlemeetid' => $googlemeet->id, 'newcount' => 2, 'recordingids' => [$recordingid, $hidden]]);
        $messages = $sink->get_messages();

        $this->assertCount(1, $messages);
        $message = $messages[0];
        $this->assertEquals($subscriber->id, $message->useridto);
        $this->assertSame('recordingavailable', $message->eventtype);
        $this->assertSame('New recording available in Constitution', $message->subject);
        $this->assertSame(0, preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $message->subject));

        foreach ([$message->fullmessage, $message->fullmessagehtml] as $body) {
            $this->assertStringContainsString('Hi Ana,', $body);
            $this->assertStringContainsString('Title VIII: territorial organisation', $body);
            $this->assertStringContainsString('autonomous communities, provinces and municipalities', $body);
            $this->assertStringContainsString('Article 137', $body);
            $this->assertStringContainsString('12:30', $body);
            $this->assertStringNotContainsString('**', $body);
            $this->assertStringNotContainsString('Constitution hidden', $body);
        }
        $this->assertStringContainsString('recording=' . $recordingid, $message->fullmessagehtml);
        $this->assertStringContainsString('/mod/googlemeet/view.php?id=' . $googlemeet->cmid, $message->contexturl);
        $customdata = is_string($message->customdata) ? json_decode($message->customdata, true)
            : (array)$message->customdata;
        $this->assertEquals($googlemeet->cmid, $customdata['cmid']);
    }

    /**
     * With review required by the site, unreviewed AI content is not quoted (tolerates the
     * review field not existing).
     */
    public function test_unreviewed_ai_content_is_not_quoted(): void {
        $this->resetAfterTest();
        [, $googlemeet] = $this->create_fixture();
        $recordingid = $this->create_recording($googlemeet->id, 'Constitution');
        $this->create_analysis($recordingid, 'completed', 0);
        set_config('requireaireview', 1, 'googlemeet');

        $lessons = notify_new_recordings::get_lessons($googlemeet, (int)$googlemeet->cmid, [$recordingid]);
        $this->assertCount(1, $lessons);
        $this->assertSame('', $lessons[0]['summary']);
        $this->assertSame([], $lessons[0]['chapters']);
        // The lesson title is not built from unreviewed AI topics either.
        $this->assertStringNotContainsString('Title VIII', $lessons[0]['title']);
    }

    /**
     * A pending AI analysis postpones the notice (bounded): a new task is queued and nothing is
     * sent; once the wait is over the notice goes out without the AI content.
     */
    public function test_waits_for_pending_ai_analysis(): void {
        $this->resetAfterTest();
        [, $googlemeet] = $this->create_fixture();
        $recordingid = $this->create_recording($googlemeet->id, 'Constitution');
        $this->create_analysis($recordingid, 'pending');

        $sink = $this->redirectMessages();
        $this->run_task(['googlemeetid' => $googlemeet->id, 'newcount' => 1, 'recordingids' => [$recordingid]]);
        $this->assertCount(0, $sink->get_messages());
        $queued = \core\task\manager::get_adhoc_tasks(notify_new_recordings::class);
        $this->assertCount(1, $queued);
        $queued = reset($queued);
        $this->assertGreaterThan(time(), $queued->get_next_run_time());
        $this->assertNotEmpty($queued->get_custom_data()->queuedat);

        // Waited long enough: send anyway.
        $this->run_task(['googlemeetid' => $googlemeet->id, 'newcount' => 1, 'recordingids' => [$recordingid],
            'queuedat' => time() - notify_new_recordings::AI_WAIT_MAX - 1]);
        $this->assertCount(1, $sink->get_messages());
    }

    /**
     * When auto-sync gives up, the activity's teachers (not students) are alerted; site admins
     * only when no teacher qualifies.
     */
    public function test_autosync_exhausted_alerts_teachers(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $googlemeet, $subscriber, , $teacher] = $this->create_fixture();
        $event = (object)['eventid' => 1, 'eventdate' => time() - DAYSECS];

        $sink = $this->redirectMessages();
        ob_start();
        $sent = process_autosync::notify_no_recording($googlemeet, $event, 3);
        ob_end_clean();
        $messages = $sink->get_messages();
        $this->assertSame(1, $sent);
        $this->assertCount(1, $messages);
        $this->assertEquals($teacher->id, $messages[0]->useridto);
        $this->assertSame('autosyncfailed', $messages[0]->eventtype);
        $this->assertStringContainsString('No recording found for "Constitution"', $messages[0]->subject);
        $this->assertStringContainsString('3 attempts', $messages[0]->fullmessage);
        $this->assertNotEquals($subscriber->id, $messages[0]->useridto);
        $sink->clear();

        // No teacher left: admins are told instead.
        $enrol = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
        enrol_get_plugin('manual')->unenrol_user($enrol, $teacher->id);
        process_autosync::notify_no_recording($googlemeet, $event, 3);
        $recipients = array_map(static function($m) {
            return (int)$m->useridto;
        }, $sink->get_messages());
        $this->assertSame(array_map('intval', array_keys(get_admins())), $recipients);
    }
}
