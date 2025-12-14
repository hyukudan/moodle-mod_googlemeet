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

    return true;
}
