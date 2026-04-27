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
use tool_mocktesttime\time_mock;
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
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        $this->resetAfterTest(true);
        \local_taskflow\local\units\unit_relations::reset_instances();
    }

    /**
     * Tear down the test environment.
     *
     * @return void
     *
     */
    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        global $DB;
        parent::tearDown();
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingenerator->teardown();
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\assignments\assignment
     * @covers \local_taskflow\local\assignments\assignment_query_builder
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

        $assignment = assignment::get_instance();
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
        global $DB;

        $DB->insert_record('user_info_field', (object)[
            'shortname' => 'customfield1',
            'name' => 'Custom Field 1',
            'categoryid' => 1,
            'datatype' => 'text',
            'sortorder' => 1,
        ]);
        $DB->insert_record('user_info_field', (object)[
            'shortname' => 'customfield2',
            'name' => 'Custom Field 2',
            'categoryid' => 1,
            'datatype' => 'text',
            'sortorder' => 2,
        ]);

        set_config('assignment_fields', 'customfield1, customfield2', 'local_taskflow');
        $assignment = assignment::get_instance();

        $params = [];
        $this->invoke_get_sql_parameter_array($assignment, $params);

        $fromsql = $this->get_from_sql($assignment);
        $this->assertStringContainsString('custom_customfield1', $fromsql);
        $this->assertStringContainsString('custom_customfield2', $fromsql);
    }

    /**
     * Invokes the private get_sql_parameter_array method via reflection.
     * @param stdClass $assignment
     * @param array $params
     */
    private function invoke_get_sql_parameter_array(&$assignment, array &$params): void {
        $refmethod = new \ReflectionMethod($assignment, 'get_sql_parameter_array');
        $refmethod->setAccessible(true);
        $refmethod->invokeArgs($assignment, [&$params]);
    }

    /**
     * Reads the private $from property via reflection.
     * @param stdClass $assignment
     * @return string
     */
    private function get_from_sql(&$assignment): string {
        $refprop = new \ReflectionProperty($assignment, 'from');
        $refprop->setAccessible(true);
        return (string)$refprop->getValue($assignment);
    }
}
