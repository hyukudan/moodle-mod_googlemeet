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
 * Backup steps for mod_googlemeet are defined here.
 *
 * @package     mod_googlemeet
 * @subpackage  backup-moodle2
 * @copyright   2020 Rone Santos <ronefel@hotmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Define the complete structure for backup, with file and id annotations.
 */
class backup_googlemeet_activity_structure_step extends backup_activity_structure_step {

    /**
     * Defines the structure of the resulting xml file.
     *
     * @return backup_nested_element The structure wrapped by the common 'activity' element.
     */
    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');

        // Replace with the attributes and final elements that the element will handle.
        $googlemeet = new backup_nested_element('googlemeet', ['id'], [
            'name',
            'originalname',
            'url',
            'creatoremail',
            'intro',
            'introformat',
            'lastsync',
            'eventdate',
            'starthour',
            'startminute',
            'endhour',
            'endminute',
            'addmultiply',
            'days',
            'period',
            'eventenddate',
            'notify',
            'minutesbefore',
            'timemodified',
            'eventid',
            'maxupcomingevents',
            'maxrecordings',
            'recordingsorder',
            'recordingfilter',
            'autosynchours'
        ]);

        $events = new backup_nested_element('events');
        // Include the auto-sync bookkeeping so a restored activity does not treat already-synced
        // events as never synced (which would re-trigger auto-sync against old sessions).
        $event = new backup_nested_element('event', ['id'], [
            'eventdate',
            'duration',
            'timemodified',
            'autosynced',
            'syncattempts',
            'nextsyncattempt'
        ]);

        // The recording transcript is participant-derived personal data, so it is only included
        // when the backup carries user information. Non-personal fields are always included.
        $recordingfields = [
            'recordingid',
            'name',
            'createdtime',
            'duration',
            'webviewlink',
            'visible',
            'timemodified'
        ];
        if ($userinfo) {
            $recordingfields[] = 'transcripttext';
            $recordingfields[] = 'transcriptfileid';
        }

        $recordings = new backup_nested_element('recordings');
        $recording = new backup_nested_element('recording', ['id'], $recordingfields);

        $recordingsubs = new backup_nested_element('recordingsubs');
        $recordingsub = new backup_nested_element('recordingsub', ['id'], [
            'userid',
            'timecreated'
        ]);

        $aianalysis = new backup_nested_element('aianalysis', ['id'], [
            'summary',
            'keypoints',
            'transcript',
            'topics',
            'language',
            'status',
            'error',
            'aimodel',
            'retrycount',
            'nextretry',
            'timecreated',
            'timemodified'
        ]);

        $holidays = new backup_nested_element('holidays');
        $holiday = new backup_nested_element('holiday', ['id'], [
            'name',
            'startdate',
            'enddate',
            'timemodified'
        ]);

        $cancelleddates = new backup_nested_element('cancelleddates');
        $cancelleddate = new backup_nested_element('cancelleddate', ['id'], [
            'cancelleddate',
            'reason',
            'timemodified'
        ]);

        // Build the tree in the order needed for restore.
        $googlemeet->add_child($events);
        $events->add_child($event);

        $googlemeet->add_child($recordings);
        $recordings->add_child($recording);
        // AI analysis includes a transcript (participant-derived personal data): only back it up
        // when the backup carries user information.
        if ($userinfo) {
            $recording->add_child($aianalysis);
            $googlemeet->add_child($recordingsubs);
            $recordingsubs->add_child($recordingsub);
        }

        $googlemeet->add_child($holidays);
        $holidays->add_child($holiday);

        $googlemeet->add_child($cancelleddates);
        $cancelleddates->add_child($cancelleddate);

        // Define the source tables for the elements.
        $googlemeet->set_source_table('googlemeet', ['id' => backup::VAR_ACTIVITYID]);

        $event->set_source_table('googlemeet_events', ['googlemeetid' => backup::VAR_PARENTID]);

        $recording->set_source_table('googlemeet_recordings', ['googlemeetid' => backup::VAR_PARENTID]);

        if ($userinfo) {
            $aianalysis->set_source_table('googlemeet_ai_analysis', ['recordingid' => backup::VAR_PARENTID]);
            $recordingsub->set_source_table('googlemeet_recording_subs', ['googlemeetid' => backup::VAR_PARENTID]);
        }

        $holiday->set_source_table('googlemeet_holidays', ['googlemeetid' => backup::VAR_PARENTID]);

        $cancelleddate->set_source_table('googlemeet_cancelled', ['googlemeetid' => backup::VAR_PARENTID]);

        // Define id annotations.
        if ($userinfo) {
            $recordingsub->annotate_ids('user', 'userid');
        }

        // Define file annotations.
        $googlemeet->annotate_files('mod_googlemeet', 'intro', null); // This file area hasn't itemid.

        return $this->prepare_activity_structure($googlemeet);
    }
}
