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
 use local_taskflow\local\assignments\assignments_facade;
 use local_taskflow\local\assignments\status\assignment_status;
 use local_taskflow\local\assignments\types\standard_assignment;
 use local_taskflow\local\completion_process\completion_operator;
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
     * @return void
     */
    public function construct_and_process_assignment($userid, $rule): void {
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
        }

        // Only if we don't keep changes, we update.
        if (
            empty($assignment->keepchanges)
        ) {
            // With this, we only check for completion.
            $completionoperator = new completion_operator(0, $userid, 0);
            $completed = $completionoperator->get_assignment_status(
                $targets,
                (object)$record
            );
            // Even when we have "keep changes", we still want to set the completion to completed.
            if ($completed == assignment_status::STATUS_COMPLETED) {
                $record['status'] = $completed;
            }

            assignments_facade::update_or_create_assignment($record);
        }

        $assignmentaction = new action_operator($userid);
        $assignmentaction->check_and_trigger_actions($rule);
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
}
