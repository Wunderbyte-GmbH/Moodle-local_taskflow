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
 * Code to be executed after the plugin's database scheme has been installed is defined here.
 *
 * @package     local_taskflow
 * @category    upgrade
 * @copyright   2025 Wunderbyte Gmbh <info@wunderbyte.at>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Custom code to be run on installing the plugin.
 */
function xmldb_local_taskflow_install() {
    global $DB;

    if (!$DB->record_exists('user_info_field', ['shortname' => 'unit_info'])) {
        $profilefield = new stdClass();
        $profilefield->shortname = 'unit_info';
        $profilefield->name = 'Unit Information';
        $profilefield->datatype = 'textarea';
        $profilefield->description = 'Stores unit-related information for users.';
        $profilefield->categoryid = 1;
        $profilefield->required = 0;
        $profilefield->locked = 0;
        $profilefield->visible = 2;
        $profilefield->sortorder = 1;

        $DB->insert_record('user_info_field', $profilefield);
    }
    return true;
}
