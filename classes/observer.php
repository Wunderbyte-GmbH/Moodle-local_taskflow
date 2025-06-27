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

use cache_helper;
use core_component;
use core_user;
use local_taskflow\event\unit_member_removed;
use local_taskflow\event\unit_member_updated;
use local_taskflow\event\unit_removed;
use local_taskflow\local\completion_process\completion_operator;
use local_taskflow\local\eventhandlers\core_user_created_updated;
use local_taskflow\local\personas\unit_members\moodle_unit_member_facade;

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
            if (
                isset($eventhandler->eventname) &&
                $eventhandler->eventname === get_class($event)
            ) {
                $eventhandler->handle($event);
            }
        }
        cache_helper::purge_by_event('changesinassignmentslist');
    }
    /**
     * Observer for the update_catscale event
     * @param \core\event\base $event
     */
    public static function core_user_created_updated($event) {
        $eventhandler = new core_user_created_updated();
        $eventhandler->handle($event);
    }

    /**
     * Observer for the update_catscale event
     * @param \core\event\base $event
     */
    public static function cohort_member_added($event) {
        $data = $event->get_data();
        $user = core_user::get_user($data['relateduserid']);
        $unitmemebrrepo = new moodle_unit_member_facade();
        $unitmemebrrepo->update_or_create($user, unitid: $data['objectid']);
        $event = unit_member_updated::create([
            'objectid' => $data['objectid'],
            'context'  => \context_system::instance(),
            'userid'   => $data['objectid'],
            'other'    => [
                'unitid' => $data['objectid'],
                'unitmemberid' => $data['relateduserid'],
            ],
        ]);
        self::call_event_handler($event);
    }

    /**
     * Observer for the update_catscale event
     * @param \core\event\base $event
     */
    public static function cohort_member_removed($event) {
        $data = $event->get_data();
        $event = unit_member_removed::create([
            'objectid' => $data['objectid'],
            'context'  => \context_system::instance(),
            'userid'   => $data['objectid'],
            'other'    => [
                'unitid' => $data['objectid'],
                'unitmemberid' => [$data['relateduserid']],
            ],
        ]);
        self::call_event_handler($event);
    }

    /**
     * Observer for the update_catscale event
     * @param \core\event\base $event
     */
    public static function cohort_removed($event) {
        $data = $event->get_data();
        $unitevent = unit_removed::create([
            'objectid' => $data['objectid'],
            'context'  => \context_system::instance(),
            'userid'   => $data['objectid'],
            'other'    => [
                'unitid' => $data['objectid'],
            ],
        ]);
        self::call_event_handler($unitevent);
    }

    /**
     * Observer for the update_catscale event
     * @param \core\event\base $event
     */
    public static function course_completed($event) {
        $data = $event->get_data();
        $completionoperator = new completion_operator(
            $data['courseid'],
            $data['other']['relateduserid'],
            'moodlecourse'
        );
        $completionoperator->handle_completion_process();
    }

    /**
     * Observer for the update_catscale event
     * @param \core\event\base $event
     */
    public static function competency_completed($event) {
        $data = $event->get_data();
        $completionoperator = new completion_operator(
            $data['other']['competencyid'],
            $data['relateduserid'],
            'competency'
        );
        $completionoperator->handle_completion_process();
    }
}
