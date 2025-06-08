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
 * Shortcodes for local_taskflow
 *
 * @package local_taskflow
 * @copyright 2025 Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$shortcodes = [
    'assignmentsdashboard' => [
        'callback' => 'local_taskflow\shortcodes::assignmentsdashboard',
        'wraps' => false,
        'description' => 'assignmentsdashboard',
    ],
    'rulesdashboard' => [
        'callback' => 'local_taskflow\shortcodes::rulesdashboard',
        'wraps' => false,
        'description' => 'rulesdashboard',
    ],
    'myassignments' => [
        'callback' => 'local_taskflow\shortcodes::myassignments',
        'wraps' => false,
        'description' => 'myassignments',
    ],
    'supervisorassignments' => [
        'callback' => 'local_taskflow\shortcodes::supervisorassignments',
        'wraps' => false,
        'description' => 'supervisorassignments',
    ],
];
