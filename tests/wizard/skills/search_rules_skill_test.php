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
use local_taskflow\local\wizard\taskflow\skills\search_rules_skill;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use local_taskflow\wizard\local_wizard_dependency;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../local_wizard_dependency.php');

/**
 * Skill local_taskflow.search_rules.
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\skills\search_rules_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class search_rules_skill_test extends advanced_testcase {
    /** @var int Unit rule with a course target (active). */
    private int $courseruleid = 0;

    /** @var int Unit rule with a competency target (active). */
    private int $competencyruleid = 0;

    /** @var int Personal rule without targets (inactive). */
    private int $personalruleid = 0;

    /** @var int Cohort used as organisational unit. */
    private int $cohortid = 0;

    /** @var int System context id. */
    private int $contextid = 0;

    /**
     * Setup: engine, standard adapter config, three rules and one assignment.
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

        /** @var \local_taskflow_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_taskflow');
        $generator->set_config_values('standard');

        $cohort = $this->getDataGenerator()->create_cohort(['name' => 'Administration']);
        $this->cohortid = (int)$cohort->id;
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Data protection course']);
        $user = $this->getDataGenerator()->create_user();

        $this->courseruleid = $generator->create_rule([
            'name' => 'Data protection basics',
            'unitid' => $this->cohortid,
            'filters' => [['userprofilefield' => 'contract', 'operator' => 'not_equals', 'value' => 'external']],
            'targets' => [['targettype' => 'moodlecourse', 'targetid' => (int)$course->id]],
        ]);
        $this->competencyruleid = $generator->create_rule([
            'name' => 'Fire safety',
            'unitid' => $this->cohortid,
            'targets' => [['targettype' => 'competency', 'targetid' => 999]],
        ]);
        $this->personalruleid = $generator->create_rule([
            'name' => 'Old personal rule',
            'userid' => (int)$user->id,
            'isactive' => 0,
        ]);
        $generator->create_user_assignment((int)$user->id, $this->courseruleid);

        $this->contextid = (int)context_system::instance()->id;
    }

    /**
     * Teardown singletons.
     */
    protected function tearDown(): void {
        $this->getDataGenerator()->get_plugin_generator('local_taskflow')->teardown();
        parent::tearDown();
    }

    /**
     * Execute the skill as admin.
     *
     * @param array $input
     * @return array
     */
    private function run_skill(array $input): array {
        global $USER;
        $skill = new search_rules_skill();
        $preflight = $skill->preflight($input, $this->contextid, (int)$USER->id);
        $this->assertSame('pass', $preflight->status);
        return $skill->execute($preflight->preparedinput, $this->contextid, (int)$USER->id);
    }

    /**
     * Ids of the returned rules.
     *
     * @param array $result
     * @return int[]
     */
    private function ids(array $result): array {
        return array_map(static fn(array $rule): int => (int)$rule['id'], (array)$result['rules']);
    }

    /**
     * Contract: name, read-only R0, native capability, system scope, no lexical triggers.
     */
    public function test_contract(): void {
        $skill = new search_rules_skill();
        $this->assertSame('local_taskflow.search_rules', $skill->get_name());
        $this->assertTrue($skill->is_read_only());
        $this->assertSame(['local/taskflow:viewrules'], $skill->get_required_native_capabilities());
        $this->assertSame(CONTEXT_SYSTEM, $skill->get_required_context_level());

        $schema = $skill->get_schema();
        foreach (['query', 'unitid', 'isactive', 'targettype', 'limit', 'outputlang'] as $field) {
            $this->assertArrayHasKey($field, $schema['properties']);
        }
        $this->assertSame(['system'], $schema['prompt_meta']['context_scopes']);
        $this->assertNotEmpty($schema['example_utterances']);
        foreach ($skill->get_contextual_prompt_packs() as $pack) {
            $this->assertArrayNotHasKey('triggers', $pack);
        }
    }

    /**
     * Without filters every rule is listed with type, unit name, target types and assignment count.
     */
    public function test_lists_all_rules(): void {
        $result = $this->run_skill([]);

        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status']);
        $this->assertSame(3, $result['total']);
        $this->assertCount(3, $result['rules']);
        $this->assertEqualsCanonicalizing(
            [$this->courseruleid, $this->competencyruleid, $this->personalruleid],
            $this->ids($result)
        );

        $byid = array_column($result['rules'], null, 'id');
        $courserule = $byid[$this->courseruleid];
        $this->assertSame('Data protection basics', $courserule['name']);
        $this->assertSame('unit', $courserule['type']);
        $this->assertSame($this->cohortid, $courserule['unitid']);
        $this->assertSame('Administration', $courserule['unitname']);
        $this->assertTrue($courserule['isactive']);
        $this->assertSame(['moodlecourse'], $courserule['targettypes']);
        $this->assertSame(1, $courserule['assignments_count']);
        $this->assertStringEndsWith('/local/taskflow/editrule.php?id=' . $this->courseruleid, $courserule['edit_url']);

        $personal = $byid[$this->personalruleid];
        $this->assertSame('user', $personal['type']);
        $this->assertFalse($personal['isactive']);
        $this->assertSame([], $personal['targettypes']);
        $this->assertSame(0, $personal['assignments_count']);

        $this->assertStringEndsWith('/local/taskflow/index.php', $result['links']['page']);
        $this->assertNotEmpty($result['links']['docs']);
        $this->assertStringContainsString('Rules payload (JSON)', $result['observation_full']);
        $this->assertSame(taskflow_preview_renderer_factory::TYPE_RULE_LIST, $result['preview']['type']);
        $this->assertEqualsCanonicalizing($this->ids($result), $result['preview']['payload']['ruleids']);
    }

    /**
     * query narrows by name (case-insensitive substring) and by numeric id.
     */
    public function test_query_narrows(): void {
        $result = $this->run_skill(['query' => 'PROTECTION']);
        $this->assertSame([$this->courseruleid], $this->ids($result));
        $this->assertSame(1, $result['total']);

        $result = $this->run_skill(['query' => (string)$this->competencyruleid]);
        $this->assertContains($this->competencyruleid, $this->ids($result));

        $result = $this->run_skill(['query' => 'does-not-exist']);
        $this->assertSame([], $result['rules']);
        $this->assertSame(0, $result['total']);
        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status']);
        $this->assertSame(get_string('agent_search_rules_none', 'local_taskflow'), $result['usermessage']);
    }

    /**
     * isactive, targettype and unitid narrow the list; limit caps rows but not total.
     */
    public function test_filters_and_limit(): void {
        $this->assertSame([$this->personalruleid], $this->ids($this->run_skill(['isactive' => false])));
        $this->assertEqualsCanonicalizing(
            [$this->courseruleid, $this->competencyruleid],
            $this->ids($this->run_skill(['isactive' => 'true']))
        );

        $this->assertSame([$this->courseruleid], $this->ids($this->run_skill(['targettype' => 'moodlecourse'])));
        $this->assertSame([$this->competencyruleid], $this->ids($this->run_skill(['targettype' => 'Competency'])));
        $this->assertSame([], $this->ids($this->run_skill(['targettype' => 'bookingoption'])));

        $this->assertEqualsCanonicalizing(
            [$this->courseruleid, $this->competencyruleid],
            $this->ids($this->run_skill(['unitid' => $this->cohortid]))
        );

        $limited = $this->run_skill(['limit' => 1]);
        $this->assertCount(1, $limited['rules']);
        $this->assertSame(3, $limited['total']);
        $this->assertSame(1, $limited['preview']['data']['limit']);

        $capped = $this->run_skill(['limit' => 1000]);
        $this->assertSame(search_rules_skill::MAX_LIMIT, $capped['preview']['data']['limit']);
    }

    /**
     * An unknown target type is rejected in preflight with its own issue code.
     */
    public function test_invalid_targettype_is_rejected(): void {
        global $USER;
        $preflight = (new search_rules_skill())->preflight(['targettype' => 'quiz'], $this->contextid, (int)$USER->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(search_rules_skill::ISSUE_INVALID_TARGETTYPE, $preflight->issuecodes);
    }

    /**
     * Without local/taskflow:viewrules preflight is invalid and execute returns an error.
     */
    public function test_requires_viewrules_capability(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $skill = new search_rules_skill();

        $preflight = $skill->preflight([], $this->contextid, (int)$user->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(taskflow_skill_base::ISSUE_SCOPE_DENIED, $preflight->issuecodes);

        $result = $skill->execute([], $this->contextid, (int)$user->id);
        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $result['status']);
        $this->assertSame([taskflow_skill_base::ISSUE_SCOPE_DENIED], $result['issue_codes']);
        $this->assertArrayNotHasKey('rules', $result);
    }

    /**
     * The declared preview renders into a taskflow_rule_list block naming the rules.
     */
    public function test_result_preview(): void {
        global $USER;
        $result = $this->run_skill(['query' => 'protection']);
        $preview = (new search_rules_skill())->get_result_preview($result, $this->contextid, (int)$USER->id);

        $this->assertNotNull($preview);
        $this->assertSame(taskflow_preview_renderer_factory::TYPE_RULE_LIST, $preview['type']);
        $this->assertStringContainsString('Data protection basics', $preview['html']);
        $this->assertStringContainsString('editrule.php?id=' . $this->courseruleid, $preview['html']);
        $this->assertSame([$this->courseruleid], $preview['payload']['ruleids']);
    }

    /**
     * execute() with the raw input (read-only chat path, no preflight) rejects an invalid target type
     * instead of silently filtering every rule away.
     */
    public function test_execute_rejects_invalid_targettype_without_preflight(): void {
        global $USER;
        $result = (new search_rules_skill())->execute(['targettype' => 'quiz'], $this->contextid, (int)$USER->id);

        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $result['status']);
        $this->assertSame([search_rules_skill::ISSUE_INVALID_TARGETTYPE], $result['issue_codes']);
        $this->assertArrayNotHasKey('rules', $result);
        $this->assertArrayNotHasKey('preview', $result);
    }
}
