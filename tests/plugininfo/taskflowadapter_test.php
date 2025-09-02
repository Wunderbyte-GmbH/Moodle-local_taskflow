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

namespace local_taskflow\plugininfo;

use advanced_testcase;

/**
 * Test unit class of local_taskflow.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class taskflowadapter_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Test getting all members of a unit.
     * @covers \local_taskflow\plugininfo\taskflowadapter
     */
    public function test_assignmentsdashboard_renders_output(): void {
        $user = $this->getDataGenerator()->create_user();

        // No config set -> should return empty object.
        $result = taskflowadapter::get_supervisor_for_user($user->id);
        $this->assertIsObject($result);
        $this->assertEquals((object)[], $result);
    }

    /**
     * Test getting all members of a unit.
     * @covers \local_taskflow\plugininfo\taskflowadapter
     */
    public function test_returns_empty_object_when_no_profile_data(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        set_config('supervisor_field', 'supervisorid', 'local_taskflow');

        $fieldid = $this->create_profile_field('supervisorid');

        $this->assertFalse($DB->record_exists('user_info_data', ['userid' => $user->id, 'fieldid' => $fieldid]));

        $result = taskflowadapter::get_supervisor_for_user($user->id);
        $this->assertEquals((object)[], $result);
    }

    /**
     * Test getting all members of a unit.
     * @covers \local_taskflow\plugininfo\taskflowadapter
     */
    public function test_returns_empty_object_when_supervisor_user_missing(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        set_config('supervisor_field', 'supervisorid', 'local_taskflow');

        $fieldid = $this->create_profile_field('supervisorid');
        // Put a numeric ID that does not exist in the user table.
        $DB->insert_record('user_info_data', (object)[
            'userid' => $user->id,
            'fieldid' => $fieldid,
            'data' => '999999', // No such user.
            'dataformat' => FORMAT_PLAIN,
        ]);

        $result = taskflowadapter::get_supervisor_for_user($user->id);
        $this->assertEquals((object)[], $result);
    }

    /**
     * Test getting all members of a unit.
     * @covers \local_taskflow\plugininfo\taskflowadapter
     */
    public function test_returns_supervisor_record_on_valid_numeric_id(): void {
        global $DB;

        $employee = $this->getDataGenerator()->create_user();
        $supervisor = $this->getDataGenerator()->create_user(['firstname' => 'Sue', 'lastname' => 'Pervisor']);

        set_config('supervisor_field', 'supervisorid', 'local_taskflow');

        $fieldid = $this->create_profile_field('supervisorid');
        // Store the supervisor's user id as profile field data of the employee.
        $DB->insert_record('user_info_data', (object)[
            'userid' => $employee->id,
            'fieldid' => $fieldid,
            'data' => (string)$supervisor->id, // Numeric string is expected.
            'dataformat' => FORMAT_PLAIN,
        ]);

        $result = taskflowadapter::get_supervisor_for_user($employee->id);

        $this->assertIsObject($result);
        $this->assertObjectHasProperty('id', $result);
        $this->assertEquals($supervisor->id, $result->id);
        $this->assertEquals('Sue', $result->firstname);
        $this->assertEquals('Pervisor', $result->lastname);
    }

    /**
     * Helper to create a minimal custom profile field in the DB.
     * Returns the created field ID.
     * @param string $shortname
     * @return int
     */
    private function create_profile_field(string $shortname): int {
        global $DB;

        // Create a category if needed.
        $categoryid = $DB->insert_record('user_info_category', (object)[
            'name' => 'Test Category',
            'sortorder' => 1,
        ]);

        // Minimal required fields for user_info_field.
        return (int)$DB->insert_record('user_info_field', (object)[
            'shortname' => $shortname,
            'name' => 'Supervisor ID',
            'datatype' => 'text',
            'description' => '',
            'descriptionformat' => FORMAT_PLAIN,
            'categoryid' => $categoryid,
            'sortorder' => 1,
            'required' => 0,
            'locked' => 0,
            'visible' => 1,
            'forceunique' => 0,
            'signup' => 0,
            'defaultdata' => '',
            'defaultdataformat' => FORMAT_PLAIN,
            // Params for text datatype (keep empty).
            'param1' => null,
            'param2' => null,
            'param3' => null,
            'param4' => null,
            'param5' => null,
        ]);
    }
}
