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
use local_taskflow\local\wizard\engine\skill_risk_class;
use local_taskflow\local\wizard\taskflow\skills\delete_rule_skill;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use local_taskflow\wizard\local_wizard_dependency;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../local_wizard_dependency.php');

/**
 * Two-stage confirmation and queued deletion of local_taskflow.delete_rule.
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\skills\delete_rule_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class delete_rule_skill_test extends advanced_testcase {
    /** @var \local_taskflow_generator Plugin generator. */
    private $generator;

    /** @var int Rule under test. */
    private int $ruleid = 0;

    /** @var int Assignee. */
    private int $employeeid = 0;

    /** @var int System context id. */
    private int $contextid = 0;

    /**
     * Setup: one rule with one assignment.
     */
    protected function setUp(): void {
        parent::setUp();
        // Pin the mocked clock of tool_mocktesttime to now: other suites advance it and never reset it.
        if (class_exists('\\tool_mocktesttime\\time_mock')) {
            \tool_mocktesttime\time_mock::reset_mock_time();
        }
        local_wizard_dependency::require_installed();
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_taskflow');
        $this->generator->set_config_values('standard');

        $employee = $this->getDataGenerator()->create_user(['firstname' => 'Anna', 'lastname' => 'Muster']);
        $this->employeeid = (int)$employee->id;
        $this->ruleid = (int)$this->generator->create_rule(['name' => 'Data protection basics']);
        $this->generator->create_user_assignment($this->employeeid, $this->ruleid);
        $this->contextid = (int)context_system::instance()->id;
    }

    /**
     * Teardown singletons.
     */
    protected function tearDown(): void {
        $this->generator->teardown();
        parent::tearDown();
    }

    /**
     * Ids of the queued removed_rule adhoc tasks naming the rule.
     *
     * @param int $ruleid
     * @return int[]
     */
    private function queued_tasks(int $ruleid): array {
        global $DB;

        $ids = [];
        foreach ($DB->get_records('task_adhoc', null, 'id') as $task) {
            if (strpos((string)$task->classname, 'removed_rule') === false) {
                continue;
            }
            $data = json_decode((string)$task->customdata, true);
            if (is_array($data) && (int)($data['id'] ?? 0) === $ruleid) {
                $ids[] = (int)$task->id;
            }
        }
        return $ids;
    }

    /**
     * Contract: irreversible write, capability, schema with ruleid and the override list.
     */
    public function test_contract(): void {
        $skill = new delete_rule_skill();
        $this->assertSame('local_taskflow.delete_rule', $skill->get_name());
        $this->assertFalse($skill->is_read_only());
        $this->assertSame(skill_risk_class::R3, $skill->get_risk_class());
        $this->assertSame(['local/taskflow:createrules'], $skill->get_required_native_capabilities());

        $schema = $skill->get_schema();
        $this->assertTrue($schema['properties']['ruleid']['required']);
        $this->assertSame('array', $schema['properties']['override']['type']);
        $this->assertSame(['system'], $schema['prompt_meta']['context_scopes']);
    }

    /**
     * Without the override token the first stage is confirmable and nothing is queued.
     */
    public function test_confirmable_without_override_token(): void {
        global $DB, $USER;

        $skill = new delete_rule_skill();
        $preflight = $skill->preflight(['ruleid' => $this->ruleid], $this->contextid, (int)$USER->id);

        $this->assertSame('soft_block', $preflight->status);
        $this->assertContains(delete_rule_skill::ISSUE_CONFIRM_REQUIRED, $preflight->issuecodes);
        $issue = $preflight->issues[0];
        $this->assertContains(delete_rule_skill::OVERRIDE_CONFIRM_DELETE, $issue['remedy_options']);
        // The exact number of assignments that disappear with the rule is part of the question.
        $this->assertStringContainsString('1', (string)$issue['user_question']);

        $proposal = $skill->describe_proposed_action($preflight->preparedinput);
        $rows = array_column($proposal['rows'], 'value', 'label');
        $this->assertSame('1', $rows[get_string('agent_rule_row_assignments', 'local_taskflow')]);
        $this->assertStringContainsString(
            delete_rule_skill::OVERRIDE_CONFIRM_DELETE,
            $rows[get_string('agent_delete_rule_row_confirmation', 'local_taskflow')]
        );

        // Nothing was queued and the rule is untouched.
        $this->assertSame([], $this->queued_tasks($this->ruleid));
        $this->assertTrue($DB->record_exists('local_taskflow_rules', ['id' => $this->ruleid]));
    }

    /**
     * execute() refuses to act without the structured token, and queues nothing.
     */
    public function test_execute_requires_override_token(): void {
        global $DB, $USER;

        $result = (new delete_rule_skill())->execute(['ruleid' => $this->ruleid], $this->contextid, (int)$USER->id);

        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $result['status']);
        $this->assertSame([delete_rule_skill::ISSUE_OVERRIDE_MISSING], $result['issue_codes']);
        $this->assertSame([], $this->queued_tasks($this->ruleid));
        $this->assertTrue($DB->record_exists('local_taskflow_rules', ['id' => $this->ruleid]));
    }

    /**
     * With the token the adhoc task is queued; the rule itself is deleted by that task later.
     */
    public function test_queues_removed_rule_task_with_override(): void {
        global $DB, $USER;

        $result = (new delete_rule_skill())->execute([
            'ruleid' => $this->ruleid,
            'override' => [delete_rule_skill::OVERRIDE_CONFIRM_DELETE],
        ], $this->contextid, (int)$USER->id);

        $this->assertSame(taskflow_skill_base::STATUS_QUEUED, $result['status']);
        $this->assertSame($this->ruleid, (int)$result['resultid']);
        $this->assertSame(1, (int)$result['assignments_total']);

        $tasks = $this->queued_tasks($this->ruleid);
        $this->assertCount(1, $tasks);
        $this->assertSame($tasks, $result['queued_effects'][0]['taskids']);
        $this->assertSame(taskflow_skill_base::STATUS_QUEUED, $result['queued_effects'][0]['status']);
        $this->assertSame(delete_rule_skill::REMOVED_RULE_TASK, $result['queued_effects'][0]['task']);
        $this->assertStringContainsString('next cron run', $result['observation_full']);

        // Deletion is the task's job: rule and assignment are still there right after the call.
        $this->assertTrue($DB->record_exists('local_taskflow_rules', ['id' => $this->ruleid]));
        $this->assertSame(1, $DB->count_records('local_taskflow_assignment', ['ruleid' => $this->ruleid]));
    }

    /**
     * Unknown rule ids are rejected before anything is queued.
     */
    public function test_unknown_rule(): void {
        global $USER;

        $skill = new delete_rule_skill();
        $preflight = $skill->preflight(['ruleid' => $this->ruleid + 5000], $this->contextid, (int)$USER->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(taskflow_skill_base::ISSUE_RULE_NOT_FOUND, $preflight->issuecodes);

        $result = $skill->execute([
            'ruleid' => $this->ruleid + 5000,
            'override' => [delete_rule_skill::OVERRIDE_CONFIRM_DELETE],
        ], $this->contextid, (int)$USER->id);
        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $result['status']);
        $this->assertSame([taskflow_skill_base::ISSUE_RULE_NOT_FOUND], $result['issue_codes']);
        $this->assertSame([], $this->queued_tasks($this->ruleid + 5000));
    }

    /**
     * Without local/taskflow:createrules the preflight hard-blocks and nothing is queued.
     */
    public function test_requires_createrules_capability(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $skill = new delete_rule_skill();
        $preflight = $skill->preflight(['ruleid' => $this->ruleid], $this->contextid, (int)$user->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(taskflow_skill_base::ISSUE_SCOPE_DENIED, $preflight->issuecodes);

        $result = $skill->execute([
            'ruleid' => $this->ruleid,
            'override' => [delete_rule_skill::OVERRIDE_CONFIRM_DELETE],
        ], $this->contextid, (int)$user->id);
        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $result['status']);
        $this->assertSame([taskflow_skill_base::ISSUE_SCOPE_DENIED], $result['issue_codes']);
        $this->assertSame([], $this->queued_tasks($this->ruleid));
    }
}
