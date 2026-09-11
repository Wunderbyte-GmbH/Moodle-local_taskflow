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

namespace local_taskflow\form;

use advanced_testcase;
use context_system;
use local_taskflow\local\dashboardcache\dashboardcache;
use local_taskflow\plugininfo\taskflowadapter;
use required_capability_exception;
use tool_mocktesttime\time_mock;
use core_competency\user_evidence;
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
final class dynamic_select_users_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        $this->resetAfterTest(true);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\form\dynamic_select_users
     */
    public function test_process_dynamic_submission_minimal(): void {
        $this->resetAfterTest();

        $form = new dynamic_select_users();
        $form->definition();

        $ref = new \ReflectionClass($form);
        $prop = $ref->getProperty('_form');
        $prop->setAccessible(true);
        $mform = $prop->getValue($form);

        $this->assertTrue($mform->elementExists('userid'));

        $form->set_data_for_dynamic_submission();
        $this->assertEmpty($form->validation(new stdClass(), []));
        $this->assertEmpty($form->get_data());
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\form\dynamic_select_users
     */
    public function test_process_dynamic_submission_returns_userid(): void {
        $this->resetAfterTest();

        // Create a fake form instance with mocked get_data().
        $form = $this->getMockBuilder(dynamic_select_users::class)
            ->onlyMethods(['get_data'])
            ->getMock();

        $data = new stdClass();
        $data->userid = 42;

        $form->method('get_data')->willReturn($data);

        $result = $form->process_dynamic_submission();

        $this->assertInstanceOf(stdClass::class, $result);
        $this->assertEquals(42, $result->userid);
    }

    /**
     * Submitting the form needs viewreports or issupervisor, like the user search behind it.
     * @covers \local_taskflow\form\dynamic_select_users::check_access_for_dynamic_submission
     */
    public function test_check_access_requires_capability(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $form = new dynamic_select_users();
        $method = new \ReflectionMethod($form, 'check_access_for_dynamic_submission');

        $this->expectException(required_capability_exception::class);
        $method->invoke($form);
    }

    /**
     * Managers see everybody, everybody sees themselves, deleted and unknown users are never accepted.
     * @covers \local_taskflow\local\dashboardcache\dashboardcache::filter_visible_userids
     */
    public function test_scope_for_manager_self_and_plain_user(): void {
        $other = $this->getDataGenerator()->create_user();
        $deleted = $this->getDataGenerator()->create_user();
        delete_user($deleted);

        $this->setAdminUser();
        $this->assertTrue(dashboardcache::may_show_user((int)$other->id));
        $this->assertFalse(dashboardcache::may_show_user((int)$deleted->id));
        $this->assertFalse(dashboardcache::may_show_user(999999));

        $plain = $this->getDataGenerator()->create_user();
        $this->setUser($plain);
        $this->assertTrue(dashboardcache::may_show_user((int)$plain->id));
        $this->assertFalse(dashboardcache::may_show_user((int)$other->id));
    }

    /**
     * Supervisors see their own team only, the same source as the user search.
     * @covers \local_taskflow\local\dashboardcache\dashboardcache::filter_visible_userids
     */
    public function test_scope_for_supervisor_is_the_own_team(): void {
        $this->configure_supervisor_field();
        $supervisor = $this->getDataGenerator()->create_user();
        $this->assign_supervisor_capability((int)$supervisor->id);
        $teammember = $this->getDataGenerator()->create_user(['profile_field_supervisor' => (string)$supervisor->id]);
        $stranger = $this->getDataGenerator()->create_user();

        $this->setUser($supervisor);
        $this->assertSame(
            [(int)$teammember->id],
            dashboardcache::filter_visible_userids([(int)$teammember->id, (int)$stranger->id])
        );
    }

    /**
     * Out-of-scope persons are rejected by the validation and never stored, even when posted directly.
     * @covers \local_taskflow\form\dynamic_select_users::process_dynamic_submission
     * @covers \local_taskflow\form\dynamic_select_users::validation
     */
    public function test_out_of_scope_user_is_rejected_and_not_stored(): void {
        $this->configure_supervisor_field();
        $supervisor = $this->getDataGenerator()->create_user();
        $this->assign_supervisor_capability((int)$supervisor->id);
        $teammember = $this->getDataGenerator()->create_user(['profile_field_supervisor' => (string)$supervisor->id]);
        $stranger = $this->getDataGenerator()->create_user();
        $this->setUser($supervisor);

        $form = new dynamic_select_users();
        $this->assertArrayHasKey('userid', $form->validation(['userid' => $stranger->id], []));
        $this->assertEmpty($form->validation(['userid' => $teammember->id], []));

        foreach ([$stranger, $teammember] as $person) {
            $form = $this->getMockBuilder(dynamic_select_users::class)->onlyMethods(['get_data'])->getMock();
            $form->method('get_data')->willReturn((object)['userid' => $person->id]);
            $form->process_dynamic_submission();
        }

        $stored = (new dashboardcache())->get_all_users()['userids'] ?? [];
        $this->assertArrayHasKey((int)$teammember->id, $stored);
        $this->assertArrayNotHasKey((int)$stranger->id, $stored);
    }

    /**
     * Profile field and adapter mapping used by supervisor::get_visible_subordinate_ids().
     *
     * @return void
     */
    private function configure_supervisor_field(): void {
        set_config('external_api_option', 'standard', 'local_taskflow');
        set_config('supervisor_field', 'supervisor', 'local_taskflow');
        set_config(taskflowadapter::TRANSLATOR_USER_SUPERVISOR, 'supervisor', 'taskflowadapter_standard');
        set_config('supervisor', taskflowadapter::TRANSLATOR_USER_SUPERVISOR, 'taskflowadapter_standard');
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'supervisor',
            'name' => 'supervisor',
        ]);
    }

    /**
     * Gives a user the supervisor capability in the system context.
     *
     * @param int $userid
     * @return void
     */
    private function assign_supervisor_capability(int $userid): void {
        $contextid = context_system::instance()->id;
        $roleid = create_role('Tab scope supervisor ' . $userid, 'tabscopesupervisor' . $userid, 'Tab scope supervisor');
        assign_capability('local/taskflow:issupervisor', CAP_ALLOW, $roleid, $contextid, true);
        role_assign($roleid, $userid, $contextid);
    }
}
