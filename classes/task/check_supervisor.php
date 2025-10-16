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

use context_system;
use local_taskflow\local\external_adapter\external_api_base;
use local_taskflow\plugininfo\taskflowadapter;


/**
 * Class send_taskflow_message
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class check_supervisor extends \core\task\adhoc_task {
    /**
     * Get's the name.
     *
     * @return string
     *
     */
    public function get_name() {
        return get_string('taskchecksupervisor', 'local_taskflow');
    }

    /**
     * Executes the check if a user is still a valid supervisor.
     * @return void
     */
    public function execute() {
        global $DB;
        $context = context_system::instance();
        $supervisorroleid = get_config('local_taskflow', 'supervisorrole');
        $supervisorroles = $this->get_all_users_with_supervisorroles();
        $supervisor = $this->get_all_supervisors();
        $difference = array_diff($supervisorroles, $supervisor);
        foreach ($difference as $user) {
                role_unassign($supervisorroleid, $user, $context->id);
        }
    }
    /**
     * Gets all the user id's with supervisor role.
     *
     * @return array
     *
     */
    private function get_all_users_with_supervisorroles() {
        $supervisorroleid = get_config('local_taskflow', 'supervisorrole');
        $context = context_system::instance();
        $users = get_role_users($supervisorroleid, $context, false);
        return array_keys($users);
    }


    /**
     * Checks if still in Supervisorfield.
     *
     * @param int $supervisor
     *
     * @return array
     *
     */
    private function get_all_supervisors() {
        global $DB;
        $shortname = external_api_base::return_shortname_for_functionname(taskflowadapter::TRANSLATOR_USER_SUPERVISOR);
        $fieldidrecord = $DB->get_record('user_info_field', ['shortname' => $shortname], 'id');
        $fieldid = $fieldidrecord->id;
        $sql = 'SELECT DISTINCT uid.data
        FROM {user_info_data} uid
        JOIN {user} u ON u.id = uid.userid
        WHERE uid.fieldid = :fieldid
        AND u.suspended = 0';
        $params = ['fieldid' => $fieldid];
        $records = $DB->get_records_sql($sql, $params);
        return array_keys($records);
    }
}
