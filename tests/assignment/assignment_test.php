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

namespace local_taskflow\assignment;

use advanced_testcase;
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
final class assignment_test extends advanced_testcase {
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
     * @covers \local_taskflow\local\assignments\assignment
     * @covers \local_taskflow\local\assignment_process\assignment_preprocessor
     *
     */
    public function test_add_or_update_assignment_creates_new_assignment(): void {
        global $DB, $USER;

        $USER = $this->getDataGenerator()->create_user();
        $this->setUser($USER);

        $rule = $DB->insert_record('local_taskflow_rules', (object)[
            'rulename' => 'Test Rule',
            'rulejson' => '{}',
        ]);

        $data = [
            'userid' => $USER->id,
            'ruleid' => $rule,
            'unitid' => 1,
            'assigneddate' => time(),
            'duedate' => time() + 3600,
        ];

        $assignment = new assignment();
        $result = $assignment->add_or_update_assignment($data);

        $this->assertNotEmpty($result->id);
        $this->assertEquals($USER->id, $result->userid);
        $this->assertEquals(1, $result->active);
        $this->assertEquals(0, $result->status);
        $result = $assignment->add_or_update_assignment((array)$result);
        $this->assertNotEmpty($result->id);
        $this->assertEquals($USER->id, $result->userid);
        $this->assertEquals(1, $result->active);
        $this->assertEquals(0, $result->status);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\assignments\assignment
     */
    public function test_get_sql_parameter_array_appends_custom_fields_to_select(): void {
        set_config('assignment_fields', 'customfield1, customfield2', 'local_taskflow');
        $assignment = new assignment();

        $params = [];
        $this->invoke_get_sql_parameter_array($assignment, $params);

        $this->assertArrayHasKey('fieldshortname0', $params);
        $this->assertArrayHasKey('fieldshortname1', $params);
        $this->assertEquals('customfield1', $params['fieldshortname0']);
        $this->assertEquals('customfield2', $params['fieldshortname1']);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @param stdClass $assignment
     * @param array $params
     */
    private function invoke_get_sql_parameter_array(&$assignment, array &$params): void {
        $refmethod = new \ReflectionMethod($assignment, 'get_sql_parameter_array');
        $refmethod->setAccessible(true);
        $refmethod->invokeArgs($assignment, [&$params]);
    }
}
