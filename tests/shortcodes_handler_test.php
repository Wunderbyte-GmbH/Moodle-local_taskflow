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

namespace local_taskflow;

use advanced_testcase;
use context_system;

/**
 * Test unit class of local_taskflow.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class shortcodes_handler_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Test getting all members of a unit.
     * @covers \local_taskflow\shortcodes_handler
     */
    public function test_assignmentsdashboard_renders_output(): void {
        $result = shortcodes_handler::validatecondition('sctest', [], [], []);
        $this->assertSame(0, $result['error']);
        $this->assertSame('', $result['message']);
    }

    /**
     * Test getting all members of a unit.
     * @covers \local_taskflow\shortcodes_handler
     */
    public function test_validatecondition_with_capability_missing(): void {
        global $DB;

        $this->setAdminUser();
        $roleid = create_role('Dummy role', 'dummyrole', 'Dummy role for testing');
        assign_capability('moodle/site:config', CAP_PROHIBIT, $roleid, context_system::instance());

        $user = $this->getDataGenerator()->create_user();
        role_assign($roleid, $user->id, context_system::instance());
        $this->setUser($user);

        $result = shortcodes_handler::validatecondition(
            'sctest',
            [],
            ['moodle/site:config'],
            []
        );

        $this->assertSame(1, $result['error']);
        $this->assertStringContainsString('alert-warning', $result['message']);
    }

    /**
     * Test getting all members of a unit.
     * @covers \local_taskflow\shortcodes_handler
     */
    public function test_validatecondition_with_capability_present(): void {
        $this->setAdminUser();

        $result = shortcodes_handler::validatecondition(
            'sctest',
            [],
            ['moodle/site:config'],
            []
        );

        $this->assertSame(0, $result['error']);
        $this->assertSame('', $result['message']);
    }

    /**
     * Test getting all members of a unit.
     * @covers \local_taskflow\shortcodes_handler
     */
    public function test_validatecondition_with_password_correct(): void {
        set_config('shortcodespassword', 'secret', 'local_taskflow');
        $result = shortcodes_handler::validatecondition('sctest', ['password' => 'secret']);
        $this->assertSame(0, $result['error']);
    }

    /**
     * Test getting all members of a unit.
     * @covers \local_taskflow\shortcodes_handler
     */
    public function test_validatecondition_with_password_incorrect(): void {
        set_config('shortcodespassword', 'secret', 'local_taskflow');
        $result = shortcodes_handler::validatecondition('sctest', ['password' => 'wrong']);
        $this->assertSame(1, $result['error']);
        $this->assertStringContainsString('alert', $result['message']);
    }
}
