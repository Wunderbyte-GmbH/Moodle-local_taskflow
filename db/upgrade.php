<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Plugin upgrade steps are defined here.
 *
 * @package     local_taskflow
 * @category    upgrade
 * @copyright   2025 Wunderbyte Gmbh <info@wunderbyte.at>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Execute local_taskflow upgrade from the given old version.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_taskflow_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2025011915) {
        if (!$DB->record_exists('user_info_field', ['shortname' => 'unit_info'])) {
            $profilefield = new stdClass();
            $profilefield->shortname = 'unit_info';
            $profilefield->name = 'Unit Information';
            $profilefield->datatype = 'textarea';
            $profilefield->description = 'Stores unit-related information for users.';
            $profilefield->categoryid = 1;
            $profilefield->required = 0;
            $profilefield->locked = 1;
            $profilefield->visible = 2;
            $profilefield->sortorder = 1;

            $DB->insert_record('user_info_field', $profilefield);
        }
        upgrade_plugin_savepoint(true, 2025011915, 'local', 'taskflow');
    }

    if ($oldversion < 2025011916) {
        // Define table local_taskflow_unit_rel to be created.
        $table = new xmldb_table('local_taskflow_unit_rel');
        $field = new xmldb_field('active', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');

        // Conditionally launch add field image.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2025011916, 'local', 'taskflow');
    }

    if ($oldversion < 2025011923) {
        // Define table booking_rules to be created.
        $table = new xmldb_table('local_taskflow_rules');
        // Adding fields to table booking_rules.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('unitid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('rulename', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('rulejson', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('eventname', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('isactive', XMLDB_TYPE_INTEGER, '2', null, null, null, 1);
        // Adding keys to table booking_rules.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        // Conditionally launch create table for booking_rules.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2025011923, 'local', 'taskflow');
    }

    if ($oldversion < 2025011928) {
        // Define table local_taskflow_assignment to be created.
        $table = new xmldb_table('local_taskflow_assignment');

        // Adding fields to table local_taskflow_assignment.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('targets', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('messages', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('unitid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('active', XMLDB_TYPE_INTEGER, '1', null, null, null, null);
        $table->add_field('assigned_date', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, null, null, null);

        // Adding keys to table local_taskflow_assignment.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        // Conditionally launch create table for local_taskflow_assignment.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Taskflow savepoint reached.
        upgrade_plugin_savepoint(true, 2025011928, 'local', 'taskflow');
    }

    return true;
}
