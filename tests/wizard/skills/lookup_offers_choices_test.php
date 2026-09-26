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

namespace local_taskflow;

use advanced_testcase;
use context_system;
use local_taskflow\local\assignments\assignment;
use local_taskflow\local\wizard\taskflow\skills\diagnose_assignment_status_skill;
use local_taskflow\local\wizard\taskflow\skills\search_assignments_skill;
use local_taskflow\local\wizard\taskflow\taskflow_message_resolver;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use local_taskflow\wizard\local_wizard_dependency;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../local_wizard_dependency.php');

/**
 * A lookup that finds nothing offers the existing choices instead of ending in "not found" (wave 30).
 *
 * George, 2026-09-24/25: when the model's value resolves nothing, the skill returns the choices that exist so the model
 * can pick one by its id - the code never translates or guesses. Each choice carries `id` (the engine's choice
 * contract). Cases from the baseline: DAS-2 ("Warum steht bei Herrn Kowalczyk 'verlängert'?" names a person, no
 * rule), DMD-2/DMD-4 (template names), TSA-4 (a unit name), rule names across languages.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_taskflow\local\wizard\taskflow\taskflow_skill_base
 * @covers     \local_taskflow\local\wizard\taskflow\taskflow_message_resolver
 * @covers     \local_taskflow\local\wizard\taskflow\skills\search_assignments_skill
 */
final class lookup_offers_choices_test extends advanced_testcase {
    /** @var \local_taskflow_generator */
    private $generator;

    /** @var \stdClass */
    private \stdClass $employee;

    /** @var \stdClass */
    private \stdClass $stranger;

    /** @var int */
    private int $ruleid = 0;

    /**
     * One person with one assignment.
     */
    protected function setUp(): void {
        parent::setUp();
        if (class_exists('\\tool_mocktesttime\\time_mock')) {
            \tool_mocktesttime\time_mock::reset_mock_time();
        }
        local_wizard_dependency::require_installed();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_taskflow');
        $this->generator->set_config_values('standard');
        $this->generator->create_custom_profile_fields(['supervisor', 'deputy']);
        \local_taskflow\local\external_adapter\external_api_base::destroy_instance();

        $this->employee = $this->getDataGenerator()->create_user(['firstname' => 'Piotr', 'lastname' => 'Kowalczyk']);
        $this->stranger = $this->getDataGenerator()->create_user();
        $this->ruleid = (int)$this->generator->create_rule(['name' => 'Data protection']);
        $this->generator->create_user_assignment((int)$this->employee->id, $this->ruleid);
        assignment::destroy_instance();
    }

    /**
     * Teardown: singletons.
     */
    protected function tearDown(): void {
        parent::tearDown();
        $this->generator->teardown();
    }

    /**
     * The id of the employee's assignment for a rule.
     *
     * @param int $ruleid
     * @return int
     */
    private function assignmentid(int $ruleid): int {
        global $DB;
        return (int)$DB->get_field(
            'local_taskflow_assignment',
            'id',
            ['userid' => $this->employee->id, 'ruleid' => $ruleid],
            MUST_EXIST
        );
    }

    /**
     * A person with exactly one assignment needs no rule: that assignment is the target.
     */
    public function test_a_person_with_one_assignment_needs_no_rule(): void {
        $skill = new diagnose_assignment_status_skill();
        $preflight = $skill->preflight(['userquery' => 'Kowalczyk'], context_system::instance()->id, (int)get_admin()->id);

        $this->assertSame('pass', $preflight->status, json_encode($preflight->to_array()));
        $result = $skill->execute($preflight->preparedinput, context_system::instance()->id, (int)get_admin()->id);
        $this->assertSame($this->assignmentid($this->ruleid), (int)$result['resultid']);
    }

