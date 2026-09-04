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
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\skills\get_user_taskflow_profile_skill;
use local_taskflow\local\wizard\taskflow\taskflow_permission_resolver;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use local_taskflow\wizard\local_wizard_dependency;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../local_wizard_dependency.php');

/**
 * Adapter mapping and scope of local_taskflow.get_user_taskflow_profile (tuines config).
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\skills\get_user_taskflow_profile_skill
 * @covers     \local_taskflow\local\wizard\taskflow\preview\taskflow_user_profile_preview_renderer
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class get_user_taskflow_profile_skill_test extends advanced_testcase {
    /** @var \local_taskflow_generator */
    private $generator;

    /** @var array<string,int> */
    private array $fields = [];

    /** @var \stdClass */
    private \stdClass $supervisor;

    /** @var \stdClass */
    private \stdClass $deputy;

    /** @var \stdClass */
    private \stdClass $employee;

    /** @var \stdClass */
    private \stdClass $stranger;

    /** @var \stdClass */
    private \stdClass $cohort;

    /** @var int */
    private int $contractend = 0;

    /**
     * Setup: tuines adapter with profile field mappings, cohort membership, supervisor role.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        local_wizard_dependency::require_installed();
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_taskflow');
        $this->generator->set_config_values('tuines');
        $this->fields = $this->generator->create_custom_profile_fields(
            ['supervisor', 'deputy', 'contractend', 'longleave', 'externalid']
        );
        \local_taskflow\local\external_adapter\external_api_base::destroy_instance();

        $this->supervisor = $this->getDataGenerator()->create_user(['firstname' => 'Emily', 'lastname' => 'Smith']);
        $this->deputy = $this->getDataGenerator()->create_user(['firstname' => 'Bert', 'lastname' => 'Beispiel']);
        $this->employee = $this->getDataGenerator()->create_user(['firstname' => 'Anna', 'lastname' => 'Muster']);
        $this->stranger = $this->getDataGenerator()->create_user();

        $this->contractend = strtotime('2027-12-31 12:00:00');
        $this->set_profile((int)$this->employee->id, 'supervisor', (string)$this->supervisor->id);
        $this->set_profile((int)$this->employee->id, 'contractend', (string)$this->contractend);
        $this->set_profile((int)$this->employee->id, 'longleave', '1');
        $this->set_profile((int)$this->employee->id, 'externalid', '00123');
        $this->set_profile((int)$this->supervisor->id, 'deputy', (string)$this->deputy->id);

        $this->cohort = $this->getDataGenerator()->create_cohort(['name' => 'Administration']);
        cohort_add_member($this->cohort->id, $this->employee->id);

        role_assign((int)get_config('local_taskflow', 'supervisorrole'), $this->supervisor->id, context_system::instance()->id);

        $ruleid = (int)$this->generator->create_rule(['name' => 'Profile rule']);
        $this->generator->create_user_assignment((int)$this->employee->id, $ruleid);
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
     * Preflight + execute as the given user.
     *
     * @param array $input
     * @param int $userid
     * @return array{preflight:object,result:array|null}
     */
    private function run_skill(array $input, int $userid): array {
        $skill = new get_user_taskflow_profile_skill();
        $preflight = $skill->preflight($input, context_system::instance()->id, $userid);
        $result = null;
        if ($preflight->status === 'pass') {
            $result = $skill->execute($preflight->preparedinput, context_system::instance()->id, $userid);
        }
        return ['preflight' => $preflight, 'result' => $result];
    }

    /**
     * tuines mapping: supervisor, contract end, long leave, external id and units resolve.
     */
    public function test_tuines_mapping_resolves_supervisor_and_fields(): void {
        $run = $this->run_skill(['userquery' => $this->employee->email], (int)get_admin()->id);
        $this->assertSame('pass', $run['preflight']->status);
        $this->assertSame((int)$this->employee->id, $run['preflight']->preparedinput['userid']);
        $result = $run['result'];

        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status']);
        $this->assertSame((int)$this->employee->id, $result['user']['id']);
        $this->assertSame('Anna Muster', $result['user']['fullname']);
        $this->assertSame((int)$this->supervisor->id, $result['supervisor']['id']);
        $this->assertSame('Emily Smith', $result['supervisor']['fullname']);
        $this->assertSame($this->contractend, $result['contractend']);
        $this->assertTrue($result['longleave']);
        $this->assertSame('00123', $result['externalid']);
        $this->assertSame('supervisor', $result['mapped_fields']['supervisor']['field']);
        $this->assertSame((string)$this->supervisor->id, $result['mapped_fields']['supervisor']['value']);
        $this->assertSame('contractend', $result['mapped_fields']['contractend']['field']);
        $this->assertSame('tuines', $result['adapter']);
        $this->assertFalse($result['is_supervisor']);
        $this->assertSame([], $result['deputies']);

        $this->assertCount(1, $result['units']);
        $this->assertSame((int)$this->cohort->id, $result['units'][0]['id']);
        $this->assertSame('Administration', $result['units'][0]['name']);

        $this->assertSame(1, $result['assignments_total']);
        $this->assertCount(1, $result['assignments_by_status']);
        $status = $result['assignments_by_status'][0];
        $this->assertSame(assignment_status_facade::get_specific_names($status['status']), $status['label']);
        $this->assertSame(1, $status['count']);
        $this->assertStringContainsString('user/profile.php?id=' . $this->employee->id, $result['links']['page']);
        $this->assertStringContainsString('Emily Smith', $result['observation_full']);

        $preview = (new get_user_taskflow_profile_skill())->get_result_preview(
            $result,
            context_system::instance()->id,
            (int)get_admin()->id
        );
        $this->assertSame(taskflow_preview_renderer_factory::TYPE_USER_PROFILE, $preview['type']);
        $this->assertStringContainsString('Anna Muster', $preview['html']);
        $this->assertStringContainsString('Administration', $preview['html']);
        $this->assertStringContainsString('user/profile.php?id=' . $this->supervisor->id, $preview['html']);
        $this->assertSame([(int)$this->employee->id], $preview['payload']['userids']);
    }

    /**
     * The supervisor's own profile lists deputies and the supervisor role; default target is self.
     */
    public function test_supervisor_profile_lists_deputies_and_role(): void {
        $run = $this->run_skill([], (int)$this->supervisor->id);
        $this->assertSame('pass', $run['preflight']->status);
        $result = $run['result'];
        $this->assertSame((int)$this->supervisor->id, $result['user']['id']);
        $this->assertSame(taskflow_permission_resolver::SCOPE_SELF, $result['scope']);
        $this->assertTrue($result['is_supervisor']);
        $this->assertSame(1, $result['subordinates_count']);
        $this->assertCount(1, $result['deputies']);
        $this->assertSame((int)$this->deputy->id, $result['deputies'][0]['id']);
        $this->assertNull($result['supervisor']);
        $this->assertNull($result['contractend']);
        $this->assertNull($result['longleave']);
    }

    /**
     * Scope: supervisor and deputy may read the employee; strangers are denied unless viewreports.
     */
    public function test_scope_supervisor_deputy_viewreports(): void {
        $run = $this->run_skill(['userid' => (int)$this->employee->id], (int)$this->supervisor->id);
        $this->assertSame('pass', $run['preflight']->status);
        $this->assertSame(taskflow_permission_resolver::SCOPE_SUPERVISOR, $run['result']['scope']);

        $run = $this->run_skill(['userid' => (int)$this->employee->id], (int)$this->deputy->id);
        $this->assertSame('pass', $run['preflight']->status);

        $run = $this->run_skill(['userid' => (int)$this->employee->id], (int)$this->stranger->id);
        $this->assertSame('hard_block', $run['preflight']->status);
        $this->assertContains(taskflow_skill_base::ISSUE_SCOPE_DENIED, $run['preflight']->issuecodes);

        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(
            get_user_taskflow_profile_skill::CAP_VIEWREPORTS,
            CAP_ALLOW,
            $roleid,
            context_system::instance()->id
        );
        role_assign($roleid, $this->stranger->id, context_system::instance()->id);
        $run = $this->run_skill(['userid' => (int)$this->employee->id], (int)$this->stranger->id);
        $this->assertSame('pass', $run['preflight']->status);
        $this->assertSame('viewreports', $run['result']['scope']);

        $run = $this->run_skill(['userquery' => 'nobody.nix@example.invalid'], (int)get_admin()->id);
        $this->assertSame('hard_block', $run['preflight']->status);
        $this->assertContains(taskflow_skill_base::ISSUE_USER_NOT_FOUND, $run['preflight']->issuecodes);
    }
}
