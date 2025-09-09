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
 * Unit class to manage users.
 *
 * @package local_taskflow
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

 namespace local_taskflow\local\assignment_process\assignments;

 use local_taskflow\local\assignment_operators\action_operator;
 use local_taskflow\local\assignment_operators\assignment_operator;
 use local_taskflow\local\assignment_status\assignment_status_facade;
 use local_taskflow\local\assignment_status\types\planned;
 use local_taskflow\local\assignments\assignments_facade;
 use local_taskflow\local\assignments\status\assignment_status;
 use local_taskflow\local\assignments\types\standard_assignment;
 use local_taskflow\local\completion_process\completion_operator;
 use local_taskflow\task\open_planned_assignment;
 use core\task\manager;
 use stdClass;

/**
 * Repository for dependecy injection
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignments_controller {
    /**
     * Updates or creates unit member
     * @param int $userid
     * @param mixed $rule
     * @return array
     */
    public function construct_and_process_assignment($userid, $rule): array {
        global $USER;
        $rulejson = json_decode($rule->get_rulesjson());
        $targets = [];
        $messages = [];

        foreach ($rulejson->rulejson->rule->actions as $assignment) {
            $targets = $assignment->targets ?? null;
            $messages = $assignment->messages ?? null;
        }

        $record = [
            'targets' => json_encode($targets),
            'messages' => json_encode($messages),
            'userid' => $userid,
            'ruleid' => $rule->get_id(),
            'unitid' => $rule->get_unitid(),
            'active' => $rule->get_isactive(),
            'assigneddate' => time(),
            'status' => 0,
            'duedate' => $this->set_due_date($rulejson),
            'usermodified' => $USER->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ];

        // At this point, before handling the processing of the assigment, we need to check if we already have one.
        $assignment = standard_assignment::get_assignment_by_userid_ruleid((object)$record);
        if (!empty($assignment)) {
            $record['id'] = $assignment->id;
            $record['keepchanges'] = $assignment->keepchanges;
            $record['assigneddate'] = $assignment->assigneddate;
            $record['timecreated'] = $assignment->timecreated;
            $record['duedate'] = $assignment->duedate;
        }
        if (
            empty($assignment) ||
            $this->is_planned_assignment($assignment)
        ) {
            $checkableassignment = empty($assignment) ? (object)$record : $assignment;
            $record = assignment_status_facade::set_initial_status($checkableassignment, $rulejson);
        }

        // Only if we don't keep changes, we update.
        if (
            empty($assignment->keepchanges)
        ) {
            // With this, we only check for completion.
            $completionoperator = new completion_operator(0, $userid, 0);
            [$newstatus, $targetstatuschange] = $completionoperator->get_assignment_status(
                $targets,
                (object)$record
            );
            // Even when we have "keep changes", we still want to set the completion to completed.
            if ($newstatus == assignment_status::STATUS_COMPLETED) {
                $record['status'] = $newstatus;
            }
            $record['targets'] = json_encode($targets);
            $record['id'] = assignments_facade::update_or_create_assignment($record);
        }
        if ($this->is_planned_assignment((object)$record)) {
            $activationdelay = $rulejson->rulejson->rule->activationdelay ?? 0;
            $task = new open_planned_assignment();
            $customdata = [
                'assignmentid' => $record['id'],
            ];
            $task->set_custom_data($customdata);
            $task->set_next_run_time(time() + $activationdelay);
            manager::reschedule_or_queue_adhoc_task($task);
        } else {
            $assignmentaction = new action_operator($userid);
            $assignmentaction->check_and_trigger_actions($rule);
        }
        return $record;
    }

    /**
     * Updates or creates unit member
     * @return bool
     */
    private function is_planned_assignment($assignment) {
        $statusmanager = planned::get_instance();
        return $assignment->status == $statusmanager->get_identifier();
    }

    /**
     * Updates or creates unit member
     * @return array
     */
    public function get_open_and_active_assignments() {
        $assignmentinstance = new assignment_operator();
        return $assignmentinstance->get_open_and_active_assignments();
    }

    /**
     * Get the assigneddate of the rule.
     * @param stdClass $rulejson
     * @return int
     */
    private function set_due_date($rulejson) {
        $ruleduedate = $rulejson->rulejson->rule;
        switch ($ruleduedate->duedatetype ?? '') {
            case 'fixeddate':
                return (int) $ruleduedate->fixeddate;
            case 'duration':
                return time() + (int) $ruleduedate->duration;
            default:
                return 0;
        }
    }

    /**
     * Updates or creates unit member
     * @param int $userid
     * @param mixed $rule
     * @return void
     */
    public function inactivate_existing_assignment($userid, $rule): void {
        global $DB;
        $ruleid = is_array($rule) ? 0 : $rule->get_id();
        $records = $DB->get_records(
            'local_taskflow_assignment',
            [
                'userid' => $userid,
                'ruleid' => $ruleid,
            ]
        );
        foreach ($records as $record) {
            if ($record->active == '1') {
                $record->active = 0;
                assignments_facade::update_or_create_assignment($record);
            }
        }
    }

    /**
     * Updates or creates unit member
     * @param int $userid
     * @param mixed $rule
     * @return bool
     */
    public function has_user_assignment($userid, $rule): bool {
        global $DB;
        $records = $DB->get_records(
            'local_taskflow_assignment',
            [
                'userid' => $userid,
                'ruleid' => $rule->get_id(),
            ],
            '',
            'id'
        );
        return empty($records) ? false : true;
    }
}
