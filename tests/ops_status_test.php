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

use mod_googlemeet\local\ops_status;
use mod_googlemeet\local\sync_log;
use mod_googlemeet\task\process_autosync;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/adminlib.php');

/**
 * OPS-04: structured autosync log and the admin status page data.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(ops_status::class)]
#[CoversClass(process_autosync::class)]
final class ops_status_test extends \advanced_testcase {

    /**
     * Activity record.
     *
     * @param array $fields
     * @return \stdClass
     */
    private function make_activity(array $fields = []): \stdClass {
        global $DB;
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Curso C1']);
        $gm = $this->getDataGenerator()->create_module('googlemeet', ['course' => $course->id, 'name' => 'Sala']);
        if ($fields) {
            $DB->update_record('googlemeet', (object)(['id' => $gm->id] + $fields));
        }
        return $DB->get_record('googlemeet', ['id' => $gm->id]);
    }

    /**
     * Insert a googlemeet_events row.
     *
     * @param int $googlemeetid
     * @param array $fields
     * @return int
     */
    private function add_event(int $googlemeetid, array $fields): int {
        global $DB;
        return (int)$DB->insert_record('googlemeet_events', (object)($fields + [
            'googlemeetid' => $googlemeetid, 'eventdate' => time() - 3 * HOURSECS, 'duration' => HOURSECS,
            'timemodified' => time(), 'autosynced' => 0, 'syncattempts' => 0, 'nextsyncattempt' => 0,
        ]));
    }

    /**
     * A failing autosync writes a structured line and a log row the admin page lists.
     */
    public function test_autosync_failure_is_logged_and_listed(): void {
        $this->resetAfterTest();
        $gm = $this->make_activity(['autosynchours' => 1, 'creatoremail' => 'nadie@example.com']);
        $this->add_event((int)$gm->id, ['eventdate' => time() - 5 * HOURSECS]);
        $healthy = $this->make_activity();

        ob_start();
        (new process_autosync())->execute();
        $output = ob_get_clean();

        $this->assertMatchesRegularExpression(
            '/mod_googlemeet autosync: activity=' . $gm->id . ' result=error .*permanent=[0-9]+.*detail="creator account missing"/',
            $output);
        $row = sync_log::latest((int)$gm->id, sync_log::KIND_AUTO);
        $this->assertSame(sync_log::STATUS_ERROR, $row->status);

        $problems = ops_status::autosync_problems();
        $this->assertArrayHasKey($gm->id, $problems);
        $this->assertArrayNotHasKey($healthy->id, $problems);
        $this->assertSame(1, $problems[$gm->id]->failedruns);
        $this->assertSame('Curso C1', $problems[$gm->id]->coursename);
        $this->assertNotEmpty($problems[$gm->id]->cmid);
    }

    /**
     * Events table history: retrying and exhausted sessions; old failures fall out of the window.
     */
    public function test_autosync_problems_from_events(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('maxsyncattempts', 3, 'googlemeet');
        $now = time();

        $retrying = $this->make_activity();
        $this->add_event((int)$retrying->id, ['syncattempts' => 1, 'nextsyncattempt' => $now + HOURSECS]);

        $exhausted = $this->make_activity();
        $this->add_event((int)$exhausted->id, ['syncattempts' => 3, 'autosynced' => $now - DAYSECS]);

        $old = $this->make_activity();
        $this->add_event((int)$old->id, ['syncattempts' => 3, 'autosynced' => $now - 10 * DAYSECS]);
        $logid = sync_log::start((int)$old->id, sync_log::KIND_AUTO, sync_log::STATUS_RUNNING, $now - 8 * DAYSECS);
        sync_log::finish($logid, sync_log::STATUS_ERROR, null, 'old');

        // Exhausted, but the last autosync in the window succeeded: not a problem.
        $recovered = $this->make_activity();
        $this->add_event((int)$recovered->id, ['syncattempts' => 3, 'autosynced' => $now - DAYSECS]);
        $logid = sync_log::start((int)$recovered->id, sync_log::KIND_AUTO, sync_log::STATUS_RUNNING);
        sync_log::finish($logid, sync_log::STATUS_SUCCESS);

        $problems = ops_status::autosync_problems($now);
        $this->assertSame(1, $problems[$retrying->id]->retrying);
        $this->assertSame(1, $problems[$exhausted->id]->exhausted);
        $this->assertArrayNotHasKey($old->id, $problems);
        $this->assertArrayNotHasKey($recovered->id, $problems);
    }

    /**
     * Stuck AI analyses: processing > 1 h, pending > 6 h (not permanent failures, not recent ones).
     */
    public function test_stuck_ai_analyses(): void {
        global $DB;
        $this->resetAfterTest();
        $gm = $this->make_activity();
        $now = time();
        $ids = [];
        foreach ([['processing', $now - 2 * HOURSECS, 0], ['processing', $now - 60, 0], ['pending', $now - 7 * HOURSECS, 0],
                ['pending', $now - 7 * HOURSECS, 99], ['completed', $now - 30 * DAYSECS, 0]] as $i => [$status, $time, $retry]) {
            $rid = $DB->insert_record('googlemeet_recordings', (object)[
                'googlemeetid' => $gm->id, 'recordingid' => 'r' . $i, 'name' => 'Clase ' . $i, 'createdtime' => $now,
                'duration' => '1:00', 'webviewlink' => '', 'visible' => 1, 'deleted' => 0, 'timemodified' => $now,
            ]);
            $ids[$i] = $DB->insert_record('googlemeet_ai_analysis', (object)[
                'recordingid' => $rid, 'status' => $status, 'retrycount' => $retry, 'nextretry' => 0,
                'timecreated' => $time, 'timemodified' => $time,
            ]);
        }
        $stuck = ops_status::stuck_ai_analyses($now);
        $this->assertEqualsCanonicalizing([$ids[0], $ids[2]], array_map('intval', array_keys($stuck)));
        $this->assertSame('Clase 0', $stuck[$ids[0]]->recordingname);
    }

    /**
     * Last sync per activity carries the latest manual and auto run; calendar failures are listed.
     */
    public function test_last_syncs_and_calendar_failures(): void {
        $this->resetAfterTest();
        $never = $this->make_activity();
        $synced = $this->make_activity(['lastsync' => time() - HOURSECS]);
        $id = sync_log::start((int)$synced->id, sync_log::KIND_MANUAL, sync_log::STATUS_RUNNING);
        sync_log::finish($id, sync_log::STATUS_ERROR, null, 'x');
        $id = sync_log::start((int)$synced->id, sync_log::KIND_MANUAL, sync_log::STATUS_RUNNING);
        sync_log::finish($id, sync_log::STATUS_SUCCESS);
        $id = sync_log::start((int)$synced->id, sync_log::KIND_CALENDAR_DELETE, sync_log::STATUS_RUNNING);
        sync_log::finish($id, sync_log::STATUS_ERROR, null, '403: Forbidden');

        $rows = array_values(ops_status::last_syncs());
        $this->assertSame((int)$never->id, (int)$rows[0]->id, 'Never-synced activities come first.');
        $this->assertSame(sync_log::STATUS_SUCCESS, $rows[1]->manualstatus);
        $this->assertSame('', $rows[1]->autostatus);

        $failures = array_values(ops_status::calendar_failures());
        $this->assertCount(1, $failures);
        $this->assertSame('403: Forbidden', $failures[0]->message);
        $this->assertSame(sync_log::KIND_CALENDAR_DELETE, $failures[0]->kind);
    }

    /**
     * The admin page is registered for site admins.
     */
    public function test_admin_page_registered(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $page = \admin_get_root(true, false)->locate('mod_googlemeet_status');
        $this->assertInstanceOf(\admin_externalpage::class, $page);
        $this->assertSame(['moodle/site:config'], $page->req_capability);
    }
}
