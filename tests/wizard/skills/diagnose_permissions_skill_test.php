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

namespace local_taskflow\wizard\skills;

use advanced_testcase;
use context_system;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\skills\diagnose_permissions_skill;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use local_taskflow\wizard\local_wizard_dependency;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../local_wizard_dependency.php');

/**
 * Capability probing, HR lists and derived interface of local_taskflow.diagnose_permissions.
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\skills\diagnose_permissions_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class diagnose_permissions_skill_test extends advanced_testcase {
    /** @var \local_taskflow_generator */
    private $generator;

    /** @var \stdClass */
    private \stdClass $manager;

    /** @var \stdClass */
    private \stdClass $plainuser;

    /** @var int */
    private int $supervisorroleid = 0;

    /**
     * Setup: a site manager, a plain user and the configured supervisor role.
     */
    protected function setUp(): void {
        parent::setUp();
        local_wizard_dependency::require_installed();
        $this->resetAfterTest();
        $this->setAdminUser();
        // The skill capabilities ship in db/access.php; until the version bump installs them on
        // this site, refresh the capability table for the test transaction (as the coverage test does).
        update_capabilities('local_taskflow');

        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_taskflow');
        $this->generator->set_config_values('standard');
        $this->generator->create_custom_profile_fields(['supervisor', 'deputy']);
        \local_taskflow\local\external_adapter\external_api_base::destroy_instance();

        $this->manager = $this->getDataGenerator()->create_user(['firstname' => 'Emily', 'lastname' => 'Smith']);
        $this->plainuser = $this->getDataGenerator()->create_user(['firstname' => 'Anna', 'lastname' => 'Muster']);

        $managerroleid = (int)$this->getDataGenerator()->create_role(['shortname' => 'taskflowmanager']);
        foreach (['viewassignment', 'editassignment', 'viewreports', 'viewrules', 'viewrequests'] as $capability) {
            assign_capability(
                'local/taskflow:' . $capability,
                CAP_ALLOW,
                $managerroleid,
                context_system::instance()->id,
                true
            );
        }
        role_assign($managerroleid, $this->manager->id, context_system::instance()->id);

        $this->supervisorroleid = (int)get_config('local_taskflow', 'supervisorrole');
        role_assign($this->supervisorroleid, $this->manager->id, context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Teardown: singletons.
     */
    protected function tearDown(): void {
        parent::tearDown();
        $this->generator->teardown();
    }

    /**
     * Preflight + execute as the given user.
     *
     * @param array $input
     * @param int $userid
     * @return array{preflight:object,result:array|null}
     */
    private function run_skill(array $input, int $userid): array {
        $skill = new diagnose_permissions_skill();
        $preflight = $skill->preflight($input, context_system::instance()->id, $userid);
        $result = null;
        if ($preflight->status === 'pass') {
            $result = $skill->execute($preflight->preparedinput, context_system::instance()->id, $userid);
        }
        return ['preflight' => $preflight, 'result' => $result];
    }

    /**
     * The manager holds the taskflow capabilities, the supervisor role and the tabs they gate.
     */
    public function test_manager_capabilities_and_visible_ui(): void {
        $run = $this->run_skill(['userid' => (int)$this->manager->id], (int)get_admin()->id);
        $this->assertSame('pass', $run['preflight']->status);
        $result = $run['result'];

        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status']);
        $this->assertSame((int)$this->manager->id, $result['resultid']);

        $granted = array_column($result['capabilities'], 'granted', 'capability');
        $this->assertArrayHasKey('local/taskflow:viewassignment', $granted);
        $this->assertTrue($granted['local/taskflow:viewassignment']);
        $this->assertTrue($granted['local/taskflow:editassignment']);
        $this->assertTrue($granted['local/taskflow:viewrules']);
        $this->assertFalse($granted['local/taskflow:createrules']);

        // Skill capabilities are listed separately and never mixed into the plain list.
        $this->assertNotEmpty($result['skill_capabilities']);
        foreach ($result['skill_capabilities'] as $row) {
            $this->assertStringStartsWith(diagnose_permissions_skill::SKILL_CAP_PREFIX, $row['capability']);
        }
        foreach ($result['capabilities'] as $row) {
            $this->assertStringStartsNotWith(diagnose_permissions_skill::SKILL_CAP_PREFIX, $row['capability']);
        }

        $this->assertTrue($result['is_supervisor_role']);
        $this->assertSame($this->supervisorroleid, $result['supervisor_role']['roleid']);
        $this->assertFalse($result['is_hr']['taskflow']);

        $this->assertTrue($result['visible_ui']['dashboard']);
        $this->assertTrue($result['visible_ui']['admin_tab']);
        $this->assertTrue($result['visible_ui']['requests_tab']);
        $this->assertTrue($result['visible_ui']['rules']);
        // The supervisor role of the fixture carries local/taskflow:issupervisor.
        $this->assertTrue($result['visible_ui']['supervisor_tab']);

        $preview = (new diagnose_permissions_skill())->get_result_preview(
            $result,
            context_system::instance()->id,
            (int)get_admin()->id
        );
        $this->assertSame(taskflow_preview_renderer_factory::TYPE_DIAGNOSTIC_CHECKLIST, $preview['type']);
        $this->assertStringContainsString('local/taskflow:viewassignment', $preview['html']);
        $this->assertSame([(int)$this->manager->id], $preview['payload']['userids']);
    }

    /**
     * A plain user holds none of the taskflow capabilities and sees only the own dashboard.
     */
    public function test_plain_user_has_no_capabilities(): void {
        $result = $this->run_skill(['userid' => (int)$this->plainuser->id], (int)get_admin()->id)['result'];

        // Only local/taskflow:createrequests is granted to the "user" archetype in db/access.php.
        $granted = array_column($result['capabilities'], 'granted', 'capability');
        $this->assertSame(1, $result['capabilities_granted']);
        $this->assertTrue($granted['local/taskflow:createrequests']);
        $this->assertFalse($granted['local/taskflow:viewassignment']);
        $this->assertFalse($granted['local/taskflow:issupervisor']);
        $this->assertFalse($result['is_supervisor_role']);
        $this->assertSame([], $result['is_deputy_of']);
        $this->assertTrue($result['visible_ui']['dashboard']);
        $this->assertFalse($result['visible_ui']['supervisor_tab']);
        $this->assertFalse($result['visible_ui']['admin_tab']);
        $this->assertFalse($result['visible_ui']['rules']);
    }

    /**
     * HR membership is read from the taskflow list; the booking extension list is optional.
     */
    public function test_hr_membership(): void {
        set_config('hrusers', (string)$this->plainuser->id, 'local_taskflow');
        $result = $this->run_skill(['userid' => (int)$this->plainuser->id], (int)get_admin()->id)['result'];
        $this->assertTrue($result['is_hr']['taskflow']);
        // The booking extension is either absent (null) or reports a real membership flag.
        $this->assertTrue($result['is_hr']['bookingextension'] === null || is_bool($result['is_hr']['bookingextension']));
    }

    /**
     * Without local/taskflow:viewreports the skill is blocked; unknown users are reported.
     */
    public function test_scope_and_unknown_user(): void {
        $run = $this->run_skill(['userid' => (int)$this->plainuser->id], (int)$this->plainuser->id);
        $this->assertSame('hard_block', $run['preflight']->status);
        $this->assertContains(taskflow_skill_base::ISSUE_SCOPE_DENIED, $run['preflight']->issuecodes);

        $run = $this->run_skill(['userid' => (int)$this->plainuser->id], (int)$this->manager->id);
        $this->assertSame('pass', $run['preflight']->status);

        $run = $this->run_skill(['userquery' => 'nobody-at-all'], (int)get_admin()->id);
        $this->assertSame('hard_block', $run['preflight']->status);
        $this->assertContains(taskflow_skill_base::ISSUE_USER_NOT_FOUND, $run['preflight']->issuecodes);
    }
}
