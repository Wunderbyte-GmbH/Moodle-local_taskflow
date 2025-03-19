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
        // Define table local_taskflow_unit_relations to be created.
        $table = new xmldb_table('local_taskflow_unit_relations');
        $field = new xmldb_field('active', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');

        // Conditionally launch add field image.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2025011916, 'local', 'taskflow');
    }

    return true;
}
