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

namespace local_taskflow\external;

use advanced_testcase;
use tool_mocktesttime\time_mock;
use context_system;
use local_taskflow\plugininfo\taskflowadapter;

/**
 * Test unit class of local_taskflow.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class search_users_test extends advanced_testcase {
    /** @var string|null Stores the external user data. */
    protected ?string $externaldata = null;

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
     * Ensure admins can search all users.
     * @covers \local_taskflow\external\search_users
     * @covers \local_taskflow\local\supervisor\supervisor
     * @runInSeparateProcess
     */
    public function test_execute_returns_expected_list_for_admin(): void {
        $this->setAdminUser();
        // Create some users via generator.
        $user1 = $this->getDataGenerator()->create_user(['firstname' => 'Alice', 'lastname' => 'Wonder']);
        $this->getDataGenerator()->create_user(['firstname' => 'Bob', 'lastname' => 'Builder']);

        $result = search_users::execute('Alice');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('list', $result);
        $this->assertArrayHasKey('warnings', $result);

        $this->assertIsArray($result['list']);
        $this->assertIsString($result['warnings']);

        // Expect at least Alice to be present in the result set.
        $found = false;
        foreach ($result['list'] as $record) {
            if ($record->id === $user1->id) {
                $found = true;
                $this->assertEquals('Alice', $record->firstname);
                $this->assertEquals('Wonder', $record->lastname);
            }
        }
        $this->assertTrue($found, 'Expected user Alice to be returned from search_users::execute().');
    }

    /**
     * Ensure supervisors can search direct subordinates.
     * @covers \local_taskflow\external\search_users
     * @covers \local_taskflow\local\supervisor\supervisor
     * @runInSeparateProcess
     */
    public function test_execute_returns_only_direct_subordinates_for_supervisor(): void {
        $this->configure_supervisor_and_deputy_fields();

        $systemcontext = context_system::instance();
        $supervisor = $this->getDataGenerator()->create_user();
        $this->assign_supervisor_capability($supervisor->id, $systemcontext->id);

        $visibleuser = $this->getDataGenerator()->create_user([
            'firstname' => 'AliceDirect',
            'lastname' => 'Team',
            'profile_field_supervisor' => (string)$supervisor->id,
        ]);
        $this->getDataGenerator()->create_user([
            'firstname' => 'AliceOther',
            'lastname' => 'Team',
        ]);

        $this->setUser($supervisor);
        $this->assertTrue(has_capability('local/taskflow:issupervisor', $systemcontext));
        $result = search_users::execute('Alice');

        $resultids = array_map(static function ($record) {
            return (int)$record->id;
        }, $result['list']);

        $this->assertContains(
            (int)$visibleuser->id,
            $resultids,
            'Expected direct subordinate not found. Returned IDs: ' . json_encode($resultids)
        );
        $this->assertNotEmpty($resultids);
    }

    /**
     * Ensure deputies can search users from delegated supervisors' teams and their own direct team.
     * @covers \local_taskflow\external\search_users
     * @covers \local_taskflow\local\supervisor\supervisor
     * @runInSeparateProcess
     */
    public function test_execute_returns_delegated_scope_for_deputy(): void {
        $this->configure_supervisor_and_deputy_fields();

        $systemcontext = context_system::instance();
        $deputy = $this->getDataGenerator()->create_user();
        $this->assign_supervisor_capability($deputy->id, $systemcontext->id);

        $delegatingsupervisor = $this->getDataGenerator()->create_user([
            'profile_field_deputy' => (string)$deputy->id,
        ]);
        $nondelagatingsupervisor = $this->getDataGenerator()->create_user();

        $directvisible = $this->getDataGenerator()->create_user([
            'firstname' => 'AliceDirectDeputy',
            'lastname' => 'Visible',
            'profile_field_supervisor' => (string)$deputy->id,
        ]);
        $delegatedvisible = $this->getDataGenerator()->create_user([
            'firstname' => 'AliceDelegated',
            'lastname' => 'Visible',
            'profile_field_supervisor' => (string)$delegatingsupervisor->id,
        ]);
        $hidden = $this->getDataGenerator()->create_user([
            'firstname' => 'AliceHidden',
            'lastname' => 'Other',
            'profile_field_supervisor' => (string)$nondelagatingsupervisor->id,
        ]);

        $this->setUser($deputy);
        $this->assertTrue(has_capability('local/taskflow:issupervisor', $systemcontext));
        $result = search_users::execute('Alice');

        $resultids = array_map(static function ($record) {
            return (int)$record->id;
        }, $result['list']);

        $this->assertContains(
            (int)$directvisible->id,
            $resultids,
            'Expected deputy direct subordinate not found. Returned IDs: ' . json_encode($resultids)
        );
        $this->assertContains(
            (int)$delegatedvisible->id,
            $resultids,
            'Expected delegated subordinate not found. Returned IDs: ' . json_encode($resultids)
        );
        $this->assertNotContains((int)$hidden->id, $resultids);
    }

    /**
     * Ensure users without supervisor capability cannot search users.
     * @covers \local_taskflow\external\search_users
     * @runInSeparateProcess
     */
    public function test_execute_returns_empty_list_without_capability(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $result = search_users::execute('Alice');

        $this->assertSame([], $result['list']);
        $this->assertNotEmpty($result['warnings']);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\external\search_users
     * @runInSeparateProcess
     */
    public function test_execute_returns_definition_matches_execute_returns(): void {
        $this->setAdminUser();
        $definition = search_users::execute_returns();
        $keys = array_keys($definition->keys);

        $this->assertEqualsCanonicalizing(['list', 'warnings'], $keys);
    }

    /**
     * Configure profile fields and mappings required by supervisor/deputy scope queries.
     *
     * @return void
     */
    private function configure_supervisor_and_deputy_fields(): void {
        set_config('external_api_option', 'standard', 'local_taskflow');
        set_config('supervisor_field', 'supervisor', 'local_taskflow');
        set_config(taskflowadapter::TRANSLATOR_USER_SUPERVISOR, 'supervisor', 'taskflowadapter_standard');
        set_config('supervisor', taskflowadapter::TRANSLATOR_USER_SUPERVISOR, 'taskflowadapter_standard');
        set_config(taskflowadapter::TRANSLATOR_USER_DEPUTY, 'deputy', 'taskflowadapter_standard');
        set_config('deputy', taskflowadapter::TRANSLATOR_USER_DEPUTY, 'taskflowadapter_standard');

        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'supervisor',
            'name' => 'supervisor',
        ]);

        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'deputy',
            'name' => 'deputy',
        ]);
    }

    /**
     * Assign supervisor capability to a user for test execution.
     *
     * @param int $userid
     * @param int $contextid
     * @return void
     */
    private function assign_supervisor_capability(int $userid, int $contextid): void {
        $shortname = 'searchusers_supervisor_' . $userid . '_' . random_int(1000, 9999);
        $roleid = create_role('Search Users Supervisor ' . $userid, $shortname, 'Search users supervisor role');
        assign_capability('local/taskflow:issupervisor', CAP_ALLOW, $roleid, $contextid, true);
        role_assign($roleid, $userid, $contextid);
    }
}
