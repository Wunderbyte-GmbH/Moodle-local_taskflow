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

namespace local_taskflow\local\assignments;

use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\assignments\status\assignment_status;
use local_taskflow\local\external_adapter\external_api_base;
use local_taskflow\task\check_assignment_status;
use local_taskflow\plugininfo\taskflowadapter;
use local_taskflow\local\history\history;
use core\task\manager;
use cache_helper;
use stdClass;

/**
 * Class unit
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignment {
    /** @var \stdClass */
    private stdClass $assignment;

    /** @var int $id Unique identifier for the assignment, automatically managed by the database. */
    public $id;

    /** @var string|null $targets Contains target-related data, possibly stored as JSON or serialized string. */
    public $targets;

    /** @var string|null $messages Contains message-related data, possibly stored as JSON or serialized string. */
    public $messages;

    /** @var int|null $userid ID of the user associated with the assignment. */
    public $userid;

    /** @var int|null $ruleid ID of the rule associated with the assignment. */
    public $ruleid;

    /** @var string|null $rulejson ID of the rule json associated with the assignment. */
    public $rulejson;

    /** @var int|null $unitid ID of the unit associated with the assignment. */
    public $unitid;

    /** @var int|null $active Indicates whether the assignment is active. Typically a boolean represented as an integer (0 or 1). */
    public $active;

    /** @var int|null $assigneddate Timestamp representing when the assignment was issued. */
    public $assigneddate;

    /** @var int|null $duedate Timestamp representing when the assignment was issued. */
    public $duedate;

    /** @var int|null $usermodified ID of the user who last modified the assignment. */
    public $usermodified;

    /** @var int|null $timecreated Timestamp for when the assignment was created. */
    public $timecreated;

    /** @var int|null $timemodified Timestamp for when the assignment was last modified. */
    public $timemodified;

    /** @var int $status Current status of the assignment, used for tracking and management. */
    public $status;

    /** @var int $keepchanges Current status of the assignment, used for tracking and management. */
    public $keepchanges;

    /** @var string $select Current status of the assignment, used for tracking and management. */
    private $select;
    /** @var string $from Current status of the assignment, used for tracking and management. */
    private $from;

     /** @var int $overduecounter , used for tracking and management. */
    public $overduecounter;

     /** @var int $prolongedcounter, used for tracking and management. */
    public $prolongedcounter;

    /**
     * Constructor for the assignment class.
     *
     * @param int $assignmentid
     *
     */
    public function __construct(int $assignmentid = 0) {
        global $DB;

        $this->select = "*";

        $concat = $DB->sql_concat("u.firstname", "' '", "u.lastname");

        $this->set_from_sql();

        if ($assignmentid > 0) {
            $this->load_from_db($assignmentid);
        }
    }

    /**
     * Returns the SQL query to fetch assignments of a given user.
     * @param int $userid
     * @param int $active
     *
     * @return array
     *
     */
    public function return_user_assignments_sql(int $userid, int $active = 1): array {
        global $DB;
        return $this->return_assignments_sql($userid, $active, 0);
    }

    /**
     * Returns the SQL query to fetch assignments for a given supervisor.
     * This will return all the assigments that are assigned to subordonates of the supervisor.
     * Optionally, we can filter by user ID and active status.
     * @param int $supervisorid
     * @param array $arguments
     * @return array
     */
    public function return_supervisor_assignments_sql(int $supervisorid, array $arguments = []): array {
        global $DB;
        $params = [];
        switch ($arguments['active']) {
            case 0:
            case 1:
                $wherearray = ['active = :status'];
                $params = ['status' => $arguments['active']];
                break;
            // 2 means no limit for status.
        }

        if (!empty($arguments['overdue'])) {
            $wherearray = ['(duedate < :duedate OR status = :statusoverdue)'];
            $params = [
                'duedate' => time(),
                'statusoverdue' => assignment_status::STATUS_OVERDUE,
            ];
        }

        $this->get_sql_parameter_array($params);

        // We need to make sure that we already have the supervisor field.
        $assignmentfields = get_config('local_taskflow', 'assignment_fields');
        $assignmentfields = array_filter(array_map('trim', explode(',', $assignmentfields)));
        $supervisorfield = external_api_base::return_shortname_for_functionname(taskflowadapter::TRANSLATOR_USER_SUPERVISOR);

        if (!in_array($supervisorfield, $assignmentfields)) {
            // If the supervisor field is not in the assignment fields, we cannot filter by it.
            $wherearray[] = " userid IN (
                SELECT uidata.userid
                FROM {user_info_data} uidata
                JOIN {user_info_field} uif ON uif.id = uidata.fieldid
                WHERE uif.shortname = :supervisorfield AND uidata.data = :supervisorid ) ";
            $params['supervisorfield'] = $supervisorfield;
        } else {
            $wherearray[] = "custom_$supervisorfield = :supervisorid";
        }

        $params['supervisorid'] = $supervisorid;

        $where = implode(' AND ', $wherearray);

        // We need to alter the logic so we can apply filter etc.

        return [$this->select, $this->from, $where, $params];
    }

    /**
     * Generic SQL query to fetch assignments based on user ID and supervisor ID.
     * This method constructs the SQL query to retrieve assignments based on the provided parameters.
     * @param int $userid
     * @param int $active
     * @param int $assignmentid
     *
     * @return array
     *
     */
    private function return_assignments_sql(
        int $userid = 0,
        int $active = 1,
        int $assignmentid = 0
    ): array {
        global $DB;
        $params = [];
        // When we want a given assigmentid, we ignore all the other params.
        if (!empty($assignmentid)) {
            $wherearray[] = "id = :assignmentid";
            $params['assignmentid'] = $assignmentid;
        } else {
            switch ($active) {
                case 0:
                case 1:
                    $wherearray = ['active = :status'];
                    $params = ['status' => $active];
                    break;
                // 2 means no limit for status.
            }
            if (!empty($userid)) {
                $wherearray[] = "userid = :userid";
                $params['userid'] = $userid;
            }

            $this->get_sql_parameter_array($params);
        }

        if (!empty($wherearray)) {
            $where = implode(' AND ', $wherearray);
        }

        return [$this->select, $this->from, $where ?? ' 1 = 1 ', $params ?? []];
    }

    /**
     * Generic SQL query to fetch assignments based on user ID and supervisor ID.
     * @param array $params
     * @return void
     */
    private function get_sql_parameter_array(array &$params): void {
        $assignmentfields = get_config('local_taskflow', 'assignment_fields');
        $assignmentfields = array_filter(array_map('trim', explode(',', $assignmentfields)));

        $additionalselect = '';

        if (!empty($assignmentfields)) {
            $i = 0;
            foreach ($assignmentfields as $fieldshortname) {
                // SQL query. The subselect will fix the "Did you remember to make the first column something...
                // ...unique in your call to get_records?" bug.
                $additionalselect .= " , (
                    SELECT uid.data
                    FROM {user_info_data} uid
                    JOIN {user_info_field} uif ON uid.fieldid = uif.id
                    WHERE uid.userid = ta.userid AND uif.shortname = :fieldshortname{$i}
                    LIMIT 1
                ) AS custom_{$fieldshortname} ";

                $params["fieldshortname{$i}"] = $fieldshortname;
                $i++;
            }
        }
        $this->set_from_sql($additionalselect);
    }

    /**
     * Loads the assignment data from the database based on the assignment ID.
     * @param int $assignmentid
     * @return void
     *
     */
    public function load_from_db($assignmentid = 0) {
        global $DB;
        [$select, $from, $where, $params] = $this->return_assignments_sql(0, 1, $assignmentid);

        $record = $DB->get_record_sql("SELECT {$select} FROM {$from} WHERE {$where}", $params);

        if ($record) {
            $this->id = $record->id;
            $this->targets = $record->targets;
            $this->messages = $record->messages;
            $this->userid = $record->userid;
            $this->ruleid = $record->ruleid;
            $this->unitid = $record->unitid;
            $this->active = $record->active;
            $this->assigneddate = $record->assigneddate;
            $this->duedate = $record->duedate;
            $this->usermodified = $record->usermodified;
            $this->timecreated = $record->timecreated;
            $this->timemodified = $record->timemodified;
            $this->status = $record->status;
            $this->rulejson = $record->rulejson;
            $this->keepchanges = $record->keepchanges;
        }
    }

    /**
     * Returns the assignment data as a stdClass object for further processing or output.
     *
     * @return stdClass
     *
     */
    public function return_class_data(): stdClass {
        $data = new stdClass();
        $data->id = $this->id;
        $data->targets = $this->targets;
        $data->messages = $this->messages;
        $data->userid = $this->userid;
        $data->ruleid = $this->ruleid;
        $data->unitid = $this->unitid;
        $data->active = $this->active;
        $data->assigneddate = $this->assigneddate;
        $data->duedate = $this->duedate;
        $data->usermodified = $this->usermodified;
        $data->timecreated = $this->timecreated;
        $data->timemodified = $this->timemodified;
        $data->status = $this->status;
        $data->rulejson = $this->rulejson;
        $jsonobject = json_decode($this->rulejson, true);
        $data->name = $jsonobject['rulejson']['rule']['name'] ?? '';
        $data->ruledescription = $jsonobject['rulejson']['rule']['description'] ?? '';
        $data->targetgroup = $this->userid;
        $data->fullname = fullname(\core_user::get_user($this->userid));
        $data->keepchanges = $this->keepchanges;
        $data->overduecounter = $this->overduecounter;
        $data->prolongedcounter = $this->prolongedcounter;
        return $data;
    }

    /**
     * Add or update an assignment in the database.
     *
     * @param array $data
     * @param string $historytype
     * @param bool $manualupdate
     * @return stdClass
     *
     */
    public function add_or_update_assignment(
        array $data,
        string $historytype = history::TYPE_MANUAL_CHANGE,
        bool $manualupdate = false,
    ): stdClass {
        global $DB, $USER;

        if (empty($data['id'])) {
            // Create a new assignment.
            $data['timecreated'] = $data['timecreated'] ?? time();
            $data['timemodified'] = $data['timemodified'] ?? time();
            $data['status'] = $data['status'] ?? 0;
            $data['active'] = $data['active'] ?? 1;
            $this->id = $DB->insert_record('local_taskflow_assignment', (object)$data);
            history::log(
                $this->id,
                $data['userid'],
                $historytype,
                [
                    'action' => 'created',
                    'data' => $data,
                ],
                $data['usermodified'] ?? null
            );
            $this->set_check_assignment_status_task();
        } else {
            // Update an existing assignment.
            $data['timemodified'] = time();
            $data['usermodified'] = $data['usermodified'] ?? $USER->id;

            // For automatic updates, check if data should be kept.
            if (
                !empty($data['keepchanges'])
                && !$manualupdate
            ) {
                unset($data['duedate']);
                unset($data['active']);
            }

            if (
                $this->status_changed($data)
                || $this->duedate != ($data['duedate'] ?? $this->duedate)
                || $this->active != ($data['active'] ?? $this->active)
                || $this->messages != ($data['messages'] ?? $this->messages)
                || $this->targets != ($data['targets'] ?? $this->targets)
                || $this->keepchanges != ($data['keepchanges'] ?? $this->keepchanges)
            ) {
                // Only run the update when there is actually sth to update.
                $this->set_check_assignment_status_task();
                $this->set_prolonged_state_on_change($data);
                // Only if there is sth to update, we update.
                $DB->update_record('local_taskflow_assignment', (object)$data);
                history::log(
                    $this->id,
                    $data['userid'],
                    $historytype,
                    [
                        'action' => 'updated',
                        'data' => $data,
                    ],
                    $data['usermodified'] ?? null
                );
            } else {
                // If there are not changes, we return directly.
                return $this->return_class_data();
            }
        }
        // Reload the assignment data.
        $this->load_from_db($this->id);
        cache_helper::purge_by_event('changesinassignmentslist');
        return $this->return_class_data();
    }

    /**
     * Here, we can introduce an additional select statement to the from SQL.
     * @return void
     */
    private function set_check_assignment_status_task(): void {
        if (
            $this->userid == null &&
            $this->id != null
        ) {
            $this->load_from_db($this->id);
        }
        $task = new check_assignment_status();
        $customdata = [
            'userid' => (string) $this->userid,
            'ruleid' => (string) $this->ruleid,
        ];
        $customdata['assignmentid'] = (string) $this->id ?? '';
        $task->set_custom_data($customdata);
        $task->set_next_run_time($this->duedate);
        manager::reschedule_or_queue_adhoc_task($task);
    }

    /**
     * Here, we can introduce an additional select statement to the from SQL.
     * @param array $data
     * @return void
     */
    private function set_prolonged_state_on_change(&$data): void {
        if (
            $this->status == assignment_status::STATUS_OVERDUE &&
            $data['duedate'] > time()
        ) {
            $data['status'] = assignment_status::STATUS_PROLONGED;
        }
    }

    /**
     * Check if status has changed.
     *
     * @param array $data
     *
     * @return bool
     *
     */
    private function status_changed($data): bool {
        $haschanged = $this->status != ($data['status'] ?? $this->status);
        if ($haschanged) {
            assignment_status_facade::execute($this, $data);
        }
        return $haschanged;
    }

    /**
     * Check if assignment is for this user?
     * @return bool
     */
    public function is_my_assignment(): bool {
        global $USER;
        return ($USER->id === $this->userid);
    }

    /**
     * Here, we can introduce an additional select statement to the from SQL.
     *
     * @param string $additionalselect = ''
     * @return void
     *
     */
    private function set_from_sql(string $additionalselect = ''): void {
        global $DB;

        $concat = $DB->sql_concat("u.firstname", "' '", "u.lastname");
        $modifierfullname = $DB->sql_concat("um.firstname", "' '", "um.lastname");
        $supervisorfullname = $DB->sql_concat('us.firstname', "' '", 'us.lastname');
        $timecreated = $DB->sql_cast_char2int('ta.timecreated');
        $timemodified = $DB->sql_cast_char2int('ta.timemodified');

        $supervisorfield = external_api_base::return_shortname_for_functionname(taskflowadapter::TRANSLATOR_USER_SUPERVISOR);

        $this->from = "(
            SELECT
                ta.id, tr.rulename, u.id userid, u.firstname, u.lastname, $concat as fullname,
                $supervisorfullname as supervisor, ta.messages, ta.ruleid, ta.unitid,
                ta.assigneddate, ta.duedate, ta.active, ta.status, ta.targets,
                tr.rulejson, ta.usermodified, $modifierfullname AS usermodified_fullname,
                $timecreated AS timecreated, $timemodified AS timemodified, ta.keepchanges
                $additionalselect, lth.data, ta.overduecounter, ta.prolongedcounter
            FROM {local_taskflow_assignment} ta
            JOIN {user} u ON ta.userid = u.id
            JOIN {local_taskflow_rules} tr ON ta.ruleid = tr.id
            LEFT JOIN {user} um ON ta.usermodified = um.id
            LEFT JOIN {user_info_field} uif ON uif.shortname = '{$supervisorfield}'
            LEFT JOIN {user_info_data} suid ON suid.userid = u.id AND suid.fieldid = uif.id
            LEFT JOIN {user} us ON us.id = CAST(NULLIF(suid.data, '') AS INT)
            LEFT JOIN (     SELECT lth1.*
                            FROM {local_taskflow_history} lth1
                            INNER JOIN (
                                SELECT assignmentid, MAX(id) AS maxid
                                FROM {local_taskflow_history}
                                GROUP BY assignmentid
                            ) lth2 ON lth1.id = lth2.maxid
                        ) lth ON lth.assignmentid = ta.id
            ) AS s1";
    }
}
