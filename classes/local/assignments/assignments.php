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

/**
 * Class unit
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignments {

    public function __construct(int $assignmentid = 0) {
        if (!empty($assignmentid)) {
            [$select, $from, $where, $params] = $this->return_assignments_sql(0, 0, true, $assignmentid);
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

        return $this->return_assignments_sql($userid, 0, $active);
    }

    /**
     * Returns the SQL query to fetch assignments for a given supervisor.
     * This will return all the assigments that are assigned to subordonates of the supervisor.
     * Optionally, we can filter by user ID and active status.
     *
     * @param int $supervisorid
     * @param int $userid
     * @param bool $active
     *
     * @return array
     *
     */
    public function return_supervisor_assignments_sql(int $supervisorid, int $userid = 0, bool $active = true): array {
        global $DB;

        return $this->return_assignments_sql($userid, $supervisorid, $active);
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
    private function return_assignments_sql(int $userid = 0, int $supervisorid = 0, bool $active = true, int $assignmentid = 0): array {
        global $DB;

        $select = "ta.id, tr.rulename, u.id userid, u.firstname, u.lastname, CONCAT(u.firstname, ' ', u.lastname) as fullname, ta.assigned_date, ta.active, ta.targets, tr.rulejson";
        $from = '{local_taskflow_assignment} ta
                 JOIN {user} u ON ta.userid = u.id
                 JOIN {local_taskflow_rules} tr ON ta.ruleid = tr.id
                 ';

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

            if (!empty($supervisorid)) {
                $supervisorfield = get_config('local_taskflow', 'supervisor_field');

                $from .= '  JOIN {user_info_data} uidata ON uidata.userid = ta.userid
                            JOIN {user_info_field} uif ON uif.id = uidata.fieldid';
                $wherearray[] = "uif.shortname = :supervisorfield";
                $wherearray[] = "uidata.data = :supervisorid";

                $params['supervisorid'] = $supervisorid;
                $params['supervisorfield'] = $supervisorfield;
            }
        }

        $where = implode(' AND ', $wherearray);

        return [$select, $from, $where, $params];
    }


}
