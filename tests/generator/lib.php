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
 * Module booking data generator
 *
 * @package local_taskflow
 * @category test
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_taskflow\local\assignments\assignment;

defined('MOODLE_INTERNAL') || die();

global $CFG;

/**
 * Class to handle module booking data generator
 *
 * @package local_taskflow
 * @category test
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2023 Andrii Semenets
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_taskflow_generator extends testing_module_generator {
    /**
     * Creates a standard assignemnt for a user.
     * @param int $userid
     * @param int $ruleid
     *
     * @return \local_taskflow\local\assignments\assignment
     *
     */
    public function create_user_assignment(int $userid, int $ruleid) {

        $data = [
            'userid' => $userid,
            'ruleid' => $ruleid,
            'unitid' => 1,
            'assigneddate' => time(),
            'duedate' => time() + 3600,
        ];

        $assignment = new assignment();
        $result = $assignment->add_or_update_assignment($data);

        return $assignment;
    }

    /**
     * Creates more or less empty rule.
     * @param array $options
     *
     * @return int
     *
     */
    public function create_rule(array $options = []) {

        global $DB;

        $ruleid = $DB->insert_record('local_taskflow_rules', (object)[
            'rulename' => 'Test Rule',
            'rulejson' => '{}',
        ]);

        return $ruleid;
    }
}
