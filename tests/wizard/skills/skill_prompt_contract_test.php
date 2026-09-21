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
use local_taskflow\local\wizard\skill_provider;
use local_taskflow\local\wizard\taskflow\skills\diagnose_message_delivery_skill;
use local_taskflow\local\wizard\taskflow\skills\preview_message_skill;
use local_taskflow\wizard\local_wizard_dependency;
use ReflectionMethod;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../local_wizard_dependency.php');

/**
 * Structural contract of what every taskflow skill hands to the planner's constructor card.
 *
 * The constructor never sees the property schema — only the description, `minimal_input`
 * (prompt_meta input_fields_for_prompt), the example keys and the example VALUES. A sentence like
 * "userquery (or userid), status, overdueonly" in minimal_input renders as ONE required token, and
 * an example that advertises `messageid` makes the model ask for an id although the name is in
 * the prompt (baseline runs 7/8 2026-09-16: TSA-1/2/3, PM-1/4, DMD-3/4; tickets #468, #469, #470).
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\skill_provider
 * @covers     \local_taskflow\local\wizard\taskflow\skills\preview_message_skill
 * @covers     \local_taskflow\local\wizard\taskflow\skills\diagnose_message_delivery_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class skill_prompt_contract_test extends advanced_testcase {
    /**
     * Setup: needs an engine.
     */
    protected function setUp(): void {
        parent::setUp();
        if (class_exists('\\tool_mocktesttime\\time_mock')) {
            \tool_mocktesttime\time_mock::reset_mock_time();
        }
        local_wizard_dependency::require_installed();
        $this->resetAfterTest();
    }

    /**
     * The prompt contract payload of a skill (protected in the engine base class).
     *
     * @param object $skill
     * @return array
     */
    private function contract(object $skill): array {
        $method = new ReflectionMethod($skill, 'prompt_contract_payload');
        $method->setAccessible(true);
        return (array)$method->invoke($skill);
    }

    /**
     * Every minimal_input entry and every example key is a real schema property.
     */
    public function test_minimal_input_and_example_keys_are_schema_properties(): void {
        $violations = [];
        foreach ((new skill_provider())->get_skills() as $skill) {
            $name = $skill->get_name();
            $properties = array_keys((array)($skill->get_schema()['properties'] ?? []));
            $contract = $this->contract($skill);
            foreach ((array)($contract['minimal_input'] ?? []) as $entry) {
                if (!in_array($entry, $properties, true)) {
                    $violations[] = $name . ' minimal_input "' . $entry . '"';
                }
            }
            foreach (array_keys((array)$skill->get_example_input()) as $key) {
                if (!in_array($key, $properties, true)) {
                    $violations[] = $name . ' example key "' . $key . '"';
                }
            }
        }
        $this->assertSame(
            [],
            $violations,
            "prompt contract entries that are not schema properties:\n" . implode("\n", $violations)
        );
    }

    /**
     * The message skills advertise the name query, not the template id, in their example.
     */
    public function test_message_skill_examples_advertise_the_name_query(): void {
        foreach ([new preview_message_skill(), new diagnose_message_delivery_skill()] as $skill) {
            $example = (array)$skill->get_example_input();
            $this->assertArrayHasKey('messagequery', $example, $skill->get_name());
            $this->assertArrayNotHasKey('messageid', $example, $skill->get_name());
            $this->assertArrayHasKey('assignmentid', $example, $skill->get_name());
        }
    }
}
