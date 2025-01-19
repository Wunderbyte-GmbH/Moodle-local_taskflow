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
 * Plugin event observers are registered here.
 *
 * @package     local_taskflow
 * @category    event
 * @copyright   2021 Wunderbyte GmbH<info@wunderbyte.at>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Get the installed event handlers.

$eventhandlers = core_component::get_component_classes_in_namespace('local_taskflow', 'local\eventhandlers');

foreach ($eventhandlers as $classname => $eventhandler) {
    $eventhandler = new $classname();
    $observers[] = [
        'eventname' => $eventhandler->eventname,
        'callback' => '\local_taskflow\observer::call_event_handler',
    ];
}
