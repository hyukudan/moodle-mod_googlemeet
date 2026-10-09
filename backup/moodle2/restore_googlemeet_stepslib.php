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
 * All the steps to restore mod_googlemeet are defined here.
 *
 * @package     mod_googlemeet
 * @subpackage  backup-moodle2
 * @copyright   2020 Rone Santos <ronefel@hotmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Defines the structure step to restore one mod_googlemeet activity.
 */
class restore_googlemeet_activity_structure_step extends restore_activity_structure_step {

    /** @var bool Whether this restore includes user information. */
    protected $userinfo = false;

    /**
     * Defines the structure to be restored.
     *
     * @return restore_path_element[].
     */
    protected function define_structure() {
        $paths = array();
        $userinfo = $this->get_setting_value('userinfo');
        $this->userinfo = (bool) $userinfo;

        $paths[] = new restore_path_element('googlemeet', '/activity/googlemeet');

        $paths[] = new restore_path_element('googlemeet_event',
            '/activity/googlemeet/events/event');

        $paths[] = new restore_path_element('googlemeet_recording',
            '/activity/googlemeet/recordings/recording');

        // AI analysis (summary, key points, topics, chapters) is course content and is always
        // restored; its transcript is stripped in process_googlemeet_aianalysis() without user info.
        $paths[] = new restore_path_element('googlemeet_aianalysis',
            '/activity/googlemeet/recordings/recording/aianalysis');
        if ($userinfo) {
            $paths[] = new restore_path_element('googlemeet_recordingprogress',
                '/activity/googlemeet/recordings/recording/recordingprogresses/recordingprogress');
            $paths[] = new restore_path_element('googlemeet_recordingsub',
                '/activity/googlemeet/recordingsubs/recordingsub');
            $paths[] = new restore_path_element('googlemeet_practiceattempt',
                '/activity/googlemeet/practiceattempts/practiceattempt');
            $paths[] = new restore_path_element('googlemeet_attendance',
                '/activity/googlemeet/events/event/attendances/attendance');
        }

        $paths[] = new restore_path_element('googlemeet_holiday',
            '/activity/googlemeet/holidays/holiday');

        $paths[] = new restore_path_element('googlemeet_cancelled',
            '/activity/googlemeet/cancelleddates/cancelleddate');

        return $this->prepare_activity_structure($paths);
    }

    /**
     * Process a googlemeet restore.
     *
     * @param object $data The data in object form
     * @return void
     */
    protected function process_googlemeet($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->course = $this->get_courseid();

        // Any changes to the list of dates that needs to be rolled should be same during course restore and course reset.
        // See MDL-9367.
        $data->eventdate = $this->apply_date_offset($data->eventdate);
        $data->eventenddate = $this->apply_date_offset($data->eventenddate);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        // DAT-03: the copy must never act on the source activity's Google Calendar event. The
        // eventid is the original's event (a future Calendar patch/delete would hit it), so drop it;
        // the Meet URL is kept so the copy keeps using the same room. lastsync describes the
        // original's Drive sync, not the copy's.
        // creatoremail is intentionally kept: it identifies the Google account that owns the Meet
        // room and its Drive recordings, which the copy still uses; clearing it would make
        // autosync close every event of the copy as "no identity" (permanent failure).
        $data->eventid = null;
        $data->lastsync = null;

        // Insert the googlemeet record.
        $newitemid = $DB->insert_record('googlemeet', $data);
        // Immediately after inserting "activity" record, call this.
        $this->apply_activity_instance($newitemid);
    }

    /**
     * Process a event restore.
     *
     * @param object $data The data in object form
     * @return void
     */
    protected function process_googlemeet_event($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->googlemeetid = $this->get_new_parentid('googlemeet');
        $data->eventdate = $this->apply_date_offset($data->eventdate);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        // DAT-03: auto-sync bookkeeping. Sessions already over keep the backed-up state, so a
        // restore does not re-trigger auto-sync against old sessions. Sessions that (after the date
        // offset of a course restore) are still to come have never been synced for this copy: reset
        // them, otherwise an "autosynced" flag copied from the original would block their sync.
        $duration = (int)($data->duration ?? 0);
        if ((int)$data->eventdate + $duration > time()) {
            $data->autosynced = 0;
            $data->syncattempts = 0;
            $data->nextsyncattempt = 0;
        }

        $newitemid = $DB->insert_record('googlemeet_events', $data);
        $this->set_mapping('googlemeet_event', $oldid, $newitemid);
    }

