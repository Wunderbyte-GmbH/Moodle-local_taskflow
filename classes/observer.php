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
 * Observer for given events.
 *
 * @package   local_taskflow
 * @author    Georg Maißer
 * @copyright 2023 Your Name
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow;

use core_component;
/**
 * Observer class that handles user events.
 */
class observer {
    /**
     * Call the central event handler class.
     *
     *
     * @param \core\event\base $event
     */
    public static function call_event_handler($event): void {

        $eventhandlers =
            core_component::get_component_classes_in_namespace('local_taskflow', 'local\eventhandlers');

        foreach ($eventhandlers as $classname => $eventhandler) {
            $eventhandler = new $classname();
            if ($eventhandler->eventname === get_class($event)) {
                $eventhandler->handle($event);
            }
        }
    }

    /**
     * Call the central event handler class.
     * @param \core\event\base $event
     */
    public static function user_externally_updated(\core\event\base $event): void {
        self::call_event_handler($event);
    }
}
