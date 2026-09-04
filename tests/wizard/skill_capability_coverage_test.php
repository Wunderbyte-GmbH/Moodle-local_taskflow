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

namespace local_taskflow\wizard;

use advanced_testcase;
use local_taskflow\local\wizard\skill_provider;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/local_wizard_dependency.php');

/**
 * Every discovered local_taskflow skill has its name-derived capability defined in db/access.php.
 *
 * Passes trivially while no skill exists yet; the provider and registry integration are
 * still exercised (no contract diagnostics may mention local_taskflow).
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\skill_provider
 * @covers     \local_taskflow\local\wizard\taskflow\taskflow_skill_base
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class skill_capability_coverage_test extends advanced_testcase {
    /**
     * Setup: needs an engine.
     */
    protected function setUp(): void {
        parent::setUp();
        local_wizard_dependency::require_installed();
        $this->resetAfterTest();
        // The Phase-A capabilities ship in db/access.php; until the version bump installs them on
        // this site, refresh the capability table for the test transaction.
        update_capabilities('local_taskflow');
    }

    /**
     * Every skill of the provider maps to a defined capability and declares the system scope.
     */
    public function test_every_skill_has_a_defined_name_derived_capability(): void {
        $validator = local_wizard_dependency::engine_class('skill_contract_validator');
        $provider = new skill_provider();
        $this->assertSame('local/taskflow', $provider->get_component());

        $undefined = [];
        $names = [];
        foreach ($provider->get_skills() as $skill) {
            $this->assertInstanceOf(taskflow_skill_base::class, $skill);
            $name = $skill->get_name();
            $this->assertStringStartsWith(skill_provider::SKILL_NAMESPACE . '.', $name);
            $this->assertNotContains($name, $names, 'Duplicate skill name ' . $name);
            $names[] = $name;

            $capability = $validator::build_skill_capability_name($provider->get_component(), $name);
            $this->assertStringStartsWith('local/taskflow:skill_local_taskflow_', $capability);
            if (get_capability_info($capability) === null) {
                $undefined[] = $name . ' -> ' . $capability;
            }

            $contract = $skill->get_prompt_contract();
            $payload = method_exists($contract, 'to_array') ? (array)$contract->to_array() : (array)$contract;
            $scopes = (array)($payload['context_scopes'] ?? ($contract->context_scopes ?? []));
            $this->assertSame(['system'], array_values($scopes), $name . ' must declare context_scopes [system]');
        }

        $this->assertSame([], $undefined, 'Skills without a defined capability in db/access.php: ' . implode('; ', $undefined));
        $this->assertSame([], $provider->get_discovery_diagnostics());
    }

    /**
     * The Phase-A capabilities exist regardless of which skill classes are present yet.
     */
    public function test_phase_a_capabilities_are_defined(): void {
        foreach (
            [
                'get_assignment_details', 'get_rule_details', 'get_user_taskflow_profile', 'list_rule_properties',
                'list_settings', 'search_assignments', 'search_rules',
            ] as $name
        ) {
            $capability = 'local/taskflow:skill_local_taskflow_' . $name;
            $info = get_capability_info($capability);
            $this->assertNotNull($info, $capability . ' is not defined');
            $this->assertSame(CONTEXT_SYSTEM, (int)$info->contextlevel);
            $this->assertSame('read', (string)$info->captype);
            $this->assertTrue(
                get_string_manager()->string_exists('taskflow:skill_local_taskflow_' . $name, 'local_taskflow'),
                'Capability string missing for ' . $capability
            );
        }
    }

    /**
     * The engine registry accepts the provider without contract diagnostics for local_taskflow.
     */
    public function test_registry_has_no_taskflow_contract_diagnostics(): void {
        $registryclass = local_wizard_dependency::engine_class('skill_registry');
        $registry = $registryclass::make_default();

        $offending = array_values(array_filter(
            (array)$registry->get_contract_diagnostics(),
            static fn($line): bool => stripos((string)$line, 'taskflow') !== false
        ));
        $this->assertSame([], $offending);

        $contracts = (array)$registry->get_skill_contracts();
        foreach ($contracts as $skillname => $meta) {
            if ((string)($meta['component'] ?? '') !== 'local/taskflow') {
                continue;
            }
            $caps = (array)$registry->get_skill_capabilities((string)$skillname);
            $this->assertContains(
                'local/taskflow:skill_' . str_replace('.', '_', (string)$skillname),
                $caps,
                $skillname . ' does not expose its name-derived capability'
            );
            foreach ($caps as $cap) {
                $this->assertNotNull(get_capability_info($cap), $skillname . ' requires undefined capability ' . $cap);
            }
        }
    }

    /**
     * Prompt packs never carry lexical trigger lists (HARD RULE).
     */
    public function test_prompt_packs_carry_no_triggers(): void {
        foreach ((new skill_provider())->get_contextual_prompt_packs() as $pack) {
            $this->assertArrayNotHasKey('triggers', $pack);
        }
    }
}
