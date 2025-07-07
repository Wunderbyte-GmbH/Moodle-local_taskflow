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

namespace local_taskflow\local\completion_process;

use core\task\manager;
use local_taskflow\scheduled_tasks\reset_cyclic_assignment;
use stdClass;

/**
 * Class unit
 *
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scheduling_cyclic_adhoc {
    /** @var stdClass Stores the external user data. */
    protected stdClass $assignmentrule;

    /**
     * Update the current unit.
     * @param stdClass $assignmentrule
     * @return void
     */
    public function __construct($assignmentrule) {
        $this->assignmentrule = $assignmentrule;
    }

    /**
     * Update the current unit.
     * @return void
     */
    public function schedule_cyclic_adhoc() {
        $rule = $this->assignmentrule->rulejson->rulejson->rule ?? '';
        if (
            $rule->cyclicvalidation == '1' &&
            !empty($rule->cyclicduration)
        ) {
            global $DB;
            $task = new reset_cyclic_assignment();

            $customdata = [
                'userid' => $this->assignmentrule->userid,
                'assignmentid' => $this->assignmentrule->assignmentid,
            ];

            $task->set_custom_data($customdata);
            $task->set_next_run_time($this->get_runtime($rule));
            manager::queue_adhoc_task($task);
        }
    }

    /**
     * Update the current unit.
     * @param stdClass $rule
     * @return string
     */
    private function get_runtime($rule) {
        return time() + $rule->cyclicduration;
    }
}
