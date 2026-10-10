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

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;
use mod_googlemeet\local\attendance\meet_api_source;
use mod_googlemeet\local\attendance\report;
use mod_googlemeet\local\attendance\scope;
use mod_googlemeet\local\attendance\service;
use mod_googlemeet\tests\attendance_source_double;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/lib.php');
require_once($CFG->dirroot . '/mod/googlemeet/tests/fixtures/attendance_source_double.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Tests for real attendance from the Google Meet REST API (ANA-03), with a test double source.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(service::class)]
#[CoversClass(scope::class)]
#[CoversClass(report::class)]
#[CoversClass(meet_api_source::class)]
#[CoversClass(\mod_googlemeet\local\attendance\privacy::class)]
#[CoversClass(\mod_googlemeet\task\fetch_attendance::class)]
final class attendance_test extends \advanced_testcase {

    /** @var \stdClass */
    private $course;
    /** @var \stdClass */
    private $googlemeet;
    /** @var \stdClass */
    private $cm;
    /** @var \stdClass */
    private $teacher;
    /** @var \stdClass[] */
    private $students = [];
    /** @var int Session start. */
    private $start;
    /** @var int Session id. */
    private $eventid;

    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('attendanceenabled', 1, 'googlemeet');

        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course();
        $this->teacher = $gen->create_and_enrol($this->course, 'editingteacher', ['email' => 'organiser@example.com']);
        $this->students['ana'] = $gen->create_and_enrol($this->course, 'student',
            ['firstname' => 'Ana', 'lastname' => 'García López']);
        $this->students['luis'] = $gen->create_and_enrol($this->course, 'student',
            ['firstname' => 'Luis', 'lastname' => 'Pérez']);
        $this->students['juan1'] = $gen->create_and_enrol($this->course, 'student',
            ['firstname' => 'Juan', 'lastname' => 'Martín']);
        $this->students['juan2'] = $gen->create_and_enrol($this->course, 'student',
            ['firstname' => 'Juan', 'lastname' => 'Martín']);

        $this->googlemeet = $gen->create_module('googlemeet', ['course' => $this->course->id,
            'url' => 'https://meet.google.com/abc-defg-hij']);
        $DB->update_record('googlemeet', (object)['id' => $this->googlemeet->id, 'attendanceenabled' => 1,
            'creatoremail' => 'organiser@example.com', 'url' => 'https://meet.google.com/abc-defg-hij']);
        $this->googlemeet = $DB->get_record('googlemeet', ['id' => $this->googlemeet->id]);
        $this->cm = get_coursemodule_from_instance('googlemeet', $this->googlemeet->id, $this->course->id, false, MUST_EXIST);

        $this->start = time() - 3 * HOURSECS;
        $this->eventid = $this->create_session($this->start);
    }

    /**
     * Insert a session of one hour.
     *
     * @param int $start Start.
     * @return int
     */
    private function create_session(int $start): int {
        global $DB;
        return (int)$DB->insert_record('googlemeet_events', (object)[
            'googlemeetid' => $this->googlemeet->id, 'eventdate' => $start, 'duration' => HOURSECS, 'timemodified' => time(),
        ]);
    }

    /**
     * Source double with two conferences for the session starting at $s.
     *
     * @param int $s Start.
     * @return attendance_source_double
     */
    private function double(int $s): attendance_source_double {
        $source = new attendance_source_double();
        $source->conferences = [
            ['name' => 'conferenceRecords/c1', 'start' => $s, 'end' => $s + 3600],
            ['name' => 'conferenceRecords/c2', 'start' => $s + 3500, 'end' => $s + 4000],
        ];
        $source->participants['conferenceRecords/c1'] = [
            ['type' => 'signedin', 'displayname' => 'Ana García', 'googleuserid' => 'users/1', 'email' => '',
                'sessions' => [[$s, $s + 1800], [$s + 2400, $s + 3600]]],
            ['type' => 'signedin', 'displayname' => 'luis perez', 'googleuserid' => 'users/2', 'email' => '',
                'sessions' => [[$s, $s + 3600]]],
            ['type' => 'signedin', 'displayname' => 'Juan Martín', 'googleuserid' => 'users/3', 'email' => '',
                'sessions' => [[$s + 60, $s + 600]]],
            ['type' => 'anonymous', 'displayname' => 'Invitado', 'googleuserid' => '', 'email' => '',
                'sessions' => [[$s + 100, $s + 200]]],
            ['type' => 'phone', 'displayname' => '+34 600 ***', 'googleuserid' => '', 'email' => '',
                'sessions' => [[$s + 100, $s + 400]]],
        ];
        $source->participants['conferenceRecords/c2'] = [
            ['type' => 'signedin', 'displayname' => 'luis perez', 'googleuserid' => 'users/2', 'email' => '',
                'sessions' => [[$s + 3500, $s + 4000]]],
        ];
        return $source;
    }

    /**
     * Queue + return the sync row of the main session.
     *
     * @return \stdClass
     */
    private function sync_row(int $eventid = 0): \stdClass {
        global $DB;
        $eventid = $eventid ?: $this->eventid;
        service::request_refetch((int)$this->googlemeet->id, $eventid);
        return $DB->get_record_sql('SELECT s.*, ge.eventdate, ge.duration FROM {googlemeet_attendance_sync} s
            JOIN {googlemeet_events} ge ON ge.id = s.eventid WHERE s.eventid = ?', [$eventid], MUST_EXIST);
    }

    public function test_helpers(): void {
        $this->assertSame(0, service::union_seconds([]));
        $this->assertSame(150, service::union_seconds([[0, 100], [50, 120], [200, 230]]));
        $this->assertSame(1700000000, meet_api_source::parse_time('2023-11-14T22:13:20.123456789Z'));
        $this->assertSame(1700000000, meet_api_source::parse_time('2023-11-14T22:13:20Z'));
        $this->assertSame(0, meet_api_source::parse_time(''));
        $this->assertTrue(meet_api_source::is_scope_error('403: Request had insufficient authentication scopes.'));
        $this->assertFalse(meet_api_source::is_scope_error(
            '403: Google Meet REST API has not been used in project 123 before or it is disabled.'));
        $this->assertFalse(meet_api_source::is_scope_error('500: Internal error'));
        $this->assertSame('jose maria nunez', service::normalise_name('  José-María  Núñez '));

        $names = [1 => ['ana garcia lopez', 'garcia lopez ana', 'first' => 'ana'],
            2 => ['juan martin', 'first' => 'juan'], 3 => ['juan martin', 'first' => 'juan'],
            4 => ['maria jose ruiz perez', 'ruiz perez maria jose', 'first' => 'maria jose']];
        $this->assertSame(1, service::match_name('Ana García', $names));
        $this->assertSame(1, service::match_name('ANA GARCIA LOPEZ', $names));
        $this->assertSame(0, service::match_name('Juan Martín', $names));
        $this->assertSame(0, service::match_name('Ana', $names));
        // A compound first name alone is not enough: it must include at least one surname.
        $this->assertSame(0, service::match_name('María José', $names));
        $this->assertSame(4, service::match_name('María José Ruiz', $names));
        // Surname first only matches exactly, never by prefix.
        $this->assertSame(0, service::match_name('García Ana', $names));
    }

    public function test_process_stores_matched_and_unmatched_participants(): void {
        global $DB;

        $source = $this->double($this->start);
        $status = service::process($this->googlemeet, $this->sync_row(), $source, $this->start + 5 * HOURSECS);
        $this->assertSame(service::STATUS_DONE, $status);

        // The query is scoped to the meeting code with a margin around the session.
        $this->assertSame(['conferences', 'abc-defg-hij', $this->start - HOURSECS, $this->start + 2 * HOURSECS],
            $source->calls[0]);

        $rows = $DB->get_records('googlemeet_attendance', ['eventid' => $this->eventid]);
        $this->assertCount(5, $rows);
        $byuser = [];
        foreach ($rows as $row) {
            $byuser[$row->userid ? (int)$row->userid : $row->displayname] = $row;
        }
        $ana = $byuser[$this->students['ana']->id];
        $this->assertSame('name', $ana->matchedby);
        $this->assertEquals(3000, $ana->durationseconds);
        $this->assertEquals(2, $ana->sessions);
        $this->assertEquals($this->start, $ana->timejoined);
        $this->assertEquals($this->start + 3600, $ana->timeleft);

        // Two conferences of the same session merge into one row; overlapping time counts once.
        $luis = $byuser[$this->students['luis']->id];
        $this->assertEquals(4000, $luis->durationseconds);
        $this->assertEquals(2, $luis->sessions);

        // Ambiguous name, anonymous and phone participants stay unmatched.
        $this->assertEquals(0, $byuser['Juan Martín']->userid);
        $this->assertSame('anonymous', $byuser['Invitado']->participanttype);
        $this->assertSame('phone', $byuser['+34 600 ***']->participanttype);

        $sync = $DB->get_record('googlemeet_attendance_sync', ['eventid' => $this->eventid]);
        $this->assertSame(service::STATUS_DONE, $sync->status);
        $this->assertEquals(2, $sync->conferences);
        $this->assertEquals(5, $sync->participants);
        $this->assertEquals(2, $sync->matched);
    }

    public function test_manual_link_is_remembered_for_next_sessions(): void {
        global $DB;

        service::process($this->googlemeet, $this->sync_row(), $this->double($this->start), time());
        $juanrow = $DB->get_record('googlemeet_attendance', ['eventid' => $this->eventid, 'googleuserid' => 'users/3']);
        service::link_manually($this->googlemeet, (int)$juanrow->id, (int)$this->students['juan2']->id);

        // Refetching the same session keeps the manual link.
        service::process($this->googlemeet, $this->sync_row(), $this->double($this->start), time());
        $juanrow = $DB->get_record('googlemeet_attendance', ['eventid' => $this->eventid, 'googleuserid' => 'users/3']);
        $this->assertEquals($this->students['juan2']->id, $juanrow->userid);

        // Next session: the Google id is matched automatically.
        $start2 = $this->start - DAYSECS;
        $event2 = $this->create_session($start2);
        service::process($this->googlemeet, $this->sync_row($event2), $this->double($start2), time());
        $row = $DB->get_record('googlemeet_attendance', ['eventid' => $event2, 'googleuserid' => 'users/3']);
        $this->assertEquals($this->students['juan2']->id, $row->userid);
        $this->assertSame('googleuser', $row->matchedby);

        // A student cannot appear twice in one session.
        $anon = $DB->get_record('googlemeet_attendance', ['eventid' => $event2, 'participanttype' => 'anonymous']);
        $this->expectException(\moodle_exception::class);
        service::link_manually($this->googlemeet, (int)$anon->id, (int)$this->students['ana']->id);
    }

    /**
     * A name match is not remembered for later sessions, and a teacher can undo any match for good.
     */
    public function test_name_match_not_remembered_and_unlink(): void {
        global $DB;

        service::process($this->googlemeet, $this->sync_row(), $this->double($this->start), time());
        $anarow = $DB->get_record('googlemeet_attendance', ['eventid' => $this->eventid, 'googleuserid' => 'users/1']);
        $this->assertSame('name', $anarow->matchedby);

        // Next session the same Google id shows up with another name: no automatic match from memory.
        $start2 = $this->start - DAYSECS;
        $event2 = $this->create_session($start2);
        $double = $this->double($start2);
        $double->participants['conferenceRecords/c1'][0]['displayname'] = 'Invitada';
        service::process($this->googlemeet, $this->sync_row($event2), $double, time());
        $row = $DB->get_record('googlemeet_attendance', ['eventid' => $event2, 'googleuserid' => 'users/1']);
        $this->assertEquals(0, $row->userid);

        // The teacher undoes the automatic match; re-fetching keeps it unmatched.
        service::link_manually($this->googlemeet, (int)$anarow->id, 0);
        service::process($this->googlemeet, $this->sync_row(), $this->double($this->start), time());
        $anarow = $DB->get_record('googlemeet_attendance', ['eventid' => $this->eventid, 'googleuserid' => 'users/1']);
        $this->assertEquals(0, $anarow->userid);
        $this->assertSame(service::MATCH_UNLINKED, $anarow->matchedby);
        $rows = report::session_rows($this->googlemeet, $this->eventid);
        $kinds = array_column($rows, 'kind', 'attendanceid');
        $this->assertSame(report::ROW_UNMATCHED, $kinds[$anarow->id]);
    }

    /**
     * The export hides e-mails unless they are an identity field the user may see, and neutralises formulas.
     */
    public function test_export_identity_and_formulas(): void {
        $rows = [['kind' => report::ROW_UNMATCHED, 'name' => '=HYPERLINK("http://x","y")', 'email' => 'a@b.c',
            'meetname' => '@SUM(1)', 'timejoined' => 0, 'timeleft' => 0, 'durationseconds' => 60, 'sessions' => 1]];
        $context = \context_module::instance($this->cm->id);

        set_config('showuseridentity', '');
        $table = report::export_table($rows, $context);
        $this->assertArrayNotHasKey('email', $table['columns']);
        $this->assertCount(count($table['columns']), $table['rows'][0]);
        $this->assertSame("'=HYPERLINK(\"http://x\",\"y\")", $table['rows'][0][0]);
        $this->assertSame("'@SUM(1)", $table['rows'][0][2]);

        set_config('showuseridentity', 'email');
        $table = report::export_table($rows, $context);
        $this->assertArrayHasKey('email', $table['columns']);
        $this->assertSame('a@b.c', $table['rows'][0][1]);
    }

    /**
     * Changing the schedule never drops a past session that has attendance.
     */
    public function test_schedule_change_keeps_sessions_with_attendance(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/googlemeet/locallib.php');

        service::process($this->googlemeet, $this->sync_row(), $this->double($this->start), time());
        $empty = $this->create_session($this->start - 2 * DAYSECS);
        $future = $this->create_session(time() + 2 * DAYSECS);

        // The new schedule generates none of the old dates (e.g. the start time moved).
        $form = clone $this->googlemeet;
        $form->coursemodule = $this->cm->id;
        googlemeet_merge_events($form, []);

        $this->assertTrue($DB->record_exists('googlemeet_events', ['id' => $this->eventid]));
        $this->assertTrue($DB->record_exists('googlemeet_attendance', ['eventid' => $this->eventid]));
        $this->assertFalse($DB->record_exists('googlemeet_events', ['id' => $empty]));
        $this->assertFalse($DB->record_exists('googlemeet_events', ['id' => $future]));
        $this->assertNotEmpty(service::get_sessions((int)$this->googlemeet->id, time()));
    }

    public function test_scope_error_and_relink_notice(): void {
        global $DB;

        $context = \context_module::instance($this->cm->id);
        // The organiser has not linked Google with the Meet scope yet.
        $this->assertTrue(scope::needs_relink($this->googlemeet, $context, $this->teacher));
        scope::after_login((int)$this->teacher->id);
        $this->assertTrue(scope::granted((int)$this->teacher->id));
        $this->assertFalse(scope::needs_relink($this->googlemeet, $context, $this->teacher));
        // Students never see it.
        $this->assertFalse(scope::needs_relink($this->googlemeet, $context, $this->students['ana']));

        $source = new attendance_source_double();
        $source->scopeerror = true;
        $now = time();
        $this->assertSame(service::STATUS_SCOPE, service::process($this->googlemeet, $this->sync_row(), $source, $now));
        $sync = $DB->get_record('googlemeet_attendance_sync', ['eventid' => $this->eventid]);
        $this->assertEquals($now + service::RETRY_INTERVAL, $sync->nextattempt);
        $this->assertTrue(scope::needs_relink($this->googlemeet, $context, $this->teacher));

        // Unlinking removes the stored Google tokens and the grant.
        $DB->insert_record('oauth2_refresh_token', (object)['userid' => $this->teacher->id,
            'issuerid' => 7, 'token' => 'x', 'scopehash' => sha1('a'), 'timecreated' => 1, 'timemodified' => 1]);
        set_config('issuerid', 7, 'googlemeet');
        scope::reset_link((int)$this->teacher->id);
        $this->assertFalse($DB->record_exists('oauth2_refresh_token', ['userid' => $this->teacher->id]));
        $this->assertFalse(scope::granted((int)$this->teacher->id));
    }

    public function test_after_login_keeps_only_newest_refresh_token(): void {
        global $DB;
        set_config('issuerid', 7, 'googlemeet');
        foreach (['old', 'new'] as $i => $token) {
            $DB->insert_record('oauth2_refresh_token', (object)['userid' => $this->teacher->id, 'issuerid' => 7,
                'token' => $token, 'scopehash' => sha1($token), 'timecreated' => $i, 'timemodified' => $i]);
        }
        scope::after_login((int)$this->teacher->id);
        $tokens = $DB->get_records('oauth2_refresh_token', ['userid' => $this->teacher->id]);
        $this->assertCount(1, $tokens);
        $this->assertSame('new', reset($tokens)->token);
    }

    public function test_nodata_and_invalid_room(): void {
        global $DB;

        $source = new attendance_source_double();
        $this->assertSame(service::STATUS_NODATA, service::process($this->googlemeet, $this->sync_row(), $source, time()));
        $this->assertEquals(1, $DB->get_field('googlemeet_attendance_sync', 'attempts', ['eventid' => $this->eventid]));

        $broken = clone $this->googlemeet;
        $broken->url = 'https://example.com/room';
        $this->assertSame(service::STATUS_ERROR, service::process($broken, $this->sync_row(), $source, time()));
        $this->assertEquals(service::MAX_ATTEMPTS,
            $DB->get_field('googlemeet_attendance_sync', 'attempts', ['eventid' => $this->eventid]));
    }

    public function test_queue_respects_site_and_activity_switches(): void {
        global $DB;
        $now = time();
        // A future session and one too old are never queued.
        $this->create_session($now + DAYSECS);
        $this->create_session($now - 30 * DAYSECS);

        set_config('attendanceenabled', 0, 'googlemeet');
        $this->assertSame(0, service::queue_due_sessions($now));

        set_config('attendanceenabled', 1, 'googlemeet');
        $DB->set_field('googlemeet', 'attendanceenabled', 0, ['id' => $this->googlemeet->id]);
        $this->assertSame(0, service::queue_due_sessions($now));

        $DB->set_field('googlemeet', 'attendanceenabled', 1, ['id' => $this->googlemeet->id]);
        $this->assertSame(1, service::queue_due_sessions($now));
        $this->assertTrue($DB->record_exists('googlemeet_attendance_sync', ['eventid' => $this->eventid]));
        $this->assertSame(0, service::queue_due_sessions($now));
        $this->assertCount(1, service::get_due($now)[$this->googlemeet->id]);
    }

    public function test_task_runs_as_organiser(): void {
        global $DB, $USER;

        $source = $this->double($this->start);
        $task = new class($source) extends \mod_googlemeet\task\fetch_attendance {
            /** @var attendance_source_double */
            public $source;
            /** @var int */
            public $runas = 0;
            /**
             * Constructor.
             * @param attendance_source_double $source Source.
             */
            public function __construct($source) {
                $this->source = $source;
            }
            protected function get_source(): ?\mod_googlemeet\local\attendance\source {
                global $USER;
                $this->runas = (int)$USER->id;
                return $this->source;
            }
        };
        $before = (int)$USER->id;
        ob_start();
        $task->run(time());
        ob_end_clean();

        $this->assertEquals($this->teacher->id, $task->runas);
        $this->assertEquals($before, $USER->id);
        $this->assertSame(service::STATUS_DONE,
            $DB->get_field('googlemeet_attendance_sync', 'status', ['eventid' => $this->eventid]));
    }

    public function test_report_rows_and_export(): void {
        service::process($this->googlemeet, $this->sync_row(), $this->double($this->start), time());
        $rows = report::session_rows($this->googlemeet, $this->eventid);

        $kinds = array_count_values(array_column($rows, 'kind'));
        $this->assertSame(2, $kinds[report::ROW_PRESENT]);
        $this->assertSame(3, $kinds[report::ROW_UNMATCHED]);
        // Both "Juan Martín" are absent; the teacher (staff) is not listed as absent.
        $this->assertSame(2, $kinds[report::ROW_ABSENT]);
        $this->assertNotContains((int)$this->teacher->id, array_column($rows, 'userid'));
        $this->assertSame(report::ROW_PRESENT, $rows[0]['kind']);

        // Without a context there is no e-mail column (identity fields are checked per context).
        $table = report::export_table($rows);
        $this->assertCount(7, $table['columns']);
        $this->assertCount(7, $table['rows']);
        $table = report::export_table($rows, \context_module::instance($this->cm->id));
        $this->assertCount(count($table['columns']), $table['rows'][0]);
    }

    public function test_privacy_export_and_delete(): void {
        global $DB;

        service::process($this->googlemeet, $this->sync_row(), $this->double($this->start), time());
        $ana = $this->students['ana'];
        $context = \context_module::instance($this->cm->id);

        $contextlist = \mod_googlemeet\privacy\provider::get_contexts_for_userid((int)$ana->id);
        $this->assertContains((int)$context->id, array_map('intval', $contextlist->get_contextids()));

        $approved = new approved_contextlist($ana, 'mod_googlemeet', [$context->id]);
        \mod_googlemeet\privacy\provider::export_user_data($approved);
        $data = writer::with_context($context)->get_data([get_string('privacy:attendance', 'googlemeet')]);
        $this->assertCount(1, $data->sessions);
        $this->assertSame('Ana García', $data->sessions[0]['meetname']);

        \mod_googlemeet\privacy\provider::delete_data_for_user($approved);
        $this->assertFalse($DB->record_exists('googlemeet_attendance', ['userid' => $ana->id]));
        $this->assertTrue($DB->record_exists('googlemeet_attendance', ['userid' => $this->students['luis']->id]));

        \mod_googlemeet\privacy\provider::delete_data_for_all_users_in_context($context);
        $this->assertFalse($DB->record_exists('googlemeet_attendance', ['googlemeetid' => $this->googlemeet->id]));
    }

    public function test_scopes_follow_site_setting(): void {
        set_config('attendanceenabled', 0, 'googlemeet');
        $this->assertStringNotContainsString(scope::MEET_SCOPE, client::get_scopes());
        set_config('attendanceenabled', 1, 'googlemeet');
        $this->assertStringContainsString(scope::MEET_SCOPE, client::get_scopes());
        $this->assertStringContainsString('https://www.googleapis.com/auth/drive', client::get_scopes());
    }

    public function test_instance_deletion_removes_attendance(): void {
        global $DB;
        service::process($this->googlemeet, $this->sync_row(), $this->double($this->start), time());
        course_delete_module($this->cm->id);
        $this->assertFalse($DB->record_exists('googlemeet_attendance', ['googlemeetid' => $this->googlemeet->id]));
        $this->assertFalse($DB->record_exists('googlemeet_attendance_sync', ['googlemeetid' => $this->googlemeet->id]));
    }

    public function test_backup_restore_with_users_keeps_attendance(): void {
        global $DB, $USER;

        service::process($this->googlemeet, $this->sync_row(), $this->double($this->start), time());
        set_config('backup_general_users', 1, 'backup');

        $bc = new \backup_controller(\backup::TYPE_1COURSE, $this->course->id, \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO, \backup::MODE_GENERAL, $USER->id);
        $bc->get_plan()->get_setting('users')->set_value(true);
        $bc->execute_plan();
        $file = $bc->get_results()['backup_destination'];
        $bc->destroy();

        $backupdir = 'googlemeet-ana03-' . random_string(6);
        $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), make_backup_temp_directory($backupdir));
        $newcourseid = \restore_dbops::create_new_course('Copy', 'ana03copy', $this->course->category);
        $rc = new \restore_controller($backupdir, $newcourseid, \backup::INTERACTIVE_NO, \backup::MODE_GENERAL,
            $USER->id, \backup::TARGET_NEW_COURSE);
        $rc->get_plan()->get_setting('users')->set_value(true);
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        $new = $DB->get_record('googlemeet', ['course' => $newcourseid], '*', MUST_EXIST);
        $this->assertEquals(1, $new->attendanceenabled);
        $rows = $DB->get_records('googlemeet_attendance', ['googlemeetid' => $new->id]);
        $this->assertCount(5, $rows);
        $eventids = array_unique(array_column($rows, 'eventid'));
        $this->assertCount(1, $eventids);
        $this->assertTrue($DB->record_exists('googlemeet_events', ['id' => reset($eventids), 'googlemeetid' => $new->id]));
        $this->assertSame(service::STATUS_DONE,
            $DB->get_field('googlemeet_attendance_sync', 'status', ['eventid' => reset($eventids)]));
        $userids = array_filter(array_map('intval', array_column($rows, 'userid')));
        $this->assertEqualsCanonicalizing([(int)$this->students['ana']->id, (int)$this->students['luis']->id], $userids);
    }
}
