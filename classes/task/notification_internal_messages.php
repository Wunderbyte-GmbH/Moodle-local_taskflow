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

use core\message\message;
use core_filters\null_filter_manager;
use core_user;
use local_taskflow\local\messages\notifiaction_message\notification_strategy_factory;
use local_taskflow\local\supervisor\supervisor;
use local_taskflow\taskflow_stringmanager;

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
        return taskflow_stringmanager::get_string('notificationinternalmessages');
    }

    /**
     * Executes the periodical notification of new internal messages.
     * @return void
     */
    public function execute() {
        global $DB;
        $since = $this->get_last_task_runtime();
        $newassignmentmessages = $this->get_messages_since_last_run($since);
        $lastassignmentsseen = $this->get_last_seen_state_since_last_run($since);
        $sendnotifications = [];
        foreach ($newassignmentmessages as $assignmentid => $newmessages) {
            $assigneeid = $lastassignmentsseen[$assignmentid]->assignment_userid;
            $supervisor = supervisor::get_supervisor_for_user($assigneeid);
            foreach ($newmessages as $senderid => $messagetime) {
                if (
                    !isset($lastassignmentsseen[$assignmentid]->usersseen[$assigneeid]) ||
                    (
                        $senderid != $assigneeid &&
                        $messagetime > $lastassignmentsseen[$assignmentid]->usersseen[$assigneeid]
                    )
                ) {
                    $sendnotifications[$assigneeid]['assignee'][] = $assignmentid;
                    $sendnotifications['admin'][] = $assignmentid;
                }
                if (
                    isset($supervisor->id) &&
                    (
                        !isset($lastassignmentsseen[$assignmentid]->usersseen[$supervisor->id ?? 0]) ||
                        (
                            $senderid != $supervisor->id &&
                            $messagetime > $lastassignmentsseen[$assignmentid]->usersseen[$supervisor->id]
                        )
                    )
                ) {
                    $sendnotifications[$supervisor->id]['supervisor'][] = $assignmentid;
                    $sendnotifications['admin'][] = $assignmentid;
                }
            }
        }

        foreach ($sendnotifications as $userid => $types) {
            if ($userid != 'admin') {
                foreach ($types as $type => $assignmentids) {
                    $assignmentids = array_unique($assignmentids);
                    $this->notify_with_strategy((int)$userid, $type, $assignmentids);
                }
            }
        }
        if (isset($sendnotifications['admin'])) {
            foreach (get_admins() as $admin) {
                $allids = array_unique(array_values($sendnotifications['admin']));
                $this->notify_with_strategy((int)$admin->id, 'admin', $allids);
            }
        }
    }

    /**
     * Strategy-based notification sender.
     * @param int $userid User ID to notify
     * @param string $type Type of notification (assignee, supervisor, admin)
     * @param array $assignmentids Assignment IDs to include in notification
     */
    private function notify_with_strategy(
        int $userid,
        string $type,
        array $assignmentids
    ): void {

        if (empty($assignmentids)) {
            return;
        }
        $records  = $this->get_data_from_assignments($assignmentids);

        if (empty($records)) {
            return;
        }

        $strategy = notification_strategy_factory::create($type);

        $userto = core_user::get_user($userid);
        $msg = new message();
        $msg->component = 'local_taskflow';
        $msg->name      = $strategy->get_message_provider();
        $msg->userfrom  = core_user::get_noreply_user();
        $msg->userto    = $userto;
        $msg->subject   = taskflow_stringmanager::get_string('notificationmessageheading', null, $userto->lang);
        $msg->fullmessagehtml = $strategy->build_email_body($records, $userto->lang, $userto);
        $msg->fullmessageformat = FORMAT_HTML;
        $msg->smallmessage = $strategy->build_notification_body($records, $userto->lang, $userto);
        $msg->notification = 1;

        message_send($msg);
    }

    /**
     * Gets assignment related data from db.
     * @param array $assignmentids Assignment IDs
     * @return int
     */
    private function get_data_from_assignments(array $assignmentids): array {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal($assignmentids, SQL_PARAMS_NAMED);

        $sql = "
            SELECT
                a.id AS assignmentid,
                r.rulename,
                u.firstname,
                u.lastname
            FROM {local_taskflow_assignment} a
            JOIN {local_taskflow_rules} r ON r.id = a.ruleid
            JOIN {user} u ON u.id = a.userid
            WHERE a.id $insql
            ORDER BY r.rulename, u.lastname, u.firstname
        ";

        return $DB->get_records_sql($sql, $params);
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
