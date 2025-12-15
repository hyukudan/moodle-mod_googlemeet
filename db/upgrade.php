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
 * Plugin upgrade steps are defined here.
 *
 * @package     mod_googlemeet
 * @category    upgrade
 * @copyright   2020 Rone Santos <ronefel@hotmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Execute mod_googlemeet upgrade from the given old version.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_googlemeet_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2023042200) {

        // Define field eventid to be added to googlemeet.
        $table = new xmldb_table('googlemeet');
        $field = new xmldb_field('eventid', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'timemodified');

        // Conditionally launch add field eventid.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Googlemeet savepoint reached.
        upgrade_mod_savepoint(true, 2023042200, 'googlemeet');
    }

    if ($oldversion < 2025121402) {

        // Define table googlemeet_holidays to be created.
        $table = new xmldb_table('googlemeet_holidays');

        // Adding fields to table googlemeet_holidays.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('googlemeetid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('startdate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('enddate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        // Adding keys to table googlemeet_holidays.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('googlemeetidfk', XMLDB_KEY_FOREIGN, ['googlemeetid'], 'googlemeet', ['id']);

        // Conditionally launch create table for googlemeet_holidays.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Googlemeet savepoint reached.
        upgrade_mod_savepoint(true, 2025121402, 'googlemeet');
    }

    if ($oldversion < 2025121403) {

        // Define table googlemeet_cancelled to be created.
        $table = new xmldb_table('googlemeet_cancelled');

        // Adding fields to table googlemeet_cancelled.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('googlemeetid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('cancelleddate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('reason', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        // Adding keys to table googlemeet_cancelled.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('googlemeetidfk', XMLDB_KEY_FOREIGN, ['googlemeetid'], 'googlemeet', ['id']);

        // Conditionally launch create table for googlemeet_cancelled.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Googlemeet savepoint reached.
        upgrade_mod_savepoint(true, 2025121403, 'googlemeet');
    }

    if ($oldversion < 2025121404) {

        // Define field maxupcomingevents to be added to googlemeet.
        $table = new xmldb_table('googlemeet');
        $field = new xmldb_field('maxupcomingevents', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '3', 'eventid');

        // Conditionally launch add field maxupcomingevents.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Googlemeet savepoint reached.
        upgrade_mod_savepoint(true, 2025121404, 'googlemeet');
    }

    if ($oldversion < 2025121500) {

        // Define table googlemeet_ai_analysis to be created.
        $table = new xmldb_table('googlemeet_ai_analysis');

        // Adding fields to table googlemeet_ai_analysis.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('recordingid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('summary', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('keypoints', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('transcript', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('topics', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('language', XMLDB_TYPE_CHAR, '10', null, null, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'pending');
        $table->add_field('error', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('aimodel', XMLDB_TYPE_CHAR, '50', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        // Adding keys to table googlemeet_ai_analysis.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('recordingidfk', XMLDB_KEY_FOREIGN_UNIQUE, ['recordingid'], 'googlemeet_recordings', ['id']);

        // Adding indexes to table googlemeet_ai_analysis.
        $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);

        // Conditionally launch create table for googlemeet_ai_analysis.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Googlemeet savepoint reached.
        upgrade_mod_savepoint(true, 2025121500, 'googlemeet');
    }

    if ($oldversion < 2025121501) {
        // Add transcript field to googlemeet_recordings table.
        $table = new xmldb_table('googlemeet_recordings');
        $field = new xmldb_field('transcripttext', XMLDB_TYPE_TEXT, null, null, null, null, null, 'webviewlink');

        // Conditionally launch add field transcripttext.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add transcriptfileid field to store the Drive file ID of the transcript.
        $field2 = new xmldb_field('transcriptfileid', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'transcripttext');
        if (!$dbman->field_exists($table, $field2)) {
            $dbman->add_field($table, $field2);
        }

        // Googlemeet savepoint reached.
        upgrade_mod_savepoint(true, 2025121501, 'googlemeet');
    }

    if ($oldversion < 2025121502) {

        // Define field maxrecordings to be added to googlemeet.
        $table = new xmldb_table('googlemeet');
        $field = new xmldb_field('maxrecordings', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '5', 'maxupcomingevents');

        // Conditionally launch add field maxrecordings.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Define field recordingsorder to be added to googlemeet.
        $field2 = new xmldb_field('recordingsorder', XMLDB_TYPE_CHAR, '4', null, XMLDB_NOTNULL, null, 'DESC', 'maxrecordings');

        // Conditionally launch add field recordingsorder.
        if (!$dbman->field_exists($table, $field2)) {
            $dbman->add_field($table, $field2);
        }

        // Googlemeet savepoint reached.
        upgrade_mod_savepoint(true, 2025121502, 'googlemeet');
    }

    if ($oldversion < 2025121503) {

        // Increase eventid field length from 100 to 255 chars.
        // Google Calendar Event IDs can be up to 1024 chars.
        $table = new xmldb_table('googlemeet');
        $field = new xmldb_field('eventid', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'timemodified');

        // Change field precision.
        $dbman->change_field_precision($table, $field);

        // Googlemeet savepoint reached.
        upgrade_mod_savepoint(true, 2025121503, 'googlemeet');
    }

    return true;
}
