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

use local_taskflow\local\roles;

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
        $table->add_field('assigneddate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
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

    if ($oldversion < 2025011929) {
        // Define table local_taskflow_unit_rel to be created.
        $table = new xmldb_table('local_taskflow_assignment');
        $field = new xmldb_field('ruleid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);

        // Conditionally launch add field image.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2025011929, 'local', 'taskflow');
    }

    if ($oldversion < 2025011930) {
        // Define table local_taskflow_assignment to be created.
        $table = new xmldb_table('local_taskflow_messages');

        // Adding fields to table local_taskflow_assignment.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('class', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('message', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('priority', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('sending_settings', XMLDB_TYPE_TEXT, '10', null, null, null, null);
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
        upgrade_plugin_savepoint(true, 2025011930, 'local', 'taskflow');
    }

    if ($oldversion < 2025042810) {
        // Define table local_taskflow_assignment to be created.
        $table = new xmldb_table('local_taskflow_sent_messages');

        // Adding fields to table local_taskflow_assignment.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('messageid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('ruleid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('timesent', XMLDB_TYPE_INTEGER, '10', null, null, null, null);

        // Adding keys to table local_taskflow_assignment.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        // Conditionally launch create table for local_taskflow_assignment.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Taskflow savepoint reached.
        upgrade_plugin_savepoint(true, 2025042810, 'local', 'taskflow');
    }

    if ($oldversion < 2025042812) {
        $table = new xmldb_table('local_taskflow_units');
        $fieldtissid = new xmldb_field('tissid', XMLDB_TYPE_INTEGER, '10', null, null, null, '0', 'name');
        $fielddescription = new xmldb_field('description', XMLDB_TYPE_TEXT, null, null, null, null);

        if (!$dbman->field_exists($table, $fieldtissid)) {
            $dbman->add_field($table, $fieldtissid);
            $key = new xmldb_key('unique_tissid', XMLDB_KEY_UNIQUE, ['tissid']);
            $dbman->add_key($table, $key);
        }
        if (!$dbman->field_exists($table, $fielddescription)) {
            $dbman->add_field($table, $fielddescription);
        }
        upgrade_plugin_savepoint(true, 2025042812, 'local', 'taskflow');
    }

    if ($oldversion < 2025042813) {
        if (!$DB->record_exists('user_info_field', ['shortname' => 'tissid_info'])) {
            $profilefield = new stdClass();
            $profilefield->shortname = 'tissid_info';
            $profilefield->name = 'Externe ID';
            $profilefield->datatype = 'textarea';
            $profilefield->description = 'Stores Tiss ID.';
            $profilefield->categoryid = 1;
            $profilefield->required = 0;
            $profilefield->locked = 0;
            $profilefield->visible = 2;
            $profilefield->sortorder = 1;

            $DB->insert_record('user_info_field', $profilefield);
        }
        if (!$DB->record_exists('user_info_field', ['shortname' => 'organisational_unit_info'])) {
            $profilefield = new stdClass();
            $profilefield->shortname = 'organisational_unit_info';
            $profilefield->name = 'Organisational unit Information';
            $profilefield->datatype = 'textarea';
            $profilefield->description = 'Stores Organisational unit Information.';
            $profilefield->categoryid = 1;
            $profilefield->required = 0;
            $profilefield->locked = 0;
            $profilefield->visible = 2;
            $profilefield->sortorder = 1;

            $DB->insert_record('user_info_field', $profilefield);
        }
        if (!$DB->record_exists('user_info_field', ['shortname' => 'end_info'])) {
            $profilefield = new stdClass();
            $profilefield->shortname = 'end_info';
            $profilefield->name = 'End Information';
            $profilefield->datatype = 'textarea';
            $profilefield->description = 'User contract end Information.';
            $profilefield->categoryid = 1;
            $profilefield->required = 0;
            $profilefield->locked = 0;
            $profilefield->visible = 2;
            $profilefield->sortorder = 1;

            $DB->insert_record('user_info_field', $profilefield);
        }
        upgrade_plugin_savepoint(true, 2025042813, 'local', 'taskflow');
    }

    if ($oldversion < 2025042826) {
        $table = new xmldb_table('local_taskflow_rules');
        $fielduserid = new xmldb_field('userid', XMLDB_TYPE_INTEGER, '10', null, null, null, '0', 'userid');
        if (!$dbman->field_exists($table, $fielduserid)) {
            $dbman->add_field($table, $fielduserid);
        }
        upgrade_plugin_savepoint(true, 2025042826, 'local', 'taskflow');
    }

    if ($oldversion < 2025061200) {
        // Define field status to be added to local_taskflow_assignment.
        $table = new xmldb_table('local_taskflow_assignment');
        $field = new xmldb_field('status', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'active');

        // Conditionally launch add field status.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Taskflow savepoint reached.
        upgrade_plugin_savepoint(true, 2025061200, 'local', 'taskflow');
    }

    if ($oldversion < 2025061300) {
        // Define table local_taskflow_history to be created.
        $table = new xmldb_table('local_taskflow_history');

        // Adding fields to table local_taskflow_history.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('assignmentid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('type', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('data', XMLDB_TYPE_TEXT, 'big', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        // Adding keys to table local_taskflow_history.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        // Adding indexes to table local_taskflow_history.
        $table->add_index('assignment_idx', XMLDB_INDEX_NOTUNIQUE, ['assignmentid']);
        $table->add_index('userid_idx', XMLDB_INDEX_NOTUNIQUE, ['userid']);
        $table->add_index('type_idx', XMLDB_INDEX_NOTUNIQUE, ['type']);

        // Conditionally launch create table for local_taskflow_history.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Training plugin savepoint reached.
        upgrade_plugin_savepoint(true, 2025061300, 'local', 'taskflow');
    }

    if ($oldversion < 2025061301) {
        // Define table and field to rename.
        // Rename field 'assigned_date' → 'assigneddate' in local_taskflow_assignment.
        $table = new xmldb_table('local_taskflow_assignment');
        $field = new xmldb_field('assigned_date', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        if ($dbman->field_exists($table, $field)) {
            $dbman->rename_field($table, $field, 'assigneddate');
        }

        // Rename fields in local_taskflow_sent_messages.
        $table = new xmldb_table('local_taskflow_sent_messages');

        // Message_id → messageid.
        $field = new xmldb_field('message_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        if ($dbman->field_exists($table, $field)) {
            $dbman->rename_field($table, $field, 'messageid');
        }

        // Rule_id → ruleid.
        $field = new xmldb_field('rule_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        if ($dbman->field_exists($table, $field)) {
            $dbman->rename_field($table, $field, 'ruleid');
        }

        // User_id → userid.
        $field = new xmldb_field('user_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        if ($dbman->field_exists($table, $field)) {
            $dbman->rename_field($table, $field, 'userid');
        }

        // Training plugin savepoint reached.
        upgrade_plugin_savepoint(true, 2025061301, 'local', 'taskflow');
    }

    if ($oldversion < 2025061305) {
        // Define table and field to rename.
        // Rename field 'assigned_date' → 'assigneddate' in local_taskflow_assignment.
        $table = new xmldb_table('local_taskflow_assignment');
        $field = new xmldb_field('duedate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0);
        // Conditionally launch add field status.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Training plugin savepoint reached.
        upgrade_plugin_savepoint(true, 2025061305, 'local', 'taskflow');
    }

    if ($oldversion < 2025061802) {
        // Define table local_taskflow_assignment_competency.
        $table = new xmldb_table('local_taskflow_assignment_competency');

        // Add fields.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('assignmentid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('competencyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('competencyevidenceid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, null, null, null);

        // Add keys.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('assignment_competency_unique', XMLDB_KEY_UNIQUE, ['assignmentid', 'userid', 'competencyid']);
        $table->add_key('assignment_fk', XMLDB_KEY_FOREIGN, ['assignmentid'], 'local_taskflow_assignment', ['id']);
        $table->add_key('competencyevidence_fk', XMLDB_KEY_FOREIGN, ['competencyevidenceid'], 'competency_userevidence', ['id']);

        // Conditionally create the table.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Upgrade savepoint.
        upgrade_plugin_savepoint(true, 2025061802, 'local', 'taskflow');
    }

    if ($oldversion < 2025061803) {
        $table = new xmldb_table('local_taskflow_assignment_competency');

        // Drop the unique key (safe to drop without checking existence).
        $key = new xmldb_key('assignment_competency_unique', XMLDB_KEY_UNIQUE, ['assignmentid', 'userid', 'competencyid']);
        $dbman->drop_key($table, $key);

        // Drop the foreign key for assignmentid.
        $assignmentfk = new xmldb_key('assignment_fk', XMLDB_KEY_FOREIGN, ['assignmentid'], 'local_taskflow_assignment', ['id']);
        $dbman->drop_key($table, $assignmentfk);

        // Drop the field assignmentid.
        $field = new xmldb_field('assignmentid');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        // Add a new unique key on (userid, competencyid).
        $newuniq = new xmldb_key('user_competency_unique', XMLDB_KEY_UNIQUE, ['userid', 'competencyid']);
        $dbman->add_key($table, $newuniq);

        upgrade_plugin_savepoint(true, 2025061803, 'local', 'taskflow');
    }

    if ($oldversion < 2025061804) {
        $table = new xmldb_table('local_taskflow_assignment_competency');

        // Drop unique key user_competency_unique if it exists.
        $key = new xmldb_key('user_competency_unique', XMLDB_KEY_UNIQUE, ['userid', 'competencyid']);
        $dbman->drop_key($table, $key);

        // Savepoint after successful upgrade.
        upgrade_plugin_savepoint(true, 2025061804, 'local', 'taskflow');
    }

    if ($oldversion < 2025061805) {
        $table = new xmldb_table('local_taskflow_unit_members');
        $field = new xmldb_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, 1);

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2025061805, 'local', 'taskflow');
    }
    if ($oldversion < 2025062200) {
        $table = new xmldb_table('local_taskflow_assignment_competency');

        // Add status field.
        $field = new xmldb_field('status', XMLDB_TYPE_CHAR, '20', null, null, null, 'underreview', 'competencyid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Savepoint after successful upgrade.
        upgrade_plugin_savepoint(true, 2025062200, 'local', 'taskflow');
    }

    if ($oldversion < 2025062601) {
        // Define the table and the new field.
        $table = new xmldb_table('local_taskflow_assignment_competency');
        $field = new xmldb_field('assignmentid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'id');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2025062601, 'local', 'taskflow');
    }

    if ($oldversion < 2025062703) {
        $table = new xmldb_table('local_taskflow_assignment');
        $field = new xmldb_field('completeddate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2025062703, 'local', 'taskflow');
    }

    if ($oldversion < 2025070700) {
        $roles = new roles();
        $roles->ensure_supervisor_role();

        upgrade_plugin_savepoint(true, 2025070700, 'local', 'taskflow');
    }

    if ($oldversion < 2025071103) {
        // Define field keepchanges to be added to local_taskflow_assignment.
        $table = new xmldb_table('local_taskflow_assignment');
        $field = new xmldb_field('keepchanges', XMLDB_TYPE_INTEGER, '10', null, null, null, '0', 'timemodified');

        // Conditionally launch add field keepchanges.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        // Taskflow savepoint reached.
        upgrade_plugin_savepoint(true, 2025071103, 'local', 'taskflow');
    }

    if ($oldversion < 2025081200) {
        // Define field name to be added to local_taskflow_messages.
        $table = new xmldb_table('local_taskflow_messages');
        $field = new xmldb_field('name', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'id');

        // Conditionally launch add field name.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Taskflow savepoint reached.
        upgrade_plugin_savepoint(true, 2025081200, 'local', 'taskflow');
    }

    if ($oldversion < 2025082801) {
        // Define field overduecounter to be added to local_taskflow_assignment.
        $table = new xmldb_table('local_taskflow_assignment');
        $fields = [
            new xmldb_field('overduecounter', XMLDB_TYPE_INTEGER, '10', null, null, null, '0', 'keepchanges'),
            new xmldb_field('prolongedcounter', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'overduecounter'),
        ];

        foreach ($fields as $field) {
            // Conditionally launch add field overduecounter.
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // Taskflow savepoint reached.
        upgrade_plugin_savepoint(true, 2025082801, 'local', 'taskflow');
    }
    if ($oldversion < 2025082900) {
        // Define index ruleid_ix (not unique) to be added to local_taskflow_assignment.
        $table = new xmldb_table('local_taskflow_assignment');
        $index = new xmldb_index('ruleid_ix', XMLDB_INDEX_NOTUNIQUE, ['ruleid']);

        // Conditionally launch add index ruleid_ix.
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Taskflow savepoint reached.
        upgrade_plugin_savepoint(true, 2025082900, 'local', 'taskflow');
    }
    if ($oldversion < 2025082901) {
        // Define index userid_ix (not unique) to be added to local_taskflow_assignment.
        $table = new xmldb_table('local_taskflow_assignment');
        $index = new xmldb_index('userid_ix', XMLDB_INDEX_NOTUNIQUE, ['userid']);

        // Conditionally launch add index userid_ix.
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Taskflow savepoint reached.
        upgrade_plugin_savepoint(true, 2025082901, 'local', 'taskflow');
    }
    if ($oldversion < 2025082902) {
        // Define index status_ix (not unique) to be added to local_taskflow_assignment.
        $table = new xmldb_table('local_taskflow_assignment');
        $index = new xmldb_index('status_ix', XMLDB_INDEX_NOTUNIQUE, ['status']);

        // Conditionally launch add index status_ix.
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Taskflow savepoint reached.
        upgrade_plugin_savepoint(true, 2025082902, 'local', 'taskflow');
    }
    if ($oldversion < 2025082903) {
        // Define index assignmentid_ix (not unique) to be added to local_taskflow_history.
        $table = new xmldb_table('local_taskflow_history');
        $index = new xmldb_index('assignmentid_ix', XMLDB_INDEX_NOTUNIQUE, ['assignmentid']);

        // Conditionally launch add index assignmentid_ix.
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Taskflow savepoint reached.
        upgrade_plugin_savepoint(true, 2025082903, 'local', 'taskflow');
    }
    return true;
}
