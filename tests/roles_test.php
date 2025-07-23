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
use local_taskflow\local\roles;
use local_taskflow\observer;
use core\event\cohort_member_added;
use core\event\cohort_member_removed;
use core\event\cohort_deleted;

/**
 * Test unit class of local_taskflow.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class roles_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        \local_taskflow\local\units\unit_relations::reset_instances();
    }

    /**
     * Test getting all members of a unit.
     * @covers \local_taskflow\local\roles
     */
    public function test_ensure_supervisor_role_creates_and_configures_role(): void {
        global $DB;

        $this->assertFalse($DB->record_exists('role', ['shortname' => 'supervisor']));

        $roles = new roles();
        $roles->ensure_supervisor_role();

        // Role should now exist.
        $role = $DB->get_record('role', ['shortname' => 'supervisor']);
        $this->assertNotEmpty($role);

        // Capability should be assigned.
        $context = context_system::instance();
        $capability = 'local/taskflow:issupervisor';

        $this->assertTrue($DB->record_exists('role_capabilities', [
            'contextid' => $context->id,
            'roleid' => $role->id,
            'capability' => $capability,
        ]));

        // Role should be assignable at system context.
        $this->assertTrue($DB->record_exists('role_context_levels', [
            'roleid' => $role->id,
            'contextlevel' => $context->contextlevel,
        ]));
    }
}
