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

use local_taskflow\local\supervisor\supervisor;

/**
 * Class to handle task of intervally notification of user.
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class notification_internal_messages extends \core\task\scheduled_task {
    /**
     * Get's the name.
     * @return string
     *
     */
    public function get_name() {
        return get_string('notificationinternalmessages', 'local_taskflow');
    }

    /**
     * Executes the periodical notification of new internal messages.
     * @return void
     */
    public function execute() {
        $since = $this->get_last_task_runtime();
        $newassignmentmessages = $this->get_messages_since_last_run($since);
        $lastassignmentsseen = $this->get_last_seen_state_since_last_run($since);
        $sendnotification = [];
        foreach ($newassignmentmessages as $assignmentid => $newmessages) {
            $assigneid = $lastassignmentsseen[$assignmentid]->assignment_userid;
            $supervisor = supervisor::get_supervisor_for_user($assigneid);
            foreach ($newmessages as $senderid => $messagetime) {
                if (
                    $senderid != $assigneid &&
                    $messagetime > $lastassignmentsseen[$assignmentid]->usersseen[$assigneid]
                ) {
                    $sendnotification[$assigneid][] = $assignmentid;
                }
                if (
                    $senderid != $supervisor->id &&
                    $messagetime > $lastassignmentsseen[$assignmentid]->usersseen[$supervisor->id]
                ) {
                    $sendnotification[$assigneid][] = $assignmentid;
                }
            }
        }
        $testing = 'test';
        //Check if one message comes from another user
        //Write a notification internal message to the assigned user

    }

    /**
     * Checks the interval to look at.
     * @return int
     */
    private function get_last_task_runtime(): int {
        global $DB;

        $task = $DB->get_record(
            'task_scheduled',
            ['classname' => 'local_taskflow\task\notification_internal_messages'],
            'lastruntime',
            IGNORE_MISSING
        );

        return !empty($task->lastruntime) ? (int)$task->lastruntime : time() - DAYSECS;
    }

    /**
     * Gets the latest send messages
     * @param int $since $name
     * @return array
     */
    private function get_messages_since_last_run($since): array {
        global $DB;
        $sql = "
            SELECT
                ic.id AS messageid,
                ic.assignmentid,
                ic.message,
                ic.timecreated,
                ic.usermodified AS senderid
            FROM {local_taskflow_int_com} ic
            WHERE ic.timecreated >= :since
            ORDER BY ic.assignmentid, ic.timecreated
        ";
        $records = $DB->get_records_sql($sql, ['since' => $since]);
        $assignmentsorted = [];
        foreach ($records as $record) {
            if (
                !isset($assignmentsorted[$record->assignmentid][$record->senderid]) ||
                $assignmentsorted[$record->assignmentid][$record->senderid] < $record->timecreated
            ) {
                $assignmentsorted[$record->assignmentid][$record->senderid] = $record->timecreated;
            }
        }

        return $assignmentsorted;
    }

    /**
     * Gets the last_seen records
     * @param int $since $name
     * @return array
     */
    private function get_last_seen_state_since_last_run($since): array {
        global $DB;
        $userseenpair = $DB->sql_concat(
            'ls.userid',
            "'|'",
            'ls.lastseen'
        );

        $usersseen = $DB->sql_group_concat(
            $userseenpair,
            "_",
            'ls.userid'
        );

        $sql = "
            SELECT
                ls.assignmentid,
                ta.userid        AS assignment_userid,
                {$usersseen}     AS usersseen
            FROM {local_taskflow_last_seen} ls
            JOIN {local_taskflow_assignment} ta
                ON ta.id = ls.assignmentid
            WHERE ls.lastseen >= :since
            GROUP BY
                ls.assignmentid,
                ta.userid
            ORDER BY ls.assignmentid
        ";
        $records = $DB->get_records_sql($sql, ['since' => $since]);
        foreach ($records as $record) {
            $userseenarray = explode('_', $record->usersseen);
            $usersseen = [];
            foreach ($userseenarray as $userseen) {
                [$userid, $lastseen] = explode('|', $userseen);
                $usersseen[(int)$userid] = (int)$lastseen;
            }
            $records[$record->assignmentid]->usersseen = $usersseen;
        }
        return $records;
    }
}