    /**
     * DAS-2: several assignments are offered with id, rule name and status - never guessed.
     */
    public function test_a_person_with_several_assignments_offers_them(): void {
        $second = (int)$this->generator->create_rule(['name' => 'Fire safety']);
        $this->generator->create_user_assignment((int)$this->employee->id, $second);
        assignment::destroy_instance();

        $preflight = (new diagnose_assignment_status_skill())->preflight(
            ['userquery' => 'Kowalczyk'],
            context_system::instance()->id,
            (int)get_admin()->id
        );

        $this->assertSame('hard_block', $preflight->status);
        $issue = $preflight->issues[0];
        $this->assertSame(taskflow_skill_base::ISSUE_ASSIGNMENT_CHOICE, $issue['code']);
        $this->assertSame('needs_clarification', $issue['severity']);
        $ids = array_column($issue['candidates'], 'id');
        sort($ids);
        $expected = [$this->assignmentid($this->ruleid), $this->assignmentid($second)];
        sort($expected);
        $this->assertSame($expected, $ids);
        $this->assertEqualsCanonicalizing(['Data protection', 'Fire safety'], array_column($issue['candidates'], 'label'));
        $this->assertNotEmpty($issue['candidates'][0]['status']);
        $this->assertStringNotContainsString('TASKFLOW_', (string)$issue['message']);
    }

    /**
     * The choices never include what the acting user may not see.
     */
    public function test_invisible_assignments_are_not_offered(): void {
        $second = (int)$this->generator->create_rule(['name' => 'Fire safety']);
        $this->generator->create_user_assignment((int)$this->employee->id, $second);
        assignment::destroy_instance();
        $this->setUser($this->stranger);

        $preflight = (new diagnose_assignment_status_skill())->preflight(
            ['userquery' => 'Kowalczyk'],
            context_system::instance()->id,
            (int)$this->stranger->id
        );

        foreach ($preflight->issues as $issue) {
            $this->assertNotSame(taskflow_skill_base::ISSUE_ASSIGNMENT_CHOICE, (string)($issue['code'] ?? ''));
            $this->assertEmpty(array_intersect(
                array_column((array)($issue['candidates'] ?? []), 'id'),
                [$this->assignmentid($this->ruleid), $this->assignmentid($second)]
            ));
        }
    }

    /**
     * A rule name that matches nothing (another language) next to a named person offers that person's assignments
     * (wave 32: context first) - even a single one is offered, never taken silently.
     */
    public function test_a_rule_name_that_matches_nothing_offers_the_persons_assignments(): void {
        $preflight = (new diagnose_assignment_status_skill())->preflight(
            ['userquery' => 'Kowalczyk', 'rulequery' => 'Datenschutz'],
            context_system::instance()->id,
            (int)get_admin()->id
        );

        $this->assertSame('hard_block', $preflight->status);
        $issue = $preflight->issues[0];
        $this->assertSame(taskflow_skill_base::ISSUE_ASSIGNMENT_CHOICE, $issue['code']);
        $this->assertSame([$this->assignmentid($this->ruleid)], array_column($issue['candidates'], 'id'));
        $this->assertStringContainsString('Data protection', (string)$issue['message']);
    }

    /**
     * Wave 32 (DAS-2 L35/L39): the status the user saw, put into rulequery, is no rule - the person's assignments are
     * offered with their status, so the one in that status can be picked by its attributes.
     */
    public function test_a_status_word_in_rulequery_offers_the_persons_assignments(): void {
        $second = (int)$this->generator->create_rule(['name' => 'Fire safety']);
        $this->generator->create_user_assignment((int)$this->employee->id, $second);
        assignment::destroy_instance();

        $preflight = (new diagnose_assignment_status_skill())->preflight(
            ['userquery' => 'Kowalczyk', 'rulequery' => 'verlängert'],
            context_system::instance()->id,
            (int)get_admin()->id
        );

        $this->assertSame('hard_block', $preflight->status);
        $issue = $preflight->issues[0];
        $this->assertSame(taskflow_skill_base::ISSUE_ASSIGNMENT_CHOICE, $issue['code']);
        $ids = array_column($issue['candidates'], 'id');
        sort($ids);
        $expected = [$this->assignmentid($this->ruleid), $this->assignmentid($second)];
        sort($expected);
        $this->assertSame($expected, $ids);
        foreach ($issue['candidates'] as $candidate) {
            $this->assertNotSame('', (string)$candidate['status']);
        }
    }

