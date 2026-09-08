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
use local_taskflow\local\wizard\engine\skill_risk_class;
use local_taskflow\local\wizard\taskflow\skills\create_rule_skill;
use local_taskflow\local\wizard\taskflow\skills\reschedule_rule_skill;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use local_taskflow\wizard\local_wizard_dependency;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../local_wizard_dependency.php');

/**
 * Skill local_taskflow.reschedule_rule.
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\skills\reschedule_rule_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class reschedule_rule_skill_test extends advanced_testcase {
    /** @var \local_taskflow_generator Plugin generator. */
    private $generator;

    /** @var int Rule under test. */
    private int $ruleid = 0;

    /** @var int Organisational unit. */
    private int $unitid = 0;

    /** @var int Unit member. */
    private int $memberid = 0;

    /** @var int System context id. */
    private int $contextid = 0;

    /**
     * Setup: engine, one unit with one member, one active rule and one assignment.
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        // Pin the mocked clock of tool_mocktesttime to now: other suites advance it and never reset it.
        if (class_exists('\\tool_mocktesttime\\time_mock')) {
            \tool_mocktesttime\time_mock::reset_mock_time();
        }
        local_wizard_dependency::require_installed();
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_taskflow');
        $this->generator->set_config_values('standard', ['organisational_unit_option' => 'unit']);

        $courseid = (int)$this->getDataGenerator()->create_course(['fullname' => 'Data protection course'])->id;
        $member = $this->getDataGenerator()->create_user(['firstname' => 'Anna', 'lastname' => 'Muster']);
        $this->memberid = (int)$member->id;

        $this->unitid = (int)unit::create_unit((object)['name' => 'Administration'])->get_id();
        $DB->insert_record('local_taskflow_unit_members', (object)[
            'unitid' => $this->unitid,
            'userid' => $this->memberid,
            'active' => 1,
            'timeadded' => time(),
            'timemodified' => time(),
            'usermodified' => 0,
        ]);

        $this->ruleid = (int)$this->generator->create_rule([
            'name' => 'Data protection basics',
            'unitid' => $this->unitid,
            'duedatetype' => 'duration',
            'duration' => 90 * DAYSECS,
            'targets' => [['targettype' => 'moodlecourse', 'targetid' => $courseid]],
        ]);
        $this->generator->create_user_assignment($this->memberid, $this->ruleid);

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
     * Ids of the queued update_rule adhoc tasks naming a rule.
     *
     * @param int $ruleid
     * @return int[]
     */
    private function queued_tasks(int $ruleid): array {
        global $DB;

        $ids = [];
        foreach ($DB->get_records('task_adhoc', null, 'id') as $task) {
            if (strpos((string)$task->classname, 'update_rule') === false) {
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
     * Contract: name, mutating R1, capability, required ruleid.
     */
    public function test_contract(): void {
        $skill = new reschedule_rule_skill();
        $this->assertSame('local_taskflow.reschedule_rule', $skill->get_name());
        $this->assertFalse($skill->is_read_only());
        $this->assertSame(skill_risk_class::R1, $skill->get_risk_class());
        $this->assertSame(['local/taskflow:createrules'], $skill->get_required_native_capabilities());

        $schema = $skill->get_schema();
        $this->assertTrue($schema['properties']['ruleid']['required']);
        $this->assertSame(['ruleid'], $schema['prompt_meta']['anchor_fields']);
        $this->assertSame(['system'], $schema['prompt_meta']['context_scopes']);
        $this->assertSame(['ruleid' => 17], $skill->get_example_input());
    }

    /**
     * The proposal names the rule, the scope and the members and promises nothing immediate.
     */
    public function test_confirmable_proposal(): void {
        global $USER;

        $skill = new reschedule_rule_skill();
        $preflight = $skill->preflight(['ruleid' => $this->ruleid], $this->contextid, (int)$USER->id);
        $this->assertSame('soft_block', $preflight->status);
        $this->assertContains(reschedule_rule_skill::ISSUE_CONFIRM_REQUIRED, $preflight->issuecodes);

        $proposal = $skill->describe_proposed_action($preflight->preparedinput);
        $this->assertIsArray($proposal);
        $this->assertStringContainsString('Data protection basics', $proposal['title']);
        $this->assertStringContainsString(create_rule_skill::UPDATE_RULE_TASK, $proposal['summary']);

        $rows = array_column($proposal['rows'], 'value', 'label');
        $this->assertSame('Administration', $rows[get_string('agent_rule_row_targetgroup', 'local_taskflow')]);
        $this->assertSame('1', $rows[get_string('agent_preview_members', 'local_taskflow')]);
        $this->assertSame('1', $rows[get_string('agent_rule_row_assignments', 'local_taskflow')]);

        $this->assertSame([], $this->queued_tasks($this->ruleid));
    }

    /**
     * Executing queues the adhoc task, reports 'queued' and changes nothing itself.
     */
    public function test_queues_update_rule_task(): void {
        global $DB, $USER;

        $before = $DB->get_field('local_taskflow_rules', 'rulejson', ['id' => $this->ruleid]);
        $assignmentsbefore = $DB->get_records('local_taskflow_assignment');

        // No event sink here: redirectEvents() would stop the observer that queues the adhoc task,
        // and the queued task is exactly the evidence this skill reports.
        $skill = new reschedule_rule_skill();
        $result = $skill->execute(['ruleid' => $this->ruleid], $this->contextid, (int)$USER->id);

        $this->assertSame(taskflow_skill_base::STATUS_QUEUED, $result['status']);
        $this->assertSame($this->ruleid, (int)$result['resultid']);

        $tasks = $this->queued_tasks($this->ruleid);
        $this->assertCount(1, $tasks);
        $this->assertSame($tasks, $result['queued_effects'][0]['taskids']);
        $this->assertSame(taskflow_skill_base::STATUS_QUEUED, $result['queued_effects'][0]['status']);
        $this->assertSame(create_rule_skill::UPDATE_RULE_TASK, $result['queued_effects'][0]['task']);
        $this->assertSame(1, (int)$result['members']);
        $this->assertSame(1, (int)$result['assignments_total']);
        $this->assertStringContainsString('next cron run', $result['observation_full']);

        // The rule and the assignments are untouched.
        $this->assertSame($before, $DB->get_field('local_taskflow_rules', 'rulejson', ['id' => $this->ruleid]));
        $this->assertEquals($assignmentsbefore, $DB->get_records('local_taskflow_assignment'));
    }

    /**
     * Unknown and missing rule ids are rejected.
     */
    public function test_unknown_rule(): void {
        global $USER;

        $skill = new reschedule_rule_skill();
        $preflight = $skill->preflight(['ruleid' => $this->ruleid + 5000], $this->contextid, (int)$USER->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(taskflow_skill_base::ISSUE_RULE_NOT_FOUND, $preflight->issuecodes);

        $preflight = $skill->preflight([], $this->contextid, (int)$USER->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains('VALIDATION_ERROR', $preflight->issuecodes);

        $result = $skill->execute(['ruleid' => $this->ruleid + 5000], $this->contextid, (int)$USER->id);
        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $result['status']);
        $this->assertSame([taskflow_skill_base::ISSUE_RULE_NOT_FOUND], $result['issue_codes']);
    }

    /**
     * Without local/taskflow:createrules preflight blocks and nothing is queued.
     */
    public function test_requires_createrules_capability(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $skill = new reschedule_rule_skill();
        $preflight = $skill->preflight(['ruleid' => $this->ruleid], $this->contextid, (int)$user->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(taskflow_skill_base::ISSUE_SCOPE_DENIED, $preflight->issuecodes);

        $result = $skill->execute(['ruleid' => $this->ruleid], $this->contextid, (int)$user->id);
        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $result['status']);
        $this->assertSame([taskflow_skill_base::ISSUE_SCOPE_DENIED], $result['issue_codes']);
        $this->assertSame([], $this->queued_tasks($this->ruleid));
    }
}
