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

use core_external\external_api;
use mod_googlemeet\external\get_sync_status;
use mod_googlemeet\external\request_sync;
use mod_googlemeet\local\sync_log;
use mod_googlemeet\local\sync_manager;
use mod_googlemeet\task\sync_recordings_task;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * PERF-02: manual "Sync with Google Drive" runs in an adhoc task and is polled.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(sync_manager::class)]
#[CoversClass(sync_log::class)]
#[CoversClass(sync_recordings_task::class)]
#[CoversClass(request_sync::class)]
#[CoversClass(get_sync_status::class)]
final class sync_manager_test extends \advanced_testcase {

    /** @var array User ids the fake runner ran as. */
    private array $ranas = [];

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->ranas = [];
    }

    protected function tearDown(): void {
        sync_manager::set_runner(null);
        parent::tearDown();
    }

    /**
     * Course with an activity and an editing teacher.
     *
     * @return array [googlemeet record, cm, teacher, course]
     */
    private function make_activity(): array {
        global $DB;
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $gm = $this->getDataGenerator()->create_module('googlemeet', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('googlemeet', $gm->id, $course->id, false, MUST_EXIST);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        return [$DB->get_record('googlemeet', ['id' => $gm->id]), $cm, $teacher, $course];
    }

    /**
     * Fake Drive sync returning $stats (or throwing).
     *
     * @param array|\Throwable $result
     * @return void
     */
    private function fake_runner($result): void {
        sync_manager::set_runner(function(\stdClass $googlemeet, \stdClass $user) use ($result) {
            global $USER, $DB;
            $this->ranas[] = (int)$USER->id;
            if ($result instanceof \Throwable) {
                throw $result;
            }
            $DB->set_field('googlemeet', 'lastsync', time(), ['id' => $googlemeet->id]);
            return $result;
        });
    }

    /**
     * Two clicks in a row queue a single task; the request itself never calls Google.
     */
    public function test_request_queues_once(): void {
        [$gm, , $teacher] = $this->make_activity();
        $this->fake_runner(['inserted' => 0]);
        $this->setUser($teacher);

        $this->assertTrue(sync_manager::request($gm, (int)$teacher->id));
        $this->assertFalse(sync_manager::request($gm, (int)$teacher->id));
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(sync_recordings_task::class));
        $this->assertEmpty($this->ranas);

        $status = sync_manager::get_status($gm);
        $this->assertSame(sync_log::STATUS_QUEUED, $status['status']);
        $this->assertTrue($status['active']);
        $this->assertSame(get_string('sync_status_queued', 'googlemeet'), $status['message']);
    }

    /**
     * The task runs the sync as the requesting teacher and stores a result the page can show.
     */
    public function test_task_runs_as_teacher_and_reports_result(): void {
        [$gm, , $teacher] = $this->make_activity();
        $this->fake_runner(['inserted' => 2, 'updated' => 0, 'deleted' => 0, 'trashed' => 0, 'restored' => 0, 'found' => 5]);
        $this->setUser($teacher);
        sync_manager::request($gm, (int)$teacher->id);

        $this->setAdminUser();
        ob_start();
        $this->runAdhocTasks(sync_recordings_task::class);
        $output = ob_get_clean();

        $this->assertSame([(int)$teacher->id], $this->ranas);
        $this->assertStringContainsString("kind=manual activity={$gm->id} user={$teacher->id} result=success", $output);
        $status = sync_manager::get_status($gm);
        $this->assertSame(sync_log::STATUS_SUCCESS, $status['status']);
        $this->assertFalse($status['active']);
        $this->assertTrue($status['haschanges']);
        $this->assertStringContainsString(get_string('sync_new_recordings', 'googlemeet', 2), $status['message']);
        $this->assertNotSame(get_string('never', 'googlemeet'), $status['lastsync']);

        // Finished: a new request is accepted again.
        $this->assertTrue(sync_manager::request($gm, (int)$teacher->id));
    }

    /**
     * Errors are reported, not thrown (the task does not retry a broken Google link forever).
     */
    public function test_task_error_is_reported(): void {
        [$gm, , $teacher] = $this->make_activity();
        $this->fake_runner(new \moodle_exception('sync_status_notlinked', 'googlemeet'));
        sync_manager::request($gm, (int)$teacher->id);

        ob_start();
        $this->runAdhocTasks(sync_recordings_task::class);
        ob_end_clean();

        $status = sync_manager::get_status($gm);
        $this->assertSame(sync_log::STATUS_ERROR, $status['status']);
        $this->assertSame(get_string('sync_status_notlinked', 'googlemeet'), $status['message']);
        $this->assertEmpty(\core\task\manager::get_adhoc_tasks(sync_recordings_task::class));
    }

    /**
     * While autosync holds the per-activity lock the task is deferred (retried) and stays queued.
     */
    public function test_busy_lock_defers_task(): void {
        global $CFG;
        // DB (GET_LOCK) locks are re-entrant within one connection; file locks conflict in-process.
        $CFG->lock_factory = '\\core\\lock\\file_lock_factory';
        [$gm, , $teacher] = $this->make_activity();
        $this->fake_runner(['inserted' => 0]);
        sync_manager::request($gm, (int)$teacher->id);
        $logid = (int)sync_log::latest((int)$gm->id, sync_log::KIND_MANUAL)->id;

        $lock = \core\lock\lock_config::get_lock_factory('mod_googlemeet')->get_lock('sync_' . $gm->id, 0);
        $this->assertNotFalse($lock);
        try {
            ob_start();
            try {
                sync_manager::run((int)$gm->id, (int)$teacher->id, $logid);
                $this->fail('Expected the busy lock to defer the task.');
            } catch (\moodle_exception $e) {
                $this->assertSame('sync_already_running', $e->errorcode);
            } finally {
                ob_end_clean();
            }
        } finally {
            $lock->release();
        }
        $this->assertEmpty($this->ranas);
        $this->assertSame(sync_log::STATUS_QUEUED, sync_manager::get_status($gm)['status']);
    }

    /**
     * A queued sync whose task never ran stops blocking after STALE_SECONDS.
     */
    public function test_stale_request_can_be_replaced(): void {
        global $DB;
        [$gm, , $teacher] = $this->make_activity();
        sync_manager::request($gm, (int)$teacher->id);
        $old = sync_log::latest((int)$gm->id, sync_log::KIND_MANUAL);
        $DB->set_field(sync_log::TABLE, 'timequeued', time() - sync_manager::STALE_SECONDS - 10, ['id' => $old->id]);

        $status = sync_manager::get_status($gm);
        $this->assertSame(sync_log::STATUS_ERROR, $status['status']);
        $this->assertFalse($status['active']);

        $this->assertTrue(sync_manager::request($gm, (int)$teacher->id));
        $this->assertSame(sync_log::STATUS_ERROR, $DB->get_field(sync_log::TABLE, 'status', ['id' => $old->id]));
    }

    /**
     * Web services: teachers queue and poll; students cannot.
     */
    public function test_web_services(): void {
        [$gm, $cm, $teacher, $course] = $this->make_activity();
        $this->fake_runner(['found' => 0]);
        $this->setUser($teacher);

        $result = external_api::clean_returnvalue(request_sync::execute_returns(), request_sync::execute((int)$cm->id));
        $this->assertTrue($result['queued']);
        $this->assertTrue($result['active']);
        $result = external_api::clean_returnvalue(request_sync::execute_returns(), request_sync::execute((int)$cm->id));
        $this->assertFalse($result['queued']);

        $status = external_api::clean_returnvalue(get_sync_status::execute_returns(), get_sync_status::execute((int)$cm->id));
        $this->assertSame('queued', $status['status']);

        ob_start();
        $this->runAdhocTasks(sync_recordings_task::class);
        ob_end_clean();
        $this->setUser($teacher);
        $status = external_api::clean_returnvalue(get_sync_status::execute_returns(), get_sync_status::execute((int)$cm->id));
        $this->assertSame('success', $status['status']);
        $this->assertSame(get_string('sync_no_recordings_found', 'googlemeet'), $status['message']);

        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);
        $this->expectException(\required_capability_exception::class);
        request_sync::execute((int)$cm->id);
    }

    /**
     * Old log rows are purged when new ones are written.
     */
    public function test_log_retention(): void {
        global $DB;
        $old = sync_log::start(1, sync_log::KIND_AUTO, sync_log::STATUS_SUCCESS, time() - (sync_log::RETENTION_DAYS + 1) * DAYSECS);
        $this->assertTrue($DB->record_exists(sync_log::TABLE, ['id' => $old]));
        sync_log::start(1, sync_log::KIND_AUTO);
        $this->assertFalse($DB->record_exists(sync_log::TABLE, ['id' => $old]));
    }

    /**
     * Only the organiser's Google account may run the Drive sync (it trashes what its Drive lacks).
     */
    public function test_require_creator_account(): void {
        sync_manager::require_creator_account('Teacher@Example.com', 'teacher@example.com');
        foreach ([['other@example.com', 'teacher@example.com'], ['', 'teacher@example.com'], ['a@b.c', '']] as [$l, $c]) {
            try {
                sync_manager::require_creator_account($l, $c);
                $this->fail('Expected isnotcreatoremail');
            } catch (\moodle_exception $e) {
                $this->assertSame('isnotcreatoremail', $e->errorcode);
            }
        }
    }

    /**
     * Impersonation never hands one user's OAuth access token (kept in the shared cron session) to another.
     */
    public function test_impersonation_isolates_oauth_session_state(): void {
        global $SESSION, $USER;
        $a = $this->getDataGenerator()->create_user();
        $b = $this->getDataGenerator()->create_user();
        $this->setAdminUser();
        $adminid = $USER->id;
        $SESSION->{'oauth2-state-7'} = 'admin-token';

        $state = \mod_googlemeet\local\impersonation::begin($a);
        $this->assertEquals($a->id, $USER->id);
        $this->assertFalse(isset($SESSION->{'oauth2-state-7'}));
        $SESSION->{'oauth2-state-7'} = 'token-of-a';
        \mod_googlemeet\local\impersonation::end($state);
        $this->assertEquals($adminid, $USER->id);
        $this->assertSame('admin-token', $SESSION->{'oauth2-state-7'});

        $state = \mod_googlemeet\local\impersonation::begin($b);
        $this->assertFalse(isset($SESSION->{'oauth2-state-7'}), 'B must not see the token of A or of the admin');
        \mod_googlemeet\local\impersonation::end($state);
        unset($SESSION->{'oauth2-state-7'});
    }
}
