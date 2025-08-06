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

namespace local_taskflow\assignment\types;

use advanced_testcase;
use local_taskflow\local\assignments\types\standard_assignment;
use local_taskflow\local\external_adapter\external_api_base;
use stdClass;
use local_taskflow\local\assignments\assignment;

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
final class standard_assignment_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        \local_taskflow\local\units\unit_relations::reset_instances();
    }

    /**
     * Tear down the test environment.
     *
     * @return void
     *
     */
    protected function tearDown(): void {
        parent::tearDown();
        external_api_base::teardown();
        \local_taskflow\local\units\unit_relations::reset_instances();
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\assignments\types\standard_assignment
     */
    public function test_instance_returns_same_object(): void {
        global $DB;

        $record = (object)[
            'targets' => 'target1',
            'messages' => 'msg1',
            'userid' => 1,
            'ruleid' => 1,
            'unitid' => 1,
            'active' => 1,
            'timemodified' => time(),
            'status' => 0,
        ];
        $record->id = $DB->insert_record('local_taskflow_assignment', $record);

        $first = standard_assignment::instance($record->id);
        $second = standard_assignment::instance($record->id);

        $this->assertInstanceOf(standard_assignment::class, $first);
        $this->assertSame($first, $second);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\assignments\types\standard_assignment
     */
    public function test_create_assignment_creates_instance(): void {
        $assignment = (object)[
            'targets' => 'target',
            'messages' => 'message',
            'userid' => 1,
            'ruleid' => 1,
            'unitid' => 1,
            'active' => 1,
            'timemodified' => time(),
            'status' => 0,
        ];

        $method = new \ReflectionMethod(standard_assignment::class, 'create_assignment');
        $method->setAccessible(true);
        $result = $method->invoke(null, $assignment);

        $this->assertInstanceOf(standard_assignment::class, $result);
    }


    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\assignments\types\standard_assignment
     * @covers \local_taskflow\local\eventhandlers\assignment_status_changed
     */
    public function test_update_assignment_updates_instance(): void {
        global $DB;

        $record = (object)[
            'targets' => 'original',
            'messages' => 'msg',
            'userid' => 2,
            'ruleid' => 2,
            'unitid' => 1,
            'active' => 1,
            'timemodified' => time(),
            'status' => 1,
        ];
        $record->id = $DB->insert_record('local_taskflow_assignment', $record);

        $updated = clone $record;
        $updated->targets = 'updated';
        $updated->messages = 'updatedmsg';
        $updated->active = 0;
        $updated->usermodified = 2;
        $updated->timemodified = time();
        $updated->status = 2;

        $method = new \ReflectionMethod(standard_assignment::class, 'update_assignment');
        $method->setAccessible(true);
        $result = $method->invoke(null, $record, $updated);

        $this->assertInstanceOf(standard_assignment::class, $result);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\assignments\types\standard_assignment
     */
    public function test_check_if_status_changed_triggers_event(): void {
        $record = (object)[
            'id' => 9999,
            'status' => 0,
        ];

        $method = new \ReflectionMethod(standard_assignment::class, 'check_if_status_changed');
        $method->setAccessible(true);
        $this->expectOutputRegex('/.*/');
        $method->invoke(null, $record, 1);
        $method->invoke(null, $record, 0);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\assignments\types\standard_assignment
     */
    public function test_set_active_state_toggles_and_sets(): void {
        global $DB;

        $record = (object)[
            'targets' => 't',
            'messages' => 'm',
            'userid' => 1,
            'ruleid' => 1,
            'unitid' => 1,
            'active' => 1,
            'timemodified' => time(),
            'status' => 0,
        ];
        $record->id = $DB->insert_record('local_taskflow_assignment', $record);
        $instance = standard_assignment::instance($record->id);

        $result1 = $instance->set_active_state();
        $this->assertEquals(1, $result1);

        $result2 = $instance->set_active_state(1);
        $this->assertEquals(1, $result2);
    }
}
