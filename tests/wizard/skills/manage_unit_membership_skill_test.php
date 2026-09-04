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
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\skills\manage_unit_membership_skill;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use local_taskflow\wizard\local_wizard_dependency;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../local_wizard_dependency.php');

/**
 * Membership writes, verification and asynchronous reporting of
 * local_taskflow.manage_unit_membership.
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\skills\manage_unit_membership_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class manage_unit_membership_skill_test extends advanced_testcase {
    /** @var \local_taskflow_generator */
    private $generator;

    /** @var \stdClass The person whose membership changes. */
    private \stdClass $employee;

    /** @var int Unit with one rule attached. */
    private int $unitid = 0;

    /** @var int Rule attached to the unit. */
    private int $ruleid = 0;

    /**
     * Setup: engine, standard adapter, own unit backend, one unit with one rule.
     */
    protected function setUp(): void {
        parent::setUp();
        local_wizard_dependency::require_installed();
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_taskflow');
        $this->generator->set_config_values('standard');
        \local_taskflow\local\external_adapter\external_api_base::destroy_instance();
        $this->use_unit_backend();

        $this->employee = $this->getDataGenerator()->create_user(['firstname' => 'Anna', 'lastname' => 'Muster']);
        $this->unitid = $this->create_unit('Administration');
        $this->ruleid = (int)$this->generator->create_rule(['name' => 'Data protection', 'unitid' => $this->unitid]);
    }

    /**
     * Teardown: singletons and the hierarchy cache.
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
        set_config('organisational_unit_option', manage_unit_membership_skill::BACKEND_UNIT, 'local_taskflow');
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
     * Preflight as the current (admin) user.
     *
     * @param array $input
     * @return object
     */
    private function preflight(array $input) {
        global $USER;
        return (new manage_unit_membership_skill())
            ->preflight($input, context_system::instance()->id, (int)$USER->id);
    }

    /**
     * Whether the user currently is a member of the unit.
     *
     * @param int $userid
     * @return bool
     */
    private function is_member(int $userid): bool {
        global $DB;
        return $DB->record_exists('local_taskflow_unit_members', ['unitid' => $this->unitid, 'userid' => $userid]);
    }

    /**
     * Adding makes the user a member and reports the assignment part as queued.
     */
    public function test_add_makes_member_and_reports_queued(): void {
        global $USER;

        $preflight = $this->preflight([
            'userid' => (int)$this->employee->id,
            'unitid' => $this->unitid,
            'action' => manage_unit_membership_skill::ACTION_ADD,
        ]);
        $this->assertSame('soft_block', $preflight->status);
        $this->assertContains(manage_unit_membership_skill::ISSUE_CONFIRM, $preflight->issuecodes);

        $skill = new manage_unit_membership_skill();
        $description = $skill->describe_proposed_action($preflight->preparedinput);
        $this->assertIsArray($description);
        $values = implode(' | ', array_column($description['rows'], 'value'));
        $this->assertStringContainsString('Anna Muster', $values);
        $this->assertStringContainsString('Administration', $values);
        // The rules of the unit and the import warning are part of the proposal.
        $this->assertStringContainsString('Data protection', $values);
        $this->assertStringContainsString('standard', $values);

        $result = $skill->execute(
            $preflight->preparedinput,
            context_system::instance()->id,
            (int)$USER->id
        );

        $this->assertSame(taskflow_skill_base::STATUS_QUEUED, $result['status'], (string)$result['detail']);
        $this->assertSame([], $result['verification']['unverified']);
        $this->assertNotEmpty($result['pending_tasks']);
        $this->assertTrue($this->is_member((int)$this->employee->id));
        $this->assertTrue((bool)$result['ismember']);
        $this->assertSame(
            taskflow_preview_renderer_factory::TYPE_UNITS_TREE,
            (string)$result['preview']['type']
        );
        $this->assertSame([$this->unitid], $result['preview']['payload']['unitids']);
    }

    /**
     * Removing ends the membership and the verification confirms it.
     */
    public function test_remove_ends_membership(): void {
        global $DB, $USER;

        $DB->insert_record('local_taskflow_unit_members', (object)[
            'unitid' => $this->unitid,
            'userid' => (int)$this->employee->id,
            'active' => 1,
            'timeadded' => time(),
            'timemodified' => time(),
            'usermodified' => (int)$USER->id,
        ]);

        $preflight = $this->preflight([
            'userid' => (int)$this->employee->id,
            'unitid' => $this->unitid,
            'action' => manage_unit_membership_skill::ACTION_REMOVE,
        ]);
        $this->assertSame('soft_block', $preflight->status);

        $result = (new manage_unit_membership_skill())->execute(
            $preflight->preparedinput,
            context_system::instance()->id,
            (int)$USER->id
        );

        $this->assertContains(
            $result['status'],
            [taskflow_skill_base::STATUS_QUEUED, taskflow_skill_base::STATUS_EXECUTED],
            (string)$result['detail']
        );
        $this->assertSame([], $result['verification']['unverified']);
        $this->assertFalse($this->is_member((int)$this->employee->id));
        $this->assertFalse((bool)$result['ismember']);
    }

    /**
     * A membership change that would change nothing is refused, and so is an unknown unit.
     */
    public function test_no_change_and_unknown_unit_are_refused(): void {
        // Removing somebody who is not a member.
        $preflight = $this->preflight([
            'userid' => (int)$this->employee->id,
            'unitid' => $this->unitid,
            'action' => manage_unit_membership_skill::ACTION_REMOVE,
        ]);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(manage_unit_membership_skill::ISSUE_NO_CHANGE, $preflight->issuecodes);

        // Adding somebody who already is a member.
        global $DB, $USER;
        $DB->insert_record('local_taskflow_unit_members', (object)[
            'unitid' => $this->unitid,
            'userid' => (int)$this->employee->id,
            'active' => 1,
            'timeadded' => time(),
            'timemodified' => time(),
            'usermodified' => (int)$USER->id,
        ]);
        $preflight = $this->preflight([
            'userid' => (int)$this->employee->id,
            'unitid' => $this->unitid,
            'action' => manage_unit_membership_skill::ACTION_ADD,
        ]);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(manage_unit_membership_skill::ISSUE_NO_CHANGE, $preflight->issuecodes);

        // An unknown unit.
        $preflight = $this->preflight([
            'userid' => (int)$this->employee->id,
            'unitid' => $this->unitid + 999,
            'action' => manage_unit_membership_skill::ACTION_ADD,
        ]);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(manage_unit_membership_skill::ISSUE_UNIT_NOT_FOUND, $preflight->issuecodes);

        // An unknown action.
        $preflight = $this->preflight([
            'userid' => (int)$this->employee->id,
            'unitid' => $this->unitid,
            'action' => 'move',
        ]);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(manage_unit_membership_skill::ISSUE_ACTION_UNKNOWN, $preflight->issuecodes);
    }

    /**
     * The person can also be named by a query instead of an id.
     */
    public function test_user_is_resolved_from_a_query(): void {
        $preflight = $this->preflight([
            'userquery' => (string)$this->employee->email,
            'unitid' => $this->unitid,
            'action' => manage_unit_membership_skill::ACTION_ADD,
        ]);

        $this->assertSame('soft_block', $preflight->status);
        $this->assertSame((int)$this->employee->id, (int)$preflight->preparedinput['userid']);
    }
}
