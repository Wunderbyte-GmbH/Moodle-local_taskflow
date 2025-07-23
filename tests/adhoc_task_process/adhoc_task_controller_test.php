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

namespace local_taskflow\adhoc_task_process;

use advanced_testcase;
use local_taskflow\local\adhoc_task_process\adhoc_task_controller;
use local_taskflow\local\assignment_process\assignments\assignments_controller;
use local_taskflow\local\assignment_process\filters\filters_controller;
use local_taskflow\local\messages\messages_factory;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/user/profile/lib.php');

/**
 * Test unit class of local_taskflow.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class adhoc_task_controller_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        \local_taskflow\local\units\unit_relations::reset_instances();
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\adhoc_task_process\adhoc_task_controller
     * @covers \local_taskflow\local\assignment_operators\action_operator
     */
    public function test_process_assignments_triggers_action(): void {
        $this->resetAfterTest();
        global $DB;
        $rule = (object)[
            'unitid' => 2,
            'rulename' => 'Testing',
            'rulejson' => json_encode(['rulejson' => ['rule' => 'somerule']]),
            'isactive' => 0,
        ];
        $ruleid = $DB->insert_record('local_taskflow_rules', $rule);

        // Step 1: Create mock assignment.
        $mockassignment = new stdClass();
        $mockassignment->userid = 42;
        $mockassignment->ruleid = $ruleid;
        $mockassignment->rulejson = json_encode(['rulejson' => ['rule' => 'somerule']]);

        // Step 2: Mock the assignments controller.
        $fassignment = $this->createMock(assignments_controller::class);
        $fassignment->method('get_open_and_active_assignments')
            ->willReturn([$mockassignment]);

        $ffilter = $this->createMock(filters_controller::class);
        $ffilter->method('check_if_user_passes_filter')
            ->willReturn(true);
        $fmessages = $this->createMock(messages_factory::class);

        // Step 6: Create controller and run method.
        $controller = new adhoc_task_controller($fassignment, $ffilter, $fmessages);
        $controller->process_assignments();
        $this->resetAfterTest(true);
    }
}
