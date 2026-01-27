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
use context_course;
use context_system;
use core_component;
use core_user;
use local_taskflow\event\unit_member_removed;
use local_taskflow\event\unit_member_updated;
use local_taskflow\event\unit_removed;
use local_taskflow\local\assignment_process\assignment_preprocessor;
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\assignments\assignment;
use local_taskflow\local\assignments\assignment_seen;
use local_taskflow\local\history\history;
use local_taskflow\local\completion_process\completion_operator;
use local_taskflow\local\eventhandlers\core_user_created_updated;
use local_taskflow\local\messages\messages_factory;
use local_taskflow\local\messages\messages_manager;
use local_taskflow\local\messages\types\chat;
use local_taskflow\local\personas\unit_members\moodle_unit_member_facade;
use local_taskflow\local\messages\types\request;
use local_taskflow\local\requests;
use local_taskflow\local\rules\rules;
use mod_booking\event\booking_debug;

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
    public static function course_reset($event) {
        $data = $event->get_data();
        $data['other']['targettype'] = history::TYPE_COURSE_COMPLETED;
        $users = get_enrolled_users(
            context_course::instance($data['courseid']),
            '',
            0,
            'u.id'
        );

        foreach ($users as $user) {
            $completionoperator = new completion_operator(
                $data['courseid'],
                $user->id,
                'moodlecourse'
            );
            $completionoperator->handle_completion_process($data);
        }
    }

    /**
     * Observer for the update_catscale event
     * @param \core\event\base $event
     */
    public static function course_completion_updated($event) {
        $data = $event->get_data();
        $data['other']['targettype'] = history::TYPE_COURSE_COMPLETED;
        if (
            isset($data['other']['newstate'])
            && $data['other']['newstate'] == COMPLETION_INCOMPLETE
        ) {
            $completionoperator = new completion_operator(
                $data['courseid'],
                $data['relateduserid'],
                'moodlecourse'
            );
            $completionoperator->handle_completion_process($data);
        }
    }

    /**
     * Observer for the update_catscale event
     * @param \core\event\base $event
     */
    public static function competency_completed($event) {
        global $DB, $USER;
        $data = $event->get_data();

        // We need to retrieve the competencyid from the event user competency.
        $id = $data['objectid'];
        $relateduserid = $data['relateduserid'];
        if (!$competencyid = $DB->get_field('competency_usercomp', 'competencyid', ['id' => $id, 'userid' => $relateduserid])) {
            // If we can't find te user competency, we just return.
            // This should never happen. We therefore trigger the booking debug event always.
            $message = "Could not find competencyid for competency_usercomp id {$id} and userid {$relateduserid}";
            $event = booking_debug::create([
                'objectid' => $id,
                'context' => context_system::instance(),
                'relateduserid' => $USER->id,
                'other' => [
                    'message' => $message,
                ],
            ]);
            $event->trigger();

            return;
        }

        $completionoperator = new completion_operator(
            $competencyid,
            $data['relateduserid'],
            'competency'
        );

        if (
            CLI_SCRIPT
            && !PHPUNIT_TEST
        ) {
            mtrace("Processing competency completion for competencyid {$competencyid} and userid {$data['relateduserid']}");
        }

        $data['other']['targettype'] = history::TYPE_COMPETENCY_COMPLETED;
        $completionoperator->handle_completion_process($data);
    }

    /**
     * Observer for the update_catscale event
     * @param \core\event\base $event
     */
    public static function bookingoption_booked($event) {
        global $DB;

        // Evenlistener on enrolled should be skipped if enrolled is excluded.
        if (assignment_status_facade::check_excluded(assignment_status_facade::get_status_identifier('enrolled'))) {
            return;
        }
        $data = $event->get_data();
        $completionoperator = new completion_operator(
            $data['objectid'],
            $data['relateduserid'],
            'bookingoption'
        );
        $data['other']['targettype'] = history::TYPE_COURSE_ENROLLED;
        $completionoperator->handle_completion_process($data);
    }

    /**
     * Observer for the user_deleted event
     * @param \core\event\base $event
     */
    public static function user_deleted($event) {
        global $DB;
        $data = $event->get_data();
        $preprocessor = new assignment_preprocessor($data);
        $preprocessor->set_this_user($data['objectid']);
        $preprocessor->set_all_user_affected_rules();
        $preprocessor->set_all_user_affected_units();
        $preprocessor->process_unassignemnts();
    }

    /**
     * Observer for the user_deleted event
     * @param \core\event\base $event
     */
    public static function send_schedule_request_messages($event) {
        global $DB;
        $data = $event->get_data();
        $eventname = $event->eventname;

        $statusmatching = [
            '\local_taskflow\event\request_created' => 'onrequestcreated',
            '\local_taskflow\event\request_treated' => 'onrequestclosed',
        ];
        $assignment = new assignment($data['other']['assignmentid']);

        $rule = rules::instance($assignment->ruleid);
        $rulejson = json_decode($rule->get_rulesjson());
        $actions = $rulejson->rulejson->rule->actions ?? null;
        if ($actions) {
            foreach ($actions as $action) {
                foreach ($action->messages as $message) {
                    $assignmentmessageinstance = messages_factory::instance(
                        $message,
                        $assignment->userid,
                        $assignment->ruleid,
                    );
                    $messagesettings = json_decode($assignmentmessageinstance->message->sending_settings);
                    if (
                        $assignmentmessageinstance != null &&
                        $assignmentmessageinstance::TYPE == request::TYPE &&
                        $statusmatching[$eventname] == $messagesettings->sendstartrequest
                    ) {
                        $rulejson->requestid = $data['objectid'];
                        $assignmentmessageinstance->schedule_message($rulejson);
                    }
                }
            }
        }
    }

    /**
     * Observer for the user_deleted event
     * @param \core\event\base $event
     */
    public static function recalculate_existing_assignments($event) {
        global $DB;
        $data = $event->get_data();
        $preprocessor = new assignment_preprocessor($data);
        $preprocessor->set_this_user($data['relateduserid']);
        $preprocessor->set_all_user_affected_rules();
        $preprocessor->process_assignemnts();
        $messagesmanager = new messages_manager($data['relateduserid']);
        $messagesmanager->delete_all_not_matching_messages_with_status(
            [
                        assignment_status_facade::get_status_identifier('completed'),
                    ]
        );
    }

    /**
     * Observer for the user_deleted event
     * @param \core\event\base $event
     */
    public static function check_and_send_assignment_message_reminder($event) {
        global $DB;
        $data = $event->get_data();
        $assignment = new assignment($data['other']['assignmentid']);
        $rule = rules::instance($assignment->ruleid);
        $rulejson = json_decode($rule->get_rulesjson());
        $actions = $rulejson->rulejson->rule->actions ?? null;
        if ($actions) {
            foreach ($actions as $action) {
                foreach ($action->messages as $message) {
                    $assignmentmessageinstance = messages_factory::instance(
                        $message,
                        $assignment->userid,
                        $assignment->ruleid,
                    );
                    if (
                        $assignmentmessageinstance != null &&
                        $assignmentmessageinstance::TYPE == chat::TYPE
                    ) {
                        $rulejson->requestid = $data['objectid'];
                        $rulejson->other = $data['other'];
                        $assignmentmessageinstance->schedule_message($rulejson);
                    }
                }
            }
        }
    }

    /**
     * Observer for the user_deleted event
     * @param \core\event\base $event
     */
    public static function update_last_seen($event) {
        global $DB;
        $data = $event->get_data();
        $assignmentseen = new assignment_seen(
            $data['other']['userid'],
            $data['other']['assignmentid'],
        );
        $assignmentseen->update_or_create_last_seen();
    }
}
