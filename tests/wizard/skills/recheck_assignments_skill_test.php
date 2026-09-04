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
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\skills\recheck_assignments_skill;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use local_taskflow\wizard\local_wizard_dependency;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../local_wizard_dependency.php');

/**
 * Idempotent re-check and change reporting of local_taskflow.recheck_assignments.
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\skills\recheck_assignments_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class recheck_assignments_skill_test extends advanced_testcase {
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
     * Setup: one unit with one member, one rule with a course target and one assignment.
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
     * Run the skill as the current admin user.
     *
     * @param array $input
     * @param int|null $userid
     * @return array
     */
    private function execute(array $input, ?int $userid = null): array {
        global $USER;

        assignment::destroy_instance();
        return (new recheck_assignments_skill())->execute($input, $this->contextid, $userid ?? (int)$USER->id);
    }

    /**
     * Contract: mutating R1, no hard native capability, exclusive selectors.
     */
    public function test_contract(): void {
        $skill = new recheck_assignments_skill();
        $this->assertSame('local_taskflow.recheck_assignments', $skill->get_name());
        $this->assertFalse($skill->is_read_only());
        $this->assertSame(skill_risk_class::R1, $skill->get_risk_class());
        $this->assertSame([], $skill->get_required_native_capabilities());

        $schema = $skill->get_schema();
        $this->assertArrayHasKey('userquery', $schema['properties']);
        $this->assertArrayHasKey('assignmentid', $schema['properties']);
        $this->assertSame(['system'], $schema['prompt_meta']['context_scopes']);

        // Exactly one selector: neither none nor both.
        $this->assertFalse($skill->check_structure([])['valid']);
        $this->assertFalse($skill->check_structure(['userid' => 5, 'assignmentid' => 7])['valid']);
        $this->assertTrue($skill->check_structure(['assignmentid' => 7])['valid']);
    }

    /**
     * The proposal names the person and the assignments in scope.
     */
    public function test_confirmable_proposal(): void {
        global $USER;

        $skill = new recheck_assignments_skill();
        $preflight = $skill->preflight(['userid' => $this->employeeid], $this->contextid, (int)$USER->id);
        $this->assertSame('soft_block', $preflight->status);
        $this->assertContains(recheck_assignments_skill::ISSUE_CONFIRM_REQUIRED, $preflight->issuecodes);

        $proposal = $skill->describe_proposed_action($preflight->preparedinput);
        $this->assertStringContainsString('Anna Muster', $proposal['title']);
        $rows = array_column($proposal['rows'], 'value', 'label');
        $this->assertSame('1', $rows[get_string('agent_preview_assignments', 'local_taskflow')]);
        $this->assertSame('Administration', $rows[get_string('agent_preview_units', 'local_taskflow')]);
    }

    /**
     * A second run right after the first changes nothing and says so.
     */
    public function test_idempotent_run_reports_no_change(): void {
        // First run brings the assignment in line with the rule.
        $this->execute(['userid' => $this->employeeid]);

        $result = $this->execute(['userid' => $this->employeeid]);

        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status']);
        $this->assertSame([], $result['changed']);
        $this->assertSame(0, (int)$result['changed_total']);
        $this->assertSame(
            get_string('agent_recheck_nochange', 'local_taskflow', (object)[
                'fullname' => 'Anna Muster',
                'assignments' => (int)$result['assignments_total'],
            ]),
            $result['usermessage']
        );
        $this->assertSame(
            taskflow_preview_renderer_factory::TYPE_ASSIGNMENT_LIST,
            $result['preview']['type']
        );
    }

    /**
     * A due date in the past is picked up: the status change is reported as old -> new.
     */
    public function test_reports_status_change_after_duedate_passed(): void {
        global $DB;

        $this->execute(['userid' => $this->employeeid]);
        $statusbefore = (int)$DB->get_field('local_taskflow_assignment', 'status', ['id' => $this->assignmentid]);

        $DB->set_field('local_taskflow_assignment', 'duedate', time() - DAYSECS, ['id' => $this->assignmentid]);
        assignment::destroy_instance();

        $result = $this->execute(['assignmentid' => $this->assignmentid]);

        $overdue = (int)assignment_status_facade::get_status_identifier('overdue');
        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status']);
        $this->assertCount(1, $result['changed']);
        $change = $result['changed'][0];
        $this->assertSame($this->assignmentid, (int)$change['id']);
        $this->assertSame('status', $change['changes'][0]['field']);
        $this->assertSame($statusbefore, (int)$change['changes'][0]['from']);
        $this->assertSame($overdue, (int)$change['changes'][0]['to']);
        $this->assertSame($overdue, (int)$DB->get_field(
            'local_taskflow_assignment',
            'status',
            ['id' => $this->assignmentid]
        ));
        $this->assertStringContainsString('→', $change['change']);
    }

    /**
     * Without the edit capability and without being supervisor the preflight hard-blocks.
     */
    public function test_missing_capability_hard_blocks(): void {
        $stranger = $this->getDataGenerator()->create_user();
        $this->setUser($stranger);

        $skill = new recheck_assignments_skill();
        $preflight = $skill->preflight(['userid' => $this->employeeid], $this->contextid, (int)$stranger->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(taskflow_skill_base::ISSUE_SCOPE_DENIED, $preflight->issuecodes);

        $result = (new recheck_assignments_skill())->execute(
            ['userid' => $this->employeeid],
            $this->contextid,
            (int)$stranger->id
        );
        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $result['status']);
        $this->assertSame([taskflow_skill_base::ISSUE_SCOPE_DENIED], $result['issue_codes']);
    }
}
