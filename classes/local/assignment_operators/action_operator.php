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
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\local\assignment_operators;

use core\task\manager;
use local_taskflow\local\actions\actions_factory;
use local_taskflow\local\assignments\types\standard_assignment;
use local_taskflow\local\messages\messages_factory;
use local_taskflow\task\check_assignment_status;

/**
 * Class unit
 *
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class action_operator {
    /** @var string Event name for user updated. */
    public int $userid;
    /**
     * Get the instance of the class for a specific ID.
     * @param int $userid
     */
    public function __construct($userid) {
        $this->userid = $userid;
    }

    /**
     * Get the instance of the class for a specific ID.
     * @param mixed $rule
     * @return void
     */
    public function check_and_trigger_actions($rule) {
        if ($rule->get_isactive() != '1') {
            return;
        }
        $rulejson = json_decode($rule->get_rulesjson());
        $rulejson = $rulejson->rulejson ?? null;
        if ($rulejson == null) {
            return;
        }

        foreach ($rulejson->rule->actions as $action) {
            $schedulemessages = false;
            foreach ($action->targets as $target) {
                $actioninstance = actions_factory::instance($target, $this->userid);
                if ($actioninstance) {
                    if ($actioninstance->is_active()) {
                        $actioninstance->execute();
                        $schedulemessages = true;
                    }
                }
                if ($this->check_can_not_continue($target)) {
                    break;
                }
            }

            if ($schedulemessages) {
                foreach ($action->messages as $message) {
                    $assignmentmessageinstance = messages_factory::instance(
                        $message,
                        $this->userid,
                        $rule->get_id()
                    );
                    if (
                        $assignmentmessageinstance != null &&
                        $assignmentmessageinstance->is_scheduled_type() &&
                        !$assignmentmessageinstance->was_already_send()
                    ) {
                        $assignmentmessageinstance->schedule_message($action);
                    }
                }
            }
        }

        // We make sure we have a task at the end of the action to update the assignment status.
        $task = new check_assignment_status();
        $customdata = [
            'userid' => (string) $this->userid,
            'ruleid' => (string) $rule->get_id(),
        ];
        $assignment = standard_assignment::get_assignment_by_userid_ruleid((object)$customdata);

        if (empty($assignment->id)) {
            return;
        }
        $customdata['assignmentid'] = (string) $assignment->id ?? '';

        $task->set_custom_data($customdata);
        $task->set_next_run_time($assignment->duedate);
        manager::reschedule_or_queue_adhoc_task($task);
    }

    /**
     * Get the instance of the class for a specific ID.
     * @param object $assignment
     * @return void
     */
    public function check_and_trigger_targets($assignment) {
        $targets = json_decode($assignment->targets);
        foreach ($targets as $target) {
            $actioninstance = actions_factory::instance($target, $this->userid);
            if ($actioninstance) {
                if ($actioninstance->is_active()) {
                    $actioninstance->execute();
                }
            }
            if ($this->check_can_not_continue($target)) {
                break;
            }
        }
    }

    /**
     * Get the instance of the class for a specific ID.
     * @param object $target
     * @return bool
     */
    private function check_can_not_continue($target) {
        $completionstatus = $target->completionstatus ?? 0;
        if (
            isset($target->completebeforenext) &&
            $target->completebeforenext == '1' &&
            $completionstatus == 0
        ) {
            return true;
        }
        return false;
    }
}
