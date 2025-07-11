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
use local_taskflow\local\history\history;
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
        $unitmemebrrepo->update_or_create($user, $data['objectid']);
        $event = unit_member_updated::create([
            'objectid' => $data['objectid'],
            'context'  => \context_system::instance(),
            'userid'   => $data['objectid'],
            'other'    => [
                'unitid' => $data['objectid'],
                'unitmemberid' => $data['relateduserid'],
            ],
        ]);
        $event->trigger();
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
        $event->trigger();
    }

    /**
     * Observer for the update_catscale event
     * @param \core\event\base $event
     */
    public static function cohort_removed($event) {
        $data = $event->get_data();
        $event = unit_removed::create([
            'objectid' => $data['objectid'],
            'context'  => \context_system::instance(),
            'userid'   => $data['objectid'],
            'other'    => [
                'unitid' => $data['objectid'],
            ],
        ]);
        $event->trigger();
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
        $data['other']['targettype'] = history::TYPE_COURSE_COMPLETED;
        $completionoperator->handle_completion_process($data);
    }

    /**
     * Observer for the update_catscale event
     * @param \core\event\base $event
     */
    public static function competency_completed($event) {

        global $DB;

        $data = $event->get_data();

        // We need to retrieve the competencyid from the event user competency.
        $id = $data['objectid'];
        $competencyid = $DB->get_field('competency_usercomp', 'competencyid', ['id' => $id]);

        $completionoperator = new completion_operator(
            $competencyid,
            $data['relateduserid'],
            'competency'
        );
        $data['other']['targettype'] = history::TYPE_COMPETENCY_COMPLETED;
        $completionoperator->handle_completion_process($data);
    }

    /**
     * Observer for the update_catscale event
     * @param \core\event\base $event
     */
    public static function bookingoption_booked($event) {
        global $DB;
        $data = $event->get_data();
        $completionoperator = new completion_operator(
            $data['objectid'],
            $data['relateduserid'],
            'bookingoption'
        );
        $data['other']['targettype'] = history::TYPE_COURSE_ENROLLED;
        $completionoperator->handle_completion_process($data);
    }
}
