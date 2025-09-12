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
use local_taskflow\task\update_assignment;
use core\task\manager;

/**
 * Class send_taskflow_message
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class open_planned_assignment extends \core\task\adhoc_task {
    /**
     * Execute sending messags function
     * @return void
     */
    public function execute() {
        if ($this->get_next_run_time() > time()) {
            return;
        }
        global $DB;

        $data = (object) $this->get_custom_data();

        $assignmentid = $data->assignmentid ?? null;
        $userid = $data->userid ?? null;
        $id = $data->id ?? null;

        if ($assignmentid && $userid && $id) {
            assignments_facade::open_planned_assignment($assignmentid);
            $task = new update_assignment();
            $task->set_custom_data([
                'assignmentid' => $assignmentid,
                'userid'   => $userid,
                'id'   => $id,
            ]);
            manager::queue_adhoc_task($task);
        }
    }
}
