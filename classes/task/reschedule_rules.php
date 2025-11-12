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

namespace local_taskflow\task;

use core\task\manager;
use local_taskflow\event\rule_created_updated;
use mod_booking\singleton_service;

/**
 * Class send_taskflow_message
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reschedule_rules extends \core\task\scheduled_task {
    /**
     * Get's the name.
     *
     * @return string
     *
     */
    public function get_name() {
        return get_string('reschedulerules', 'local_taskflow');
    }

    /**
     * Executes the update for all rules who have a filter with the operator nowminusdays.
     * @return void
     */
    public function execute() {
        global $DB;
        $relevantrules = $this->get_relevant_rules();
        foreach ($relevantrules as $rule) {
            $assignments = $DB->get_records('local_taskflow_assignment', ['ruleid' => $rule->id]);
            // Run through all assignments based on this rule.
            foreach ($assignments as $assignment) {
                $assigneddate = $assignment->assigneddate;

                $user = singleton_service::get_instance_of_user($assignment->userid, true);
                $entrydate = $user->profile['EntryDate'];
                $ruledata = json_decode($rule->rulejson);
                if (!empty($ruledata['rulejson']['rule']['filter']) && is_array($ruledata['rulejson']['rule']['filter'])) {
                    foreach ($ruledata['rulejson']['rule']['filter'] as $filter) {
                        if (!empty($filter['operator']) && $filter['operator'] === 'nowminusdays') {
                            $daysafter = $filter['values'];
                            break;
                        }
                    }
                }
                $assignmentdate = $entrydate + $daysafter;
                if ($assignmentdate != $assigneddate) {
                    $ruledata = json_decode($rule->rulejson);
                    $duration = $ruledata->rulejson->rule->duration;

                    // Update assigned date.
                    $assignment->assigneddate = $assignmentdate;
                    $assignment->timecreated = $entrydate;
                    $assignment->duedate = $assignmentdate + $duration + 86400; // Add one day to include the entry date.
                    $DB->update_record('local_taskflow_assignment', $assignment);
                    $task = new check_assignment_status();
                    $customdata = [
                    'userid' => (string) $assignment->userid,
                    'ruleid' => (string) $assignment->ruleid,
                    ];
                    $customdata['assignmentid'] = (string) $assignment->id ?? '';
                    $customdata['scheduledtime'] = (string) $assignment->duedate ?? '';
                    $task->set_custom_data($customdata);
                    $task->set_next_run_time($assignment->duedate);
                    manager::reschedule_or_queue_adhoc_task($task);
                }
            }

            $event = rule_created_updated::create([
                'objectid' => $rule->id,
                'context'  => \context_system::instance(),
                'other'    => [
                    'ruledata' => $rule,
                ],
                        ]);
            $event->trigger();
        }
    }

    /**
     * Checks for all rules with the nowminusdays operator in the filter.
     *
     * @param int $supervisor
     *
     * @return array
     *
     */
    private function get_relevant_rules() {
        global $DB;

        $records = $DB->get_records('local_taskflow_rules');
        $filteredrecords = [];

        foreach ($records as $record) {
            if (empty($record->rulejson)) {
                continue;
            }
            $data = json_decode($record->rulejson, true);
            if (!empty($data['rulejson']['rule']['filter']) && is_array($data['rulejson']['rule']['filter'])) {
                foreach ($data['rulejson']['rule']['filter'] as $filter) {
                    if (!empty($filter['operator']) && $filter['operator'] === 'nowminusdays') {
                        $filteredrecords[] = $record;
                        break;
                    }
                }
            }
        }
        return $filteredrecords;
    }
}