    /**
     * Without a named person an unmatched rule name still offers the rules that exist.
     */
    public function test_a_rule_name_without_person_still_offers_the_rules(): void {
        $preflight = (new diagnose_assignment_status_skill())->preflight(
            ['rulequery' => 'Datenschutz'],
            context_system::instance()->id,
            (int)get_admin()->id
        );

        $this->assertSame('hard_block', $preflight->status);
        $issue = $preflight->issues[0];
        $this->assertSame(taskflow_skill_base::ISSUE_RULE_NOT_FOUND, $issue['code']);
        $this->assertContains($this->ruleid, array_column($issue['candidates'], 'id'));
    }

    /**
     * A template name that matches nothing offers the templates that exist.
     */
    public function test_a_template_name_that_matches_nothing_offers_the_templates(): void {
        global $DB;
        $messageid = (int)$DB->insert_record('local_taskflow_messages', (object)[
            'name' => 'Request opened',
            'class' => 'onrequestcreated',
            'message' => json_encode(['heading' => 'Opened', 'body' => '<p>Opened</p>']),
            'priority' => 2,
            'sending_settings' => json_encode([]),
            'usermodified' => (int)get_admin()->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $issue = taskflow_message_resolver::issue(
            taskflow_message_resolver::resolve(['messagequery' => 'Antrag eröffnet']),
            'messagequery'
        );

        $this->assertSame(taskflow_message_resolver::ISSUE_MESSAGE_NOT_FOUND, $issue['code']);
        $this->assertContains($messageid, array_column($issue['candidates'], 'id'));
        $this->assertStringContainsString('Request opened', (string)$issue['message']);
    }

    /**
     * A unit name that matches nothing offers the units that exist.
     */
    public function test_a_unit_name_that_matches_nothing_offers_the_units(): void {
        global $DB, $USER;
        set_config('organisational_unit_option', 'unit', 'local_taskflow');
        $unitid = (int)$DB->insert_record('local_taskflow_units', (object)[
            'name' => 'Abteilung Facility',
            'description' => '',
            'criteria' => '',
            'timecreated' => time(),
            'timemodified' => time(),
            'usermodified' => (int)$USER->id,
        ]);
        \local_taskflow\local\units\unit_hierarchy::invalidate_cache();

        $preflight = (new search_assignments_skill())->preflight(
            ['unitquery' => 'Marketing'],
            context_system::instance()->id,
            (int)get_admin()->id
        );

        $this->assertSame('hard_block', $preflight->status);
        $issue = $preflight->issues[0];
        $this->assertSame(search_assignments_skill::ISSUE_UNIT_NOT_FOUND, $issue['code']);
        $this->assertContains($unitid, array_column($issue['candidates'], 'id'));
    }

    /**
     * The field descriptions of the two skills touched here reach the constructor whole (160-character cut of the
     * agent's schema projection; "leave out for my people" sat behind it, TSA-4).
     */
    public function test_the_touched_field_descriptions_are_not_cut(): void {
        foreach ([new search_assignments_skill(), new diagnose_assignment_status_skill()] as $skill) {
            foreach ((array)($skill->get_schema()['properties'] ?? []) as $field => $definition) {
                $text = trim((string)preg_replace('/\s+/u', ' ', (string)($definition['description'] ?? '')));
                $this->assertLessThanOrEqual(160, \core_text::strlen($text), $skill->get_name() . '.' . $field . ': ' . $text);
            }
        }
    }
}
