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

use local_taskflow\local\history\history;
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

    /** @var string $select Current status of the assignment, used for tracking and management. */
    private $select = "ta.id, tr.rulename, u.id userid, u.firstname, u.lastname, CONCAT(u.firstname, ' ', u.lastname) as fullname,
        ta.messages, ta.ruleid, ta.unitid, ta.assigneddate, ta.duedate, ta.active, ta.status, ta.targets,
        tr.rulejson, ta.usermodified, ta.timecreated, ta.timemodified";

    /** @var string $from Current status of the assignment, used for tracking and management. */
    private $from = '{local_taskflow_assignment} ta
        JOIN {user} u ON ta.userid = u.id
        JOIN {local_taskflow_rules} tr ON ta.ruleid = tr.id';


    /**
     * Constructor for the assignment class.
     *
     * @param int $assignmentid
     *
     */
    public function __construct(int $assignmentid = 0) {
        if ($assignmentid > 0) {
            $this->load_from_db($assignmentid);
        }
    }

    /**
     * Returns the SQL query to fetch assignments of a given user.
     * @param int $userid
     * @param bool $active
     *
     * @return array
     *
     */
    public function return_user_assignments_sql(int $userid, bool $active = true): array {
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
        // When we want a given assigmentid, we ignore all the other params.

        $wherearray = ['ta.active = :status'];
        $params = ['status' => $arguments['active'] ? 1 : 0];

        if ($arguments['overdue'] == '1') {
            $wherearray = ['ta.duedate < :duedate'];
            $params = ['duedate' => time()];
        }

        $supervisorfield = get_config('local_taskflow', 'supervisor_field');

        $this->from .= '  JOIN {user_info_data} uidata ON uidata.userid = ta.userid
                    JOIN {user_info_field} uif ON uif.id = uidata.fieldid';

        $wherearray[] = "uif.shortname = :supervisorfield";
        $params['supervisorfield'] = $supervisorfield;
        $wherearray[] = "uidata.data = :supervisorid";
        $params['supervisorid'] = $supervisorid;

        $this->get_sql_parameter_array($params);

        $where = implode(' AND ', $wherearray);

        return [$this->select, $this->from, $where, $params];
    }

    /**
     * Generic SQL query to fetch assignments based on user ID and supervisor ID.
     * This method constructs the SQL query to retrieve assignments based on the provided parameters.
     * @param int $userid
     * @param int $supervisorid
     * @param bool $active
     * @param int $assignmentid
     *
     * @return array
     *
     */
    private function return_assignments_sql(
        int $userid = 0,
        bool $active = true,
        int $assignmentid = 0
    ): array {
        global $DB;
        // When we want a given assigmentid, we ignore all the other params.
        if (!empty($assignmentid)) {
            $wherearray[] = "ta.id = :assignmentid";
            $params['assignmentid'] = $assignmentid;
        } else {
            $wherearray = ['ta.active = :status'];
            $params = ['status' => $active ? 1 : 0];

            if (!empty($userid)) {
                $wherearray[] = "u.id = :userid";
                $params['userid'] = $userid;
            }

            $this->get_sql_parameter_array($params);
        }

        $where = implode(' AND ', $wherearray);

        return [$this->select, $this->from, $where, $params];
    }

        /**
     * Generic SQL query to fetch assignments based on user ID and supervisor ID.
     * @param array $params
     * @return void
     */
    private function get_sql_parameter_array(array &$params): void {
        $assignmentfields = get_config('local_taskflow', 'assignment_fields');
        $assignmentfields = array_filter(array_map('trim', explode(',', $assignmentfields)));
        if (!empty($assignmentfields)) {
            $i = 0;
            foreach ($assignmentfields as $fieldshortname) {
                // SQL query. The subselect will fix the "Did you remember to make the first column something...
                // ...unique in your call to get_records?" bug.
                $this->select .= ", (
                    SELECT uid.data
                    FROM {user_info_data} uid
                    JOIN {user_info_field} uif ON uid.fieldid = uif.id
                    WHERE uid.userid = u.id AND uif.shortname = :fieldshortname{$i}
                    LIMIT 1
                ) AS custom_{$fieldshortname}";

                $params["fieldshortname{$i}"] = $fieldshortname;
                $i++;
            }
        }
    }

    /**
     * Loads the assignment data from the database based on the assignment ID.
     * @param int $assignmentid
     * @return void
     *
     */
    public function load_from_db($assignmentid = 0) {
        global $DB;
        [$select, $from, $where, $params] = $this->return_assignments_sql(0, true, $assignmentid);

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
        } else {
            // Optionally handle cases where no record is found.
            throw new \moodle_exception(
                'assignmentnotfound',
                'local_taskflow',
                '',
                null,
                "Assignment with ID {$assignmentid} not found."
            );
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

        return $data;
    }

    /**
     * Add or update an assignment in the database.
     *
     * @param array $data
     * @param string $historytype
     * @return stdClass
     *
     */
    public function add_or_update_assignment(array $data, $historytype = history::TYPE_MANUAL_CHANGE): stdClass {
        global $DB, $USER;

        if (empty($data['id'])) {
            // Create a new assignment.
            $data['timecreated'] = time();
            $data['timemodified'] = time();
            $data['status'] = 0; // Default status.
            $data['active'] = 1; // Default active status.
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
        } else {
            // Update an existing assignment.
            $data['timemodified'] = time();
            $data['usermodified'] = $data['usermodified'] ?? $USER->id;
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
        }

        // Reload the assignment data.
        $this->load_from_db($this->id);

        return $this->return_class_data();
    }
}
