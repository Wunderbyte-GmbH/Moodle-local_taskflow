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
use local_taskflow\local\wizard\taskflow\skills\list_rule_properties_skill;
use local_taskflow\wizard\local_wizard_dependency;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../local_wizard_dependency.php');
require_once(__DIR__ . '/skill_description_budget_test.php');

/**
 * Tests for the local_taskflow.list_rule_properties skill.
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\skills\list_rule_properties_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class list_rule_properties_skill_test extends advanced_testcase {
    /** @var \local_taskflow_generator */
    private $generator;

    /**
     * Setup: engine required, standard adapter configured.
     */
    protected function setUp(): void {
        parent::setUp();
        // Pin the mocked clock of tool_mocktesttime to now: other suites advance it and never reset it.
        if (class_exists('\\tool_mocktesttime\\time_mock')) {
            \tool_mocktesttime\time_mock::reset_mock_time();
        }
        local_wizard_dependency::require_installed();
        $this->resetAfterTest();
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_taskflow');
        $this->generator->set_config_values('standard');
    }

    /**
     * Teardown: singletons.
     */
    protected function tearDown(): void {
        parent::tearDown();
        $this->generator->teardown();
    }

    /**
     * Preflight + execute as admin.
     *
     * @param array $input
     * @return array
     */
    private function run_as_admin(array $input = []): array {
        global $USER;
        $this->setAdminUser();
        $skill = new list_rule_properties_skill();
        $dto = $skill->preflight($input, context_system::instance()->id, (int)$USER->id);
        $this->assertSame('pass', $dto->status);
        return $skill->execute($dto->preparedinput, context_system::instance()->id, (int)$USER->id);
    }

    /**
     * Rows of a list indexed by a key column.
     *
     * @param array $rows
     * @param string $column
     * @return array<string,array>
     */
    private function by(array $rows, string $column = 'key'): array {
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(string)$row[$column]] = $row;
        }
        return $indexed;
    }

    /**
     * Contract: name, capability, schema shape.
     */
    public function test_contract(): void {
        $skill = new list_rule_properties_skill();
        $this->assertSame('local_taskflow.list_rule_properties', $skill->get_name());
        $this->assertTrue($skill->is_read_only());
        $this->assertSame(['local/taskflow:viewrules'], $skill->get_required_native_capabilities());

        $schema = $skill->get_schema();
        $this->assertSame([], $schema['prompt_meta']['input_fields_for_prompt']);
        $this->assertArrayHasKey('outputlang', $schema['properties']);
        $this->assertSame(['system'], $schema['prompt_meta']['context_scopes']);
    }

    /**
     * The catalog carries every group with the expected entries.
     */
    public function test_execute_returns_full_catalog(): void {
        $result = $this->run_as_admin();

        foreach (
            ['status', 'detail', 'usermessage', 'observation_full', 'links', 'issue_codes', 'rule_fields', 'filter_types',
            'operators', 'target_types', 'request_types', 'request_receivers', 'message_types', 'message_timing',
            'statuses', 'placeholders', 'preview'] as $key
        ) {
            $this->assertArrayHasKey($key, $result, "Missing result key $key");
        }
        $this->assertSame('executed', $result['status']);

        $fields = $this->by($result['rule_fields']);
        $expectedfields = ['name', 'ruletype', 'duedatetype', 'duration', 'fixeddate', 'extensionperiod', 'cyclicvalidation',
            'cyclicduration', 'activationdelay', 'inheritance', 'recursive'];
        foreach ($expectedfields as $key) {
            $this->assertArrayHasKey($key, $fields);
            $this->assertNotSame('', $fields[$key]['label']);
        }
        $this->assertSame(['duration', 'fixeddate'], array_column($fields['duedatetype']['options'], 'key'));

        $operators = $this->by($result['operators']);
        $this->assertArrayHasKey('nowminusdays', $operators);
        $this->assertNotSame('', $operators['nowminusdays']['semantics']);
        $this->assertStringNotContainsString('[[', $operators['nowminusdays']['semantics']);
        $this->assertTrue($operators['nowminusdays']['runtime_supported']);
        $this->assertArrayHasKey('since', $operators);
        $this->assertTrue($operators['since']['runtime_supported']);
        $this->assertArrayHasKey('isin', $operators);
        $this->assertStringContainsString(';', $operators['isin']['semantics']);

        $filtertypes = $this->by($result['filter_types']);
        $this->assertTrue($filtertypes['user_field']['runtime_supported']);
        $this->assertTrue($filtertypes['user_profile_field']['runtime_supported']);
        $this->assertSame(['firstaccess', 'lastaccess'], array_column($filtertypes['user_field']['fields'], 'key'));

        $targets = $this->by($result['target_types']);
        $this->assertArrayHasKey('moodlecourse', $targets);
        $this->assertArrayHasKey('competency', $targets);
        $this->assertTrue($targets['moodlecourse']['available']);

        $this->assertNotEmpty($result['request_types']);
        $this->assertNotEmpty($result['request_receivers']);
        $this->assertContains('standard', array_column($result['message_types'], 'key'));
        $timing = $this->by($result['message_timing']);
        $this->assertSame(['start', 'end', 'status_change'], array_column($timing['sendstart']['options'], 'key'));
        $this->assertSame(['before', 'after'], array_column($timing['senddirection']['options'], 'key'));

        $statuses = $this->by($result['statuses'], 'id');
        $overdue = assignment_status_facade::get_status_identifier('overdue');
        $completed = assignment_status_facade::get_status_identifier('completed');
        $this->assertSame(assignment_status_facade::get_specific_names($overdue), $statuses[(string)$overdue]['label']);
        $this->assertSame(assignment_status_facade::get_specific_names($completed), $statuses[(string)$completed]['label']);
        $this->assertSame('overdue', $statuses[(string)$overdue]['name']);
        $this->assertFalse($statuses[(string)$overdue]['excluded']);

        $this->assertContains('firstname', $result['placeholders']);
        $this->assertContains('due_date', $result['placeholders']);

        $this->assertStringContainsString('nowminusdays', $result['observation_full']);
        $this->assertGreaterThanOrEqual(5, count($result['links']['docs']));
        $this->assertSame('taskflow_catalog', $result['preview']['type']);
    }

    /**
     * Statuses excluded by the active adapter are flagged.
     */
    public function test_statuses_carry_adapter_exclusion(): void {
        $this->generator->set_config_values('tuines');
        $result = $this->run_as_admin();

        $statuses = $this->by($result['statuses'], 'id');
        foreach (explode(',', (string)get_config('taskflowadapter_tuines', 'excludestatus')) as $excludedid) {
            $this->assertArrayHasKey($excludedid, $statuses);
            $this->assertTrue($statuses[$excludedid]['excluded'], "Status $excludedid should be excluded");
        }
        $this->assertFalse($statuses[(string)assignment_status_facade::get_status_identifier('assigned')]['excluded']);
    }

    /**
     * Without local/taskflow:viewrules preflight blocks and execute answers gracefully.
     */
    public function test_user_without_viewrules_is_blocked(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $skill = new list_rule_properties_skill();

        $dto = $skill->preflight([], context_system::instance()->id, (int)$user->id);
        $this->assertSame('hard_block', $dto->status);
        $this->assertContains('NO_NATIVE_CAPABILITY', $dto->issuecodes);

        $result = $skill->execute([], context_system::instance()->id, (int)$user->id);
        $this->assertSame('error', $result['status']);
        $this->assertContains('NO_NATIVE_CAPABILITY', $result['issue_codes']);
    }

    /**
     * The preview renders operators and statuses as a catalog card.
     */
    public function test_result_preview_renders_catalog(): void {
        global $USER;
        $result = $this->run_as_admin();
        $skill = new list_rule_properties_skill();
        $preview = $skill->get_result_preview($result, context_system::instance()->id, (int)$USER->id);

        $this->assertNotNull($preview);
        $this->assertSame('taskflow_catalog', $preview['type']);
        $this->assertStringContainsString('nowminusdays', $preview['html']);
        $overdue = assignment_status_facade::get_status_identifier('overdue');
        $this->assertStringContainsString(s(assignment_status_facade::get_specific_names($overdue)), $preview['html']);
        $this->assertStringContainsString('bg-danger', $preview['html']);
        $this->assertStringContainsString('&lt;firstname&gt;', $preview['html']);
        $this->assertSame([], $preview['payload']);
    }

    /**
     * The anchors cover the four baseline question forms that kept routing to the docs skill
     * (taskflow #466, F24): operators and their semantics, target types and request receivers,
     * the field menu with message timing, and date-field operators/evaluation.
     */
    public function test_anchors_cover_the_rule_property_question_forms(): void {
        $schema = (new list_rule_properties_skill())->get_schema();
        $haystack = \core_text::strtolower(implode(' ', (array)($schema['example_utterances'] ?? [])));
        foreach (['operator', 'target', 'receiver', 'timing', 'date field', 'evaluated'] as $needle) {
            $this->assertStringContainsString($needle, $haystack, 'anchor missing for: ' . $needle);
        }
        $this->assertGreaterThanOrEqual(8, count((array)$schema['example_utterances']));
        // Wave 17 (#2453): the boundary against the docs skill moved out of the description — which is
        // embedding anchor #0 and cannot carry a negation — into the NOT: card line the selector reads.
        $this->assertStringContainsString('documentation', \core_text::strtolower((string)$schema['not']));
        $this->assertStringNotContainsString(
            'documentation',
            \core_text::strtolower((string)$schema['description']),
            'the boundary against the docs skill must not be embedded with this skill'
        );
    }

    /**
     * Wave 32 (LRP-4): the operators flagged "re-checked daily" are exactly those whose rules the scheduled task
     * reschedule_rules re-triggers - derived from the task itself, so the flag cannot drift from the code.
     */
    public function test_daily_recheck_operators_mirror_the_reschedule_task(): void {
        $operators = (new \local_taskflow\local\operators\string_compare_operators())->get_operator_keys();
        $byrule = [];
        foreach ($operators as $operator) {
            $ruleid = (int)$this->generator->create_rule([
                'name' => 'Operator ' . $operator,
                'filters' => [['operator' => (string)$operator, 'value' => '1', 'userprofilefield' => 'contract']],
            ]);
            $byrule[$ruleid] = (string)$operator;
        }

        $method = new \ReflectionMethod(\local_taskflow\task\reschedule_rules::class, 'get_relevant_rules');
        $method->setAccessible(true);
        $picked = [];
        foreach ((array)$method->invoke(new \local_taskflow\task\reschedule_rules()) as $record) {
            if (isset($byrule[(int)$record->id])) {
                $picked[] = $byrule[(int)$record->id];
            }
        }
        sort($picked);
        $expected = list_rule_properties_skill::DAILY_RECHECK_OPERATORS;
        sort($expected);
        $this->assertSame($expected, $picked);
    }

    /**
     * Wave 32 (LRP-4): the answer to "is a date condition frozen when the rule is created?" is in the observation -
     * when filters are evaluated and which operators the daily task re-checks - and the card says so in its window.
     */
    public function test_observation_and_card_carry_when_filters_are_evaluated(): void {
        $result = $this->run_as_admin();
        $operators = $this->by($result['operators']);
        $this->assertTrue($operators['nowminusdays']['daily_recheck']);
        $this->assertFalse($operators['since']['daily_recheck']);
        $this->assertStringContainsString('Filter evaluation: ', $result['observation_full']);
        $this->assertStringContainsString(list_rule_properties_skill::DAILY_RECHECK_TASK, $result['observation_full']);
        $this->assertSame(list_rule_properties_skill::DAILY_RECHECK_OPERATORS, $result['evaluation']['operators']);

        $schema = (new list_rule_properties_skill())->get_schema();
        $window = skill_description_budget_test::retained((string)$schema['description']);
        $this->assertStringContainsString('when a filter is evaluated', $window);
        // The selector compares IS/NOT (A/B 2026-09-26): IS names the evaluation question, NOT still names the docs
        // skill (its NOT line names this skill back), both inside the 120-character clause guideline.
        $this->assertStringContainsString('evaluated', (string)$schema['is']);
        $this->assertStringContainsString('wizard.explain_docs', (string)$schema['not']);
        foreach (['is', 'not'] as $key) {
            $this->assertLessThanOrEqual(120, \core_text::strlen((string)$schema[$key]), $key);
        }
        $triggers = (new list_rule_properties_skill())->get_message_triggers();
        $this->assertStringContainsString('evaluated', (string)$triggers[0]['description']);
    }
}
