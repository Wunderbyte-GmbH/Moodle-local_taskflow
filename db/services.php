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
 * Webservice to reload table.
 *
 * @package     local_taskflow
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_taskflow_load_dashboard' => [
        'classname'   => 'local_taskflow\external\load_dashboard',
        'description' => 'Submit step data via AJAX',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => '',
    ],
    'local_taskflow_clear_dashboard_cache' => [
        'classname'   => 'local_taskflow\external\clear_dashboard_cache',
        'description' => 'Remove a user from dashboardfilter cache',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => '',
    ],
];
