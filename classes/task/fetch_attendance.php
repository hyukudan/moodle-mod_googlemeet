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

use mod_googlemeet\local\attendance\scope;
use mod_googlemeet\local\attendance\service;
use mod_googlemeet\local\attendance\source;

/**
 * Read real attendance of finished sessions from the Google Meet REST API (ANA-03).
 *
 * Does nothing unless the site setting googlemeet/attendanceenabled is on; only activities that
 * opted in are processed. Calls run with the Google token of the room organiser (creatoremail),
 * because the API only lists conferences organised by the authenticated account.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fetch_attendance extends \core\task\scheduled_task {

    /**
     * Task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('fetch_attendance_task', 'mod_googlemeet');
    }

    /**
     * Execute.
     *
     * @return void
     */
    public function execute() {
        if (!scope::site_enabled()) {
            return;
        }
        $lock = \core\lock\lock_config::get_lock_factory('mod_googlemeet_attendance')->get_lock('fetch_attendance', 0);
        if (!$lock) {
            mtrace('mod_googlemeet attendance: another run holds the lock; skipping.');
            return;
        }
        try {
            $this->run(time());
        } finally {
            $lock->release();
        }
    }

    /**
     * Queue and process due sessions.
     *
     * @param int $now Current time.
     * @return void
     */
    public function run(int $now): void {
        global $DB;

        $queued = service::queue_due_sessions($now);
        if ($queued) {
            mtrace("mod_googlemeet attendance: {$queued} session(s) queued.");
        }

        foreach (service::get_due($now) as $googlemeetid => $rows) {
            $googlemeet = $DB->get_record('googlemeet', ['id' => $googlemeetid]);
            if (!$googlemeet) {
                continue;
            }
            $this->process_activity($googlemeet, $rows, $now);
        }
    }

    /**
     * Process the due sessions of one activity as its organiser.
     *
     * @param \stdClass $googlemeet Activity record.
     * @param \stdClass[] $rows Due sync rows.
     * @param int $now Current time.
     * @return void
     */
    protected function process_activity(\stdClass $googlemeet, array $rows, int $now): void {
        global $DB;

        $organiser = $this->get_organiser($googlemeet);
        if (!$organiser) {
            foreach ($rows as $row) {
                service::record_outcome($row, service::STATUS_ERROR, [],
                    'No single active Moodle user with the organiser e-mail.', $now);
            }
            return;
        }

        $impersonation = \mod_googlemeet\local\impersonation::begin($organiser);
        try {
            $source = $this->get_source();
            if (!$source) {
                foreach ($rows as $row) {
                    service::record_outcome($row, service::STATUS_SCOPE, [],
                        'The organiser is not linked to Google.', $now);
                }
                return;
            }
            foreach ($rows as $row) {
                $status = service::process($googlemeet, $row, $source, $now);
                mtrace("mod_googlemeet attendance: activity #{$googlemeet->id} session #{$row->eventid}: {$status}.");
                if ($status === service::STATUS_SCOPE) {
                    scope::mark_missing((int)$organiser->id);
                }
            }
        } finally {
            \mod_googlemeet\local\impersonation::end($impersonation);
        }
    }

    /**
     * The Moodle user behind creatoremail (only when exactly one active user has it).
     *
     * @param \stdClass $googlemeet Activity record.
     * @return \stdClass|null
     */
    protected function get_organiser(\stdClass $googlemeet): ?\stdClass {
        global $DB;

        $email = trim((string)($googlemeet->creatoremail ?? ''));
        if ($email === '') {
            return null;
        }
        $users = $DB->get_records_select('user', 'LOWER(email) = LOWER(:email) AND deleted = 0 AND suspended = 0',
            ['email' => $email], 'id', '*', 0, 2);
        return count($users) === 1 ? reset($users) : null;
    }

    /**
     * Attendance source for the current (impersonated) user, null when not linked to Google.
     *
     * @return source|null
     */
    protected function get_source(): ?source {
        $client = new \mod_googlemeet\client();
        if (!$client->enabled || !$client->check_login()) {
            return null;
        }
        return $client->get_attendance_source();
    }
}
