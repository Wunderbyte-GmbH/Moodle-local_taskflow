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

 namespace local_taskflow\local\assignment_process\repository;

 use local_taskflow\local\assignments\assignments_factory;

/**
 * Repository for dependecy injection
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignment_repository implements assignment_interface {
    /**
     * Updates or creates unit member
     * @param int $userid
     * @param \local_taskflow\local\rules\unit_rules $rule
     * @return void
     */
    public function construct_and_process_assignment($userid, $rule): void {
        global $USER;
        $rulejson = json_decode($rule->get_rulesjson());
        $targets = [];
        $messages = [];

        foreach ($rulejson->rulejson->rule->actions as $assignments) {
            $targets = $assignments->targets ?? null;
            $messages = $assignments->messages ?? null;
        }

        $record = [
            'targets' => json_encode($targets),
            'messages' => json_encode($messages),
            'userid' => $userid,
            'ruleid' => $rule->get_id(),
            'unitid' => $rule->get_unitid(),
            'active' => $rule->get_isactive(),
            'assigned_date' => time(),
            'usermodified' => $USER->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ];

        $assignmentcontroller = assignments_factory::update_or_create_assignment($record);
    }
}
