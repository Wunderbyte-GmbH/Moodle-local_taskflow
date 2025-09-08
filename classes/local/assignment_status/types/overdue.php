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

namespace local_taskflow\local\assignment_status\types;

use core\task\manager;
use local_taskflow\local\assignment_status\assignment_status_base;
use local_taskflow\local\assignments\status\assignment_status;
use local_taskflow\local\rules\rules;
use local_taskflow\task\check_assignment_status;

/**
 * Class unit
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class overdue extends assignment_status_base {
    /** @var overdue */
    private static ?overdue $instance = null;

    /**
     * Constructor
     */
    private function __construct() {
        $this->identifier = 10;
        $this->name = get_string('statusoverdue', 'local_taskflow');
        $this->label = 'overdue';
    }

    /**
     * Instanciator
     * @return overdue
     */
    public static function get_instance(): overdue {
        if (self::$instance === null) {
            self::$instance = new overdue();
        }
        return self::$instance;
    }

    /**
     * Factory for the organisational units.
     * @param object $assignment
     * @return void
     */
    public function change_status(&$assignment): void {
        $extensionperiod = $this->get_extension_period($assignment->ruleid);
        if (
            get_config('taskflowadapter_tuines', 'usingprolongedstate') &&
            $assignment->status != assignment_status::STATUS_PROLONGED &&
            $assignment->status != $this->identifier &&
            $extensionperiod > 0
        ) {
            $assignment->status = assignment_status::STATUS_PROLONGED;
            $this->shedule_new_assignment_check($assignment);
        } else {
            $assignment->status = $this->identifier;
            $assignment->duedate += $extensionperiod;
            $assignment->overduecounter = $assignment->overduecounter + 1;
        }
        return;
    }

    /**
     * Factory for the organisational units.
     * @param object $assignment
     * @return void
     */
    private function shedule_new_assignment_check($assignment): void {
        $task = new check_assignment_status();
        $customdata = [
            'userid' => (string) $assignment->userid,
            'ruleid' => (string) $assignment->ruleid,
            'assignmentid' => (string) $assignment->id,
        ];
        $task->set_custom_data($customdata);
        $task->set_next_run_time($assignment->duedate);
        manager::reschedule_or_queue_adhoc_task($task);
        return;
    }

    /**
     * Factory for the organisational units.
     * @param string $ruleid
     * @return int
     */
    private function get_extension_period($ruleid): int {
        $rule = rules::instance($ruleid);
        $rulejson = $rule->get_rulesjson();
        $rulejson = json_decode($rulejson);
        if (isset($rulejson->rulejson->rule->extensionperiod)) {
            return $rulejson->rulejson->rule->extensionperiod;
        }
        return 0;
    }
}