    /**
     * Process a recording restore.
     *
     * @param object $data The data in object form
     * @return void
     */
    protected function process_googlemeet_recording($data) {
        global $DB, $CFG;

        $data = (object)$data;
        $oldid = $data->id;

        $data->googlemeetid = $this->get_new_parentid('googlemeet');
        $data->timemodified = $this->apply_date_offset($data->timemodified);
        // DAT-05: derived from the text duration (also for backups made before the column existed).
        require_once($CFG->dirroot . '/mod/googlemeet/lib.php');
        $data->durationseconds = googlemeet_recording_duration_to_seconds($data->duration ?? '') ?: null;

        // Strip participant-derived transcript data from legacy backups when restoring without
        // user information (current backups already omit these fields when userinfo is off).
        if (!$this->userinfo) {
            unset($data->transcripttext);
            unset($data->transcriptfileid);
            unset($data->notestext);
            unset($data->notesdocid);
        }

        $newitemid = $DB->insert_record('googlemeet_recordings', $data);
        $this->set_mapping('googlemeet_recording', $oldid, $newitemid);
    }

    /**
     * Process an AI analysis restore.
     *
     * @param object $data The data in object form
     * @return void
     */
    protected function process_googlemeet_aianalysis($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->recordingid = $this->get_new_parentid('googlemeet_recording');
        if (empty($data->recordingid)
                || $DB->record_exists('googlemeet_ai_analysis', ['recordingid' => $data->recordingid])) {
            return;
        }
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);
        // IA-04: backups made before the review existed hold content students could already see.
        if (!isset($data->reviewed)) {
            $data->reviewed = 1;
            $data->timereviewed = 0;
            $data->reviewedby = 0;
        }
        $data->timereviewed = empty($data->timereviewed) ? 0 : $this->apply_date_offset($data->timereviewed);
        $data->reviewedby = empty($data->reviewedby) ? 0 : (int)($this->get_mappingid('user', $data->reviewedby) ?: 0);

        // The transcript is participant-derived personal data: never restore it without user
        // information (also strips it from backups made with user data). Without user information
        // only finished analyses are copied: a pending/failed one would make the copy queue its own
        // (paid) AI run against the same Drive file.
        if (!$this->userinfo) {
            if (($data->status ?? '') !== 'completed') {
                return;
            }
            $data->transcript = null;
        }

