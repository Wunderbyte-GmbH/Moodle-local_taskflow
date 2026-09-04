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

namespace local_taskflow\wizard;

use advanced_testcase;
use context_system;
use local_taskflow\local\supervisor\supervisor;
use local_taskflow\local\wizard\taskflow\taskflow_permission_resolver;

/**
 * Scope resolution admin > supervisor (direct / deputy) > self > none.
 *
 * Does not need the engine: the resolver is plain taskflow code.
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\taskflow_permission_resolver
 * @covers     \local_taskflow\local\supervisor\supervisor::get_visible_subordinate_ids
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class taskflow_permission_resolver_test extends advanced_testcase {
    /** @var \local_taskflow_generator */
    private $generator;

    /** @var array<string,int> Profile field ids by shortname. */
    private array $fields = [];

    /**
     * Setup: standard adapter, supervisor + deputy profile fields.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_taskflow');
        $this->generator->set_config_values('standard');
        $this->fields = $this->generator->create_custom_profile_fields(['supervisor', 'deputy']);
        \local_taskflow\local\external_adapter\external_api_base::destroy_instance();
    }

    /**
     * Teardown: singletons.
     */
    protected function tearDown(): void {
        parent::tearDown();
        $this->generator->teardown();
    }

    /**
     * Write a custom profile field value.
     *
     * @param int $userid
     * @param string $shortname
     * @param string $value
     */
    private function set_profile(int $userid, string $shortname, string $value): void {
        global $DB;
        $DB->insert_record('user_info_data', (object)[
            'userid' => $userid,
            'fieldid' => $this->fields[$shortname],
            'data' => $value,
            'dataformat' => 0,
        ]);
    }

    /**
     * Create an assignment for a user and return its id.
     *
     * @param int $userid
     * @return int
     */
    private function create_assignment(int $userid): int {
        global $DB;
        $ruleid = $this->generator->create_rule(['name' => 'Scope rule']);
        $this->generator->create_user_assignment($userid, $ruleid);
        return (int)$DB->get_field('local_taskflow_assignment', 'id', ['userid' => $userid, 'ruleid' => $ruleid], MUST_EXIST);
    }

    /**
     * Admin capability grants full scope and unrestricted visibility.
     */
    public function test_admin_scope(): void {
        $admin = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/taskflow:viewassignment', CAP_ALLOW, $roleid, context_system::instance()->id);
        role_assign($roleid, $admin->id, context_system::instance()->id);
        $employee = $this->getDataGenerator()->create_user();
        $assignmentid = $this->create_assignment((int)$employee->id);

        $resolver = new taskflow_permission_resolver();
        $this->assertSame(
            taskflow_permission_resolver::SCOPE_ADMIN,
            $resolver->scope_for_user((int)$employee->id, (int)$admin->id)
        );
        $this->assertSame(
            taskflow_permission_resolver::SCOPE_ADMIN,
            $resolver->scope_for_assignment($assignmentid, (int)$admin->id)
        );
        $this->assertNull($resolver->visible_userids((int)$admin->id));
        // View capability alone does not grant editing.
        $this->assertFalse($resolver->can_edit_assignment($assignmentid, (int)$admin->id));

        assign_capability('local/taskflow:editassignment', CAP_ALLOW, $roleid, context_system::instance()->id, true);
        $this->assertTrue((new taskflow_permission_resolver())->can_edit_assignment($assignmentid, (int)$admin->id));
    }

    /**
     * Supervisor (profile field) sees and edits subordinates; deputies of the supervisor too.
     */
    public function test_supervisor_and_deputy_scope(): void {
        $supervisor = $this->getDataGenerator()->create_user();
        $deputy = $this->getDataGenerator()->create_user();
        $employee = $this->getDataGenerator()->create_user();
        $stranger = $this->getDataGenerator()->create_user();

        $this->set_profile((int)$employee->id, 'supervisor', (string)$supervisor->id);
        $this->set_profile((int)$supervisor->id, 'deputy', (string)$deputy->id);
        $assignmentid = $this->create_assignment((int)$employee->id);

        $resolver = new taskflow_permission_resolver();

        $this->assertSame(
            taskflow_permission_resolver::SCOPE_SUPERVISOR,
            $resolver->scope_for_user((int)$employee->id, (int)$supervisor->id)
        );
        $this->assertSame(
            taskflow_permission_resolver::SCOPE_SUPERVISOR,
            $resolver->scope_for_assignment($assignmentid, (int)$supervisor->id)
        );
        $this->assertTrue($resolver->can_edit_assignment($assignmentid, (int)$supervisor->id));
        $visible = $resolver->visible_userids((int)$supervisor->id);
        $this->assertContains((int)$employee->id, $visible);
        $this->assertContains((int)$supervisor->id, $visible);
        $this->assertNotContains((int)$stranger->id, $visible);

        // Deputy delegation via supervisor::get_visible_subordinate_ids().
        $this->assertContains((int)$employee->id, supervisor::get_visible_subordinate_ids((int)$deputy->id));
        $this->assertSame(
            taskflow_permission_resolver::SCOPE_SUPERVISOR,
            $resolver->scope_for_user((int)$employee->id, (int)$deputy->id)
        );
        $this->assertTrue($resolver->can_edit_assignment($assignmentid, (int)$deputy->id));

        // A stranger has no scope at all.
        $this->assertSame(
            taskflow_permission_resolver::SCOPE_NONE,
            $resolver->scope_for_user((int)$employee->id, (int)$stranger->id)
        );
        $this->assertSame(
            taskflow_permission_resolver::SCOPE_NONE,
            $resolver->scope_for_assignment($assignmentid, (int)$stranger->id)
        );
        $this->assertFalse($resolver->can_edit_assignment($assignmentid, (int)$stranger->id));
        $this->assertSame([(int)$stranger->id], $resolver->visible_userids((int)$stranger->id));
    }

    /**
     * Self scope on own data; unknown assignments and HR list handling.
     */
    public function test_self_scope_and_edge_cases(): void {
        $employee = $this->getDataGenerator()->create_user();
        $assignmentid = $this->create_assignment((int)$employee->id);
        $resolver = new taskflow_permission_resolver();

        $this->assertSame(
            taskflow_permission_resolver::SCOPE_SELF,
            $resolver->scope_for_user((int)$employee->id, (int)$employee->id)
        );
        $this->assertSame(
            taskflow_permission_resolver::SCOPE_SELF,
            $resolver->scope_for_assignment($assignmentid, (int)$employee->id)
        );
        $this->assertFalse($resolver->can_edit_assignment($assignmentid, (int)$employee->id));
        $this->assertSame(taskflow_permission_resolver::SCOPE_NONE, $resolver->scope_for_assignment(999999, (int)$employee->id));
        $this->assertSame(taskflow_permission_resolver::SCOPE_NONE, $resolver->scope_for_user(0, (int)$employee->id));

        $this->assertFalse($resolver->is_hr_user((int)$employee->id));
        set_config('hrusers', ' 12, ' . $employee->id . ',x', 'local_taskflow');
        $this->assertTrue($resolver->is_hr_user((int)$employee->id));
        $this->assertFalse($resolver->is_hr_user(12345678));
    }
}
