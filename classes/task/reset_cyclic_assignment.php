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

use local_taskflow\local\assignments\assignments_facade;
use local_taskflow\local\assignments\types\standard_assignment;
use local_taskflow\local\messages\messages_facade;
use local_taskflow\local\rules\rules;

/**
 * Class send_taskflow_message
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reset_cyclic_assignment extends \core\task\adhoc_task {
    /**
     * Execute sending messags function
     * @return void
     */
    public function execute() {
        global $DB;
        $data = (object) $this->get_custom_data();
        $assignment = standard_assignment::get_assignment_record_by_assignmentid($data->assignmentid);
        if ($this->rule_is_still_cyclic($assignment->ruleid)) {
            $assignment->overduecounter = 0;
            assignments_facade::reopen_assignment($assignment);
            messages_facade::removed_send_messages($assignment);
        }
    }

    /**
     * Checvk if rule is still cyclic
     * @param string $ruleid
     * @return bool
     */
    private function rule_is_still_cyclic($ruleid) {
        $rule = rules::instance($ruleid);
        if ($rule) {
            $rulejson = json_decode($rule->get_rulesjson());
            $iscyclic = $rulejson->rulejson->rule->cyclicvalidation ?? false;
            if ($iscyclic == "1") {
                return true;
            }
        }
        return false;
    }
}
