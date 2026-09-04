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
use local_taskflow\local\units\organisational_unit_factory;
use local_taskflow\local\units\unit_hierarchy;
use local_taskflow\local\units\unit_relations;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\skills\list_units_skill;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use local_taskflow\wizard\local_wizard_dependency;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../local_wizard_dependency.php');

/**
 * Backend, hierarchy and counter behaviour of local_taskflow.list_units.
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\skills\list_units_skill
 * @covers     \local_taskflow\local\wizard\taskflow\preview\taskflow_units_tree_preview_renderer
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class list_units_skill_test extends advanced_testcase {
    /** @var \local_taskflow_generator */
    private $generator;

    /**
     * Setup: engine and standard adapter.
     */
    protected function setUp(): void {
        parent::setUp();
        local_wizard_dependency::require_installed();
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_taskflow');
        $this->generator->set_config_values('standard');
        \local_taskflow\local\external_adapter\external_api_base::destroy_instance();
    }

    /**
     * Teardown: singletons and hierarchy cache.
     */
    protected function tearDown(): void {
        parent::tearDown();
        unit_hierarchy::invalidate_cache();
        $this->generator->teardown();
    }

    /**
     * Switch to the own unit tables backend.
     */
    private function use_unit_backend(): void {
        set_config('organisational_unit_option', list_units_skill::BACKEND_UNIT, 'local_taskflow');
        organisational_unit_factory::teardown();
        unit_hierarchy::invalidate_cache();
        \cache_helper::invalidate_by_event('config', ['local_taskflow']);
    }

    /**
     * Insert a unit row into the own unit tables.
     *
     * @param string $name
     * @return int
     */
    private function create_unit(string $name): int {
        global $DB, $USER;
        return (int)$DB->insert_record('local_taskflow_units', (object)[
            'name' => $name,
            'description' => '',
            'criteria' => '',
            'timecreated' => time(),
            'timemodified' => time(),
            'usermodified' => (int)$USER->id,
        ]);
    }

    /**
     * Add a member to a unit.
     *
     * @param int $unitid
     * @param int $userid
     */
    private function add_member(int $unitid, int $userid): void {
        global $DB, $USER;
        $DB->insert_record('local_taskflow_unit_members', (object)[
            'unitid' => $unitid,
            'userid' => $userid,
            'active' => 1,
            'timeadded' => time(),
            'timemodified' => time(),
            'usermodified' => (int)$USER->id,
        ]);
    }

    /**
     * Grant a capability to a user through a fresh system role.
     *
     * @param int $userid
     * @param string[] $capabilities
     */
    private function grant(int $userid, array $capabilities): void {
        $roleid = $this->getDataGenerator()->create_role();
        foreach ($capabilities as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, context_system::instance()->id, true);
        }
        role_assign($roleid, $userid, context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Preflight + execute as the given user.
     *
     * @param array $input
     * @param int $userid
     * @return array{preflight:object,result:array|null}
     */
    private function run_skill(array $input, int $userid): array {
        $skill = new list_units_skill();
        $preflight = $skill->preflight($input, context_system::instance()->id, $userid);
        $result = null;
        if ($preflight->status === 'pass') {
            $result = $skill->execute($preflight->preparedinput, context_system::instance()->id, $userid);
        }
        return ['preflight' => $preflight, 'result' => $result];
    }

    /**
     * One unit row of a result.
     *
     * @param array $result
     * @param int $unitid
     * @return array
     */
    private function unit(array $result, int $unitid): array {
        foreach ((array)$result['units'] as $row) {
            if ((int)$row['id'] === $unitid) {
                return (array)$row;
            }
        }
        return [];
    }

    /**
     * Unit backend: parent/child hierarchy, member counts, rule counts and the parentid filter.
     */
    public function test_unit_backend_with_hierarchy(): void {
        $this->use_unit_backend();

        $parent = $this->create_unit('Administration');
        $child = $this->create_unit('Human resources');
        unit_relations::create($child, $parent);
        unit_relations::destroy_instance();
        unit_hierarchy::invalidate_cache();

        $user = $this->getDataGenerator()->create_user();
        $this->add_member($child, (int)$user->id);
        $this->generator->create_rule(['name' => 'Unit rule', 'unitid' => $parent]);

        $run = $this->run_skill([], (int)get_admin()->id);
        $this->assertSame('pass', $run['preflight']->status);
        $result = $run['result'];

        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status']);
        $this->assertSame(list_units_skill::BACKEND_UNIT, $result['backend']);
        $this->assertSame(2, $result['total']);

        $parentrow = $this->unit($result, $parent);
        $childrow = $this->unit($result, $child);
        $this->assertSame('Administration', $parentrow['name']);
        $this->assertSame(0, $parentrow['parentid']);
        $this->assertSame(1, $parentrow['rules_count']);
        $this->assertSame($parent, $childrow['parentid']);
        $this->assertSame(1, $childrow['members']);
        $this->assertSame(0, $childrow['rules_count']);
        $this->assertGreaterThan($parentrow['depth'], $childrow['depth']);

        // Name filter.
        $run = $this->run_skill(['query' => 'human'], (int)get_admin()->id);
        $this->assertSame([$child], array_column($run['result']['units'], 'id'));

        // The parentid filter returns the unit itself plus its descendants.
        $run = $this->run_skill(['parentid' => $parent], (int)get_admin()->id);
        $this->assertEqualsCanonicalizing([$parent, $child], array_column($run['result']['units'], 'id'));

        // Preview: nested details tree, no JavaScript.
        $preview = (new list_units_skill())
            ->get_result_preview($result, context_system::instance()->id, (int)get_admin()->id);
        $this->assertNotNull($preview);
        $this->assertSame(taskflow_preview_renderer_factory::TYPE_UNITS_TREE, $preview['type']);
        $this->assertStringContainsString('<details', $preview['html']);
        $this->assertStringContainsString('Administration', $preview['html']);
        $this->assertStringContainsString('Human resources', $preview['html']);
        $this->assertEqualsCanonicalizing([$parent, $child], $preview['payload']['unitids']);
    }

    /**
     * Cohort backend: cohorts are listed with their member counts.
     */
    public function test_cohort_backend(): void {
        set_config('organisational_unit_option', list_units_skill::BACKEND_COHORT, 'local_taskflow');
        organisational_unit_factory::teardown();
        \cache_helper::invalidate_by_event('config', ['local_taskflow']);

        $cohort = $this->getDataGenerator()->create_cohort(['name' => 'Engineering']);
        $user = $this->getDataGenerator()->create_user();
        cohort_add_member((int)$cohort->id, (int)$user->id);

        $run = $this->run_skill([], (int)get_admin()->id);
        $this->assertSame('pass', $run['preflight']->status);
        $result = $run['result'];

        $this->assertSame(list_units_skill::BACKEND_COHORT, $result['backend']);
        $this->assertSame([(int)$cohort->id], array_column($result['units'], 'id'));
        $this->assertSame(1, $this->unit($result, (int)$cohort->id)['members']);
    }

    /**
     * An unknown parent unit is a hard block; viewreports is required.
     */
    public function test_unknown_parent_and_missing_capability(): void {
        $this->use_unit_backend();
        $this->create_unit('Administration');

        $run = $this->run_skill(['parentid' => 9999], (int)get_admin()->id);
        $this->assertSame('hard_block', $run['preflight']->status);
        $this->assertContains(list_units_skill::ISSUE_UNIT_NOT_FOUND, $run['preflight']->issuecodes);

        $user = $this->getDataGenerator()->create_user();
        $run = $this->run_skill([], (int)$user->id);
        $this->assertSame('hard_block', $run['preflight']->status);
        $this->assertContains(taskflow_skill_base::ISSUE_SCOPE_DENIED, $run['preflight']->issuecodes);

        // The capability is also declared natively for the engine's hard gate.
        $this->assertContains(
            list_units_skill::CAP_VIEWREPORTS,
            (new list_units_skill())->get_required_native_capabilities()
        );

        $this->grant((int)$user->id, [list_units_skill::CAP_VIEWREPORTS]);
        $run = $this->run_skill([], (int)$user->id);
        $this->assertSame('pass', $run['preflight']->status);
        $this->assertSame(1, $run['result']['total']);
    }
}
