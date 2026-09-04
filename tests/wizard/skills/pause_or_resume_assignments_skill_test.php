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
use local_taskflow\local\assignments\assignment;
use local_taskflow\local\units\organisational_units\unit;
use local_taskflow\local\wizard\engine\skill_risk_class;
use local_taskflow\local\wizard\taskflow\skills\pause_or_resume_assignments_skill;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use local_taskflow\wizard\local_wizard_dependency;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../local_wizard_dependency.php');

/**
 * Pause/resume round trip of local_taskflow.pause_or_resume_assignments.
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\skills\pause_or_resume_assignments_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class pause_or_resume_assignments_skill_test extends advanced_testcase {
    /** @var \local_taskflow_generator Plugin generator. */
    private $generator;

    /** @var int Assignee. */
    private int $employeeid = 0;

    /** @var int Organisational unit. */
    private int $unitid = 0;

    /** @var int Rule under test. */
    private int $ruleid = 0;

    /** @var int Assignment under test. */
    private int $assignmentid = 0;

    /** @var int System context id. */
    private int $contextid = 0;

    /**
     * Setup: one unit with one member, one rule and one assignment.
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        local_wizard_dependency::require_installed();
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_taskflow');
        $this->generator->set_config_values('standard', ['organisational_unit_option' => 'unit']);

        $courseid = (int)$this->getDataGenerator()->create_course(['fullname' => 'Data protection course'])->id;
        $employee = $this->getDataGenerator()->create_user(['firstname' => 'Anna', 'lastname' => 'Muster']);
        $this->employeeid = (int)$employee->id;

        $this->unitid = (int)unit::create_unit((object)['name' => 'Administration'])->get_id();
        $DB->insert_record('local_taskflow_unit_members', (object)[
            'unitid' => $this->unitid,
            'userid' => $this->employeeid,
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
        $this->generator->create_user_assignment($this->employeeid, $this->ruleid);
        $this->assignmentid = (int)$DB->get_field(
            'local_taskflow_assignment',
            'id',
            ['userid' => $this->employeeid, 'ruleid' => $this->ruleid],
            MUST_EXIST
        );
        $DB->set_field('local_taskflow_assignment', 'unitid', $this->unitid, ['id' => $this->assignmentid]);
        $DB->set_field(
            'local_taskflow_assignment',
            'status',
            assignment_status_facade::get_status_identifier('assigned'),
            ['id' => $this->assignmentid]
        );
        assignment::destroy_instance();

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
     * Run the skill as the given (default: current) user.
     *
     * @param string $action
     * @param int|null $userid
     * @return array
     */
    private function execute(string $action, ?int $userid = null): array {
        global $USER;

        assignment::destroy_instance();
        return (new pause_or_resume_assignments_skill())->execute(
            ['userid' => $this->employeeid, 'action' => $action],
            $this->contextid,
            $userid ?? (int)$USER->id
        );
    }

    /**
     * Number of assignments of the employee in the paused status.
     *
     * @return int
     */
    private function paused_count(): int {
        global $DB;

        return (int)$DB->count_records('local_taskflow_assignment', [
            'userid' => $this->employeeid,
            'status' => assignment_status_facade::get_status_identifier('paused'),
        ]);
    }

    /**
     * Contract: mutating R2, schema with the two directions.
     */
    public function test_contract(): void {
        $skill = new pause_or_resume_assignments_skill();
        $this->assertSame('local_taskflow.pause_or_resume_assignments', $skill->get_name());
        $this->assertFalse($skill->is_read_only());
        $this->assertSame(skill_risk_class::R2, $skill->get_risk_class());
        $this->assertSame([], $skill->get_required_native_capabilities());

        $schema = $skill->get_schema();
        $this->assertTrue($schema['properties']['action']['required']);
        $this->assertSame(['system'], $schema['prompt_meta']['context_scopes']);
        $this->assertFalse($skill->check_structure(['userid' => 5, 'action' => 'stop'])['valid']);
        $this->assertTrue($skill->check_structure(['userid' => 5, 'action' => 'pause'])['valid']);
    }

    /**
     * The proposal names person, direction and the number of affected assignments.
     */
    public function test_confirmable_proposal(): void {
        global $USER;

        $skill = new pause_or_resume_assignments_skill();
        $preflight = $skill->preflight(
            ['userid' => $this->employeeid, 'action' => pause_or_resume_assignments_skill::ACTION_PAUSE],
            $this->contextid,
            (int)$USER->id
        );
        $this->assertSame('soft_block', $preflight->status);
        $this->assertContains(pause_or_resume_assignments_skill::ISSUE_CONFIRM_REQUIRED, $preflight->issuecodes);

        $proposal = $skill->describe_proposed_action($preflight->preparedinput);
        $this->assertStringContainsString('Anna Muster', $proposal['title']);
        $rows = array_column($proposal['rows'], 'value', 'label');
        $this->assertSame('1', $rows[get_string('agent_pause_resume_row_affected', 'local_taskflow')]);
        $this->assertSame(
            get_string('agent_pause_resume_action_pause', 'local_taskflow'),
            $rows[get_string('agent_pause_resume_row_action', 'local_taskflow')]
        );
    }

    /**
     * Pausing and resuming again returns the counts to the starting point.
     */
    public function test_pause_and_resume_round_trip(): void {
        $this->assertSame(0, $this->paused_count());

        $pause = $this->execute(pause_or_resume_assignments_skill::ACTION_PAUSE);
        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $pause['status']);
        $this->assertSame(1, (int)$pause['counts_after']['paused']);
        $this->assertSame(0, (int)$pause['counts_after']['active']);
        $this->assertSame(1, (int)$pause['changed_total']);
        $this->assertSame(1, $this->paused_count());

        $resume = $this->execute(pause_or_resume_assignments_skill::ACTION_RESUME);
        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $resume['status']);
        $this->assertSame(0, (int)$resume['counts_after']['paused']);
        $this->assertSame(1, (int)$resume['counts_after']['active']);
        $this->assertSame(1, (int)$resume['changed_total']);
        $this->assertSame(0, $this->paused_count());
    }

    /**
     * Resuming a person without paused assignments is refused before anything is written.
     */
    public function test_nothing_to_do(): void {
        global $USER;

        $preflight = (new pause_or_resume_assignments_skill())->preflight(
            ['userid' => $this->employeeid, 'action' => pause_or_resume_assignments_skill::ACTION_RESUME],
            $this->contextid,
            (int)$USER->id
        );
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(pause_or_resume_assignments_skill::ISSUE_NOTHING_TO_DO, $preflight->issuecodes);
        $this->assertSame(0, $this->paused_count());
    }

    /**
     * Without the edit capability and without being supervisor the preflight hard-blocks.
     */
    public function test_missing_capability_hard_blocks(): void {
        $stranger = $this->getDataGenerator()->create_user();
        $this->setUser($stranger);

        $skill = new pause_or_resume_assignments_skill();
        $preflight = $skill->preflight(
            ['userid' => $this->employeeid, 'action' => pause_or_resume_assignments_skill::ACTION_PAUSE],
            $this->contextid,
            (int)$stranger->id
        );
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(taskflow_skill_base::ISSUE_SCOPE_DENIED, $preflight->issuecodes);

        $result = $this->execute(pause_or_resume_assignments_skill::ACTION_PAUSE, (int)$stranger->id);
        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $result['status']);
        $this->assertSame([taskflow_skill_base::ISSUE_SCOPE_DENIED], $result['issue_codes']);
        $this->assertSame(0, $this->paused_count());
    }

    /**
     * The assignee alone may not pause their own assignments (long leave is not self service).
     */
    public function test_self_scope_is_not_sufficient(): void {
        $this->setUser($this->employeeid);

        $preflight = (new pause_or_resume_assignments_skill())->preflight(
            ['userid' => $this->employeeid, 'action' => pause_or_resume_assignments_skill::ACTION_PAUSE],
            $this->contextid,
            $this->employeeid
        );
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(taskflow_skill_base::ISSUE_SCOPE_DENIED, $preflight->issuecodes);
    }
}
