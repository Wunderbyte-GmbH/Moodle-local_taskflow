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
use local_taskflow\local\units\organisational_units\unit;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\skills\diagnose_user_assignments_skill;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use local_taskflow\wizard\local_wizard_dependency;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../local_wizard_dependency.php');

/**
 * Verdicts, filter evaluation and scope of local_taskflow.diagnose_user_assignments.
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\skills\diagnose_user_assignments_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class diagnose_user_assignments_skill_test extends advanced_testcase {
    /** @var \local_taskflow_generator */
    private $generator;

    /** @var \stdClass */
    private \stdClass $supervisor;

    /** @var \stdClass */
    private \stdClass $employee;

    /** @var \stdClass */
    private \stdClass $outsider;

    /** @var int */
    private int $unitid = 0;

    /**
     * Setup: unit backend, one unit with one member, a supervisor and an unrelated user.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        local_wizard_dependency::require_installed();
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_taskflow');
        $this->generator->set_config_values('standard', ['organisational_unit_option' => 'unit']);
        $fields = $this->generator->create_custom_profile_fields(['supervisor', 'deputy']);
        \local_taskflow\local\external_adapter\external_api_base::destroy_instance();

        $this->supervisor = $this->getDataGenerator()->create_user(['firstname' => 'Emily', 'lastname' => 'Smith']);
        $this->employee = $this->getDataGenerator()->create_user(['firstname' => 'Anna', 'lastname' => 'Muster']);
        $this->outsider = $this->getDataGenerator()->create_user(['firstname' => 'Bert', 'lastname' => 'Beispiel']);
        $DB->insert_record('user_info_data', (object)[
            'userid' => $this->employee->id,
            'fieldid' => $fields['supervisor'],
            'data' => (string)$this->supervisor->id,
            'dataformat' => 0,
        ]);

        $this->unitid = (int)unit::create_unit((object)['name' => 'Administration'])->get_id();
        $DB->insert_record('local_taskflow_unit_members', (object)[
            'unitid' => $this->unitid,
            'userid' => $this->employee->id,
            'active' => 1,
            'timeadded' => time(),
            'timemodified' => time(),
            'usermodified' => 0,
        ]);
    }

    /**
     * Teardown: singletons.
     */
    protected function tearDown(): void {
        parent::tearDown();
        $this->generator->teardown();
    }

    /**
     * Create a rule on the test unit with one profile field filter.
     *
     * @param string $operator
     * @param string $value
     * @param array $extra
     * @return int
     */
    private function create_rule(string $operator, string $value, array $extra = []): int {
        return (int)$this->generator->create_rule($extra + [
            'name' => 'Data protection',
            'unitid' => $this->unitid,
            'filters' => [[
                'filtertype' => 'user_profile_field',
                'userprofilefield' => 'contract',
                'operator' => $operator,
                'value' => $value,
                'key' => '',
            ]],
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
        $skill = new diagnose_user_assignments_skill();
        $preflight = $skill->preflight($input, context_system::instance()->id, $userid);
        $result = null;
        if ($preflight->status === 'pass') {
            $result = $skill->execute($preflight->preparedinput, context_system::instance()->id, $userid);
        }
        return ['preflight' => $preflight, 'result' => $result];
    }

    /**
     * A filter that does not match blocks the assignment and is reported with result = false.
     */
    public function test_failing_filter_blocks_the_assignment(): void {
        $ruleid = $this->create_rule('equals', 'internal');
        $run = $this->run_skill(['ruleid' => $ruleid, 'userid' => (int)$this->employee->id], (int)get_admin()->id);

        $this->assertSame('pass', $run['preflight']->status);
        $result = $run['result'];
        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status']);
        $this->assertSame(diagnose_user_assignments_skill::VERDICT_BLOCKED_BY_FILTER, $result['verdict']);
        $this->assertCount(1, $result['filters']);
        $this->assertFalse($result['filters'][0]['result']);
        $this->assertSame('user_profile_field', $result['filters'][0]['filter']);
        $this->assertSame('equals', $result['filters'][0]['operator']);
        $this->assertSame('internal', $result['filters'][0]['value']);
        $this->assertNull($result['existing_assignment']);
        $this->assertTrue($result['membership']['direct']);

        $checks = array_column($result['checks'], 'passed', 'check');
        $this->assertTrue($checks[get_string('agent_check_rule_active', 'local_taskflow')]);
        $this->assertTrue($checks[get_string('agent_check_unit_membership', 'local_taskflow')]);
        $this->assertTrue($checks[get_string('agent_check_user_active', 'local_taskflow')]);
        $this->assertFalse($checks[get_string('agent_check_filters_overall', 'local_taskflow')]);

        $preview = (new diagnose_user_assignments_skill())->get_result_preview(
            $result,
            context_system::instance()->id,
            (int)get_admin()->id
        );
        $this->assertSame(taskflow_preview_renderer_factory::TYPE_DIAGNOSTIC_CHECKLIST, $preview['type']);
        $this->assertStringContainsString('✗', $preview['html']);
        $this->assertStringContainsString(
            get_string('agent_verdict_blocked_by_filter', 'local_taskflow'),
            $preview['html']
        );
        $this->assertSame([(int)$this->employee->id], $preview['payload']['userids']);
        $this->assertSame([$ruleid], $preview['payload']['ruleids']);
    }

    /**
     * A member passing every filter would get an assignment; an existing one is reported instead.
     */
    public function test_member_passing_filters_would_get_and_has_assignment(): void {
        $ruleid = $this->create_rule('not_equals', 'external');
        $run = $this->run_skill(['ruleid' => $ruleid, 'userid' => (int)$this->employee->id], (int)get_admin()->id);
        $result = $run['result'];
        $this->assertSame(diagnose_user_assignments_skill::VERDICT_WOULD_GET, $result['verdict']);
        $this->assertTrue($result['filters'][0]['result']);
        $this->assertNull($result['existing_assignment']);

        $this->generator->create_user_assignment((int)$this->employee->id, $ruleid);
        \local_taskflow\local\assignments\assignment::destroy_instance();

        $run = $this->run_skill(['ruleid' => $ruleid, 'userid' => (int)$this->employee->id], (int)get_admin()->id);
        $result = $run['result'];
        $this->assertSame(diagnose_user_assignments_skill::VERDICT_HAS_ASSIGNMENT, $result['verdict']);
        $this->assertNotNull($result['existing_assignment']);
        $this->assertTrue($result['existing_assignment']['active']);
        $this->assertStringContainsString(
            'assignment.php?id=' . $result['existing_assignment']['id'],
            $result['links']['assignment']
        );
    }

    /**
     * A user outside the rule unit is reported as not_member.
     */
    public function test_non_member_is_reported(): void {
        $ruleid = $this->create_rule('not_equals', 'external');
        $run = $this->run_skill(['ruleid' => $ruleid, 'userid' => (int)$this->outsider->id], (int)get_admin()->id);
        $result = $run['result'];
        $this->assertSame(diagnose_user_assignments_skill::VERDICT_NOT_MEMBER, $result['verdict']);
        $this->assertFalse($result['membership']['direct']);
        $this->assertFalse($result['membership']['inherited']);
        $this->assertSame('Administration', $result['membership']['unitname']);
    }

    /**
     * An inactive rule wins over every other finding.
     */
    public function test_inactive_rule_is_reported(): void {
        $ruleid = $this->create_rule('not_equals', 'external', ['isactive' => 0]);
        $run = $this->run_skill(['ruleid' => $ruleid, 'userid' => (int)$this->employee->id], (int)get_admin()->id);
        $this->assertSame(diagnose_user_assignments_skill::VERDICT_RULE_INACTIVE, $run['result']['verdict']);
    }

    /**
     * The supervisor may diagnose a subordinate; the assignee alone may not.
     */
    public function test_scope_supervisor_yes_employee_no(): void {
        $ruleid = $this->create_rule('not_equals', 'external');

        $run = $this->run_skill(
            ['ruleid' => $ruleid, 'userid' => (int)$this->employee->id],
            (int)$this->supervisor->id
        );
        $this->assertSame('pass', $run['preflight']->status);
        $this->assertSame(diagnose_user_assignments_skill::VERDICT_WOULD_GET, $run['result']['verdict']);

        $run = $this->run_skill(
            ['ruleid' => $ruleid, 'userid' => (int)$this->employee->id],
            (int)$this->employee->id
        );
        $this->assertSame('hard_block', $run['preflight']->status);
        $this->assertContains(taskflow_skill_base::ISSUE_SCOPE_DENIED, $run['preflight']->issuecodes);
        $this->assertNull($run['result']);

        $result = (new diagnose_user_assignments_skill())->execute(
            ['ruleid' => $ruleid, 'userid' => (int)$this->employee->id],
            context_system::instance()->id,
            (int)$this->employee->id
        );
        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $result['status']);
        $this->assertContains(taskflow_skill_base::ISSUE_SCOPE_DENIED, $result['issue_codes']);
    }

    /**
     * Missing input and unknown ids are answered in the preflight.
     */
    public function test_validation_and_unknown_ids(): void {
        $ruleid = $this->create_rule('not_equals', 'external');

        $run = $this->run_skill(['userid' => (int)$this->employee->id], (int)get_admin()->id);
        $this->assertSame('hard_block', $run['preflight']->status);
        $this->assertContains('VALIDATION_ERROR', $run['preflight']->issuecodes);

        $run = $this->run_skill(['ruleid' => $ruleid], (int)get_admin()->id);
        $this->assertSame('hard_block', $run['preflight']->status);
        $this->assertContains('VALIDATION_ERROR', $run['preflight']->issuecodes);

        $run = $this->run_skill(['ruleid' => 999999, 'userid' => (int)$this->employee->id], (int)get_admin()->id);
        $this->assertSame('hard_block', $run['preflight']->status);
        $this->assertContains(taskflow_skill_base::ISSUE_RULE_NOT_FOUND, $run['preflight']->issuecodes);

        $run = $this->run_skill(['ruleid' => $ruleid, 'userquery' => 'nobody-at-all'], (int)get_admin()->id);
        $this->assertSame('hard_block', $run['preflight']->status);
        $this->assertContains(taskflow_skill_base::ISSUE_USER_NOT_FOUND, $run['preflight']->issuecodes);
    }
}