        $newitemid = $DB->insert_record('googlemeet_ai_analysis', $data);
        $this->set_mapping('googlemeet_aianalysis', $oldid, $newitemid);
    }

    /**
     * Process a recording progress restore.
     *
     * @param object $data The data in object form
     * @return void
     */
    protected function process_googlemeet_recordingprogress($data) {
        global $DB;

        $data = (object)$data;
        $data->recordingid = $this->get_new_parentid('googlemeet_recording');
        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        if (empty($data->userid)) {
            return;
        }

        if (!$DB->record_exists('googlemeet_recording_progress',
                ['recordingid' => $data->recordingid, 'userid' => $data->userid])) {
            $DB->insert_record('googlemeet_recording_progress', $data);
        }
    }

    /**
     * Process a recording subscription restore.
     *
     * @param object $data The data in object form
     * @return void
     */
    protected function process_googlemeet_recordingsub($data) {
        global $DB;

        $data = (object)$data;
        $data->googlemeetid = $this->get_new_parentid('googlemeet');
        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->timecreated = $this->apply_date_offset($data->timecreated);

        if (empty($data->userid)) {
            return;
        }

        if (!$DB->record_exists('googlemeet_recording_subs',
                ['googlemeetid' => $data->googlemeetid, 'userid' => $data->userid])) {
            $DB->insert_record('googlemeet_recording_subs', $data);
        }
    }

    /**
     * Process a practice attempt restore (ANA-05).
     *
     * Questions are created by the root task before any activity, so their mapping exists here.
     * Attempts whose user, recording (e.g. trashed, not backed up) or question cannot be mapped
     * are skipped.
     *
     * @param object $data The data in object form
     * @return void
     */
    protected function process_googlemeet_practiceattempt($data) {
        global $DB;

        $data = (object)$data;
        $data->googlemeetid = $this->get_new_parentid('googlemeet');
        $data->recordingid = (int)$this->get_mappingid('googlemeet_recording', $data->recordingid);
        $data->questionid = (int)$this->get_mappingid('question', $data->questionid);
        $data->userid = (int)$this->get_mappingid('user', $data->userid);
        $data->timecreated = $this->apply_date_offset($data->timecreated);

        if (empty($data->recordingid) || empty($data->questionid) || empty($data->userid)) {
            return;
        }
        $DB->insert_record('googlemeet_practice_attempts', $data);
    }

    /**
     * Process an attendance restore (ANA-03).
     *
     * Unmatched participants (userid 0) keep userid 0; rows of users that cannot be mapped are
     * skipped. The session is marked as fetched so the copy does not query Google again.
     *
     * @param object $data The data in object form
     * @return void
     */
    protected function process_googlemeet_attendance($data) {
        global $DB;

        $data = (object)$data;
        $data->googlemeetid = $this->get_new_parentid('googlemeet');
        $data->eventid = $this->get_new_parentid('googlemeet_event');
        if (!empty($data->userid)) {
            $data->userid = (int)$this->get_mappingid('user', $data->userid);
            if (empty($data->userid)) {
                return;
            }
        } else {
            $data->userid = 0;
        }
        $data->timejoined = $this->apply_date_offset($data->timejoined);
        $data->timeleft = $this->apply_date_offset($data->timeleft);
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);
        $DB->insert_record('googlemeet_attendance', $data);

        if (!$DB->record_exists('googlemeet_attendance_sync', ['eventid' => $data->eventid])) {
            $DB->insert_record('googlemeet_attendance_sync', (object)[
                'googlemeetid' => $data->googlemeetid,
                'eventid' => $data->eventid,
                'status' => 'done',
                'attempts' => 1,
                'timemodified' => time(),
            ]);
        }
    }

    /**
     * Process a holiday restore.
     *
     * @param object $data The data in object form
     * @return void
     */
    protected function process_googlemeet_holiday($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->googlemeetid = $this->get_new_parentid('googlemeet');
        $data->startdate = $this->apply_date_offset($data->startdate);
        $data->enddate = $this->apply_date_offset($data->enddate);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        $newitemid = $DB->insert_record('googlemeet_holidays', $data);
        $this->set_mapping('googlemeet_holiday', $oldid, $newitemid);
    }

    /**
     * Process a cancelled date restore.
     *
     * @param object $data The data in object form
     * @return void
     */
    protected function process_googlemeet_cancelled($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->googlemeetid = $this->get_new_parentid('googlemeet');
        $data->cancelleddate = $this->apply_date_offset($data->cancelleddate);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        $newitemid = $DB->insert_record('googlemeet_cancelled', $data);
        $this->set_mapping('googlemeet_cancelled', $oldid, $newitemid);
    }

    /**
     * Defines post-execution actions.
     */
    protected function after_execute() {
        $this->add_related_files('mod_googlemeet', 'intro', null);
        $this->add_related_files('mod_googlemeet', 'attachment', null);
        $this->add_related_files('mod_googlemeet', 'recordingmaterial', 'googlemeet_recording');
    }

    /**
     * Re-bind the restored practice questions to the new activity and recordings (DAT-02).
     *
     * Runs after the whole restore plan, i.e. once core has created the question categories and
     * moved them (with their tag instances) into this activity's new module context.
     */
    protected function after_restore() {
        $context = context_module::instance($this->task->get_moduleid());
        \mod_googlemeet\question_service::remap_restored_questions(
            $context,
            (int)$this->task->get_old_moduleid(),
            function(int $oldrecordingid) {
                return $this->get_mappingid('googlemeet_recording', $oldrecordingid);
            }
        );
    }
}
