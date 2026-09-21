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
use local_taskflow\local\history\history;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\skills\change_assignment_status_skill;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use local_taskflow\wizard\local_wizard_dependency;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../local_wizard_dependency.php');

/**
 * Confirmation, execution and verification of local_taskflow.change_assignment_status.
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\skills\change_assignment_status_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class change_assignment_status_skill_test extends advanced_testcase {
    /** @var \local_taskflow_generator */
    private $generator;

    /** @var \stdClass */
    private \stdClass $supervisor;

    /** @var \stdClass */
    private \stdClass $employee;

    /** @var \stdClass */
    private \stdClass $stranger;

    /** @var int */
    private int $ruleid = 0;

    /** @var int */
    private int $assignmentid = 0;

    /**
     * Setup: standard adapter, one assigned assignment of Anna Muster.
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
        $this->generator->set_config_values('standard');
        $fields = $this->generator->create_custom_profile_fields(['supervisor', 'deputy']);
        \local_taskflow\local\external_adapter\external_api_base::destroy_instance();

        $this->supervisor = $this->getDataGenerator()->create_user(['firstname' => 'Emily', 'lastname' => 'Smith']);
        $this->employee = $this->getDataGenerator()->create_user(['firstname' => 'Anna', 'lastname' => 'Muster']);
        $this->stranger = $this->getDataGenerator()->create_user();
        $DB->insert_record('user_info_data', (object)[
            'userid' => $this->employee->id,
            'fieldid' => $fields['supervisor'],
            'data' => (string)$this->supervisor->id,
            'dataformat' => 0,
        ]);

        $this->ruleid = (int)$this->generator->create_rule(['name' => 'Data protection']);
        $this->generator->create_user_assignment((int)$this->employee->id, $this->ruleid);
        $this->assignmentid = (int)$DB->get_field(
            'local_taskflow_assignment',
            'id',
            ['userid' => $this->employee->id, 'ruleid' => $this->ruleid],
            MUST_EXIST
        );
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
     * Preflight the skill as the given user.
     *
     * @param array $input
     * @param int $userid
     * @return object
     */
    private function preflight(array $input, int $userid) {
        return (new change_assignment_status_skill())->preflight($input, context_system::instance()->id, $userid);
    }

    /**
     * Execute the skill as the given user.
     *
     * @param array $input
     * @param int $userid
     * @return array
     */
    private function execute(array $input, int $userid): array {
        return (new change_assignment_status_skill())->execute($input, context_system::instance()->id, $userid);
    }

    /**
     * Preflight proposes a confirmable action with the expected rows; execution changes the DB.
     */
    public function test_confirmable_proposal_and_verified_execution(): void {
        global $DB;

        $paused = assignment_status_facade::get_status_identifier('paused');
        $before = (int)$DB->count_records('local_taskflow_history', [
            'assignmentid' => $this->assignmentid,
            'type' => history::TYPE_MANUAL_CHANGE,
        ]);

        $input = [
            'assignmentid' => $this->assignmentid,
            'status' => 'paused',
            'reason' => 'sickness',
            'comment' => 'Sick leave',
        ];
        $preflight = $this->preflight($input, (int)get_admin()->id);
        $this->assertSame('soft_block', $preflight->status);
        $this->assertContains(change_assignment_status_skill::ISSUE_CONFIRM_REQUIRED, $preflight->issuecodes);
        $this->assertSame($paused, $preflight->preparedinput['status']);

        $descriptor = (new change_assignment_status_skill())->describe_proposed_action($preflight->preparedinput);
        $this->assertStringContainsString('#' . $this->assignmentid, $descriptor['title']);
        $this->assertStringContainsString('Anna Muster', $descriptor['title']);
        $this->assertNotSame('', $descriptor['summary']);
        $labels = array_column($descriptor['rows'], 'label');
        $values = array_column($descriptor['rows'], 'value');
        $this->assertContains(get_string('fullname', 'local_taskflow'), $labels);
        $this->assertContains(get_string('status', 'local_taskflow'), $labels);
        $this->assertContains(get_string('changereason', 'local_taskflow'), $labels);
        $this->assertContains('Anna Muster', $values);
        $this->assertContains(
            assignment_status_facade::get_specific_names(assignment_status_facade::get_status_identifier('assigned'))
                . ' → ' . assignment_status_facade::get_specific_names($paused),
            $values
        );
        $this->assertContains('Sick leave', $values);

        $result = $this->execute($preflight->preparedinput, (int)get_admin()->id);
        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status']);
        $this->assertSame($this->assignmentid, $result['resultid']);
        $this->assertSame($paused, $result['status_after']);
        $this->assertSame(
            $paused,
            (int)$DB->get_field('local_taskflow_assignment', 'status', ['id' => $this->assignmentid])
        );

        $after = (int)$DB->count_records('local_taskflow_history', [
            'assignmentid' => $this->assignmentid,
            'type' => history::TYPE_MANUAL_CHANGE,
        ]);
        $this->assertGreaterThan($before, $after);
        $this->assertGreaterThan(0, $result['history_entry_id']);

        $this->assertSame(taskflow_preview_renderer_factory::TYPE_ASSIGNMENT, $result['preview']['type']);
        $this->assertSame(
            $paused,
            (int)$result['preview']['data']['assignment']['status']
        );
        $this->assertStringContainsString('→', (string)$result['preview']['data']['assignment']['change']);
        $this->assertSame([$this->assignmentid], $result['preview']['payload']['assignmentids']);
        $this->assertStringContainsString('status_after=' . $paused, $result['observation_full']);
    }

    /**
     * A status the adapter excludes is refused in preflight and never silently succeeds.
     */
    public function test_status_excluded_by_adapter_is_refused(): void {
        global $DB;

        $this->generator->set_config_values('tuines');
        \local_taskflow\local\external_adapter\external_api_base::destroy_instance();
        $enrolled = assignment_status_facade::get_status_identifier('enrolled');
        $this->assertTrue(assignment_status_facade::check_excluded((string)$enrolled));
        $statusbefore = (int)$DB->get_field('local_taskflow_assignment', 'status', ['id' => $this->assignmentid]);

        $input = [
            'assignmentid' => $this->assignmentid,
            'status' => 'enrolled',
            'reason' => 'other',
        ];
        $preflight = $this->preflight($input, (int)get_admin()->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(change_assignment_status_skill::ISSUE_STATUS_EXCLUDED, $preflight->issuecodes);

        $result = $this->execute(array_merge($input, ['status' => (string)$enrolled]), (int)get_admin()->id);
        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $result['status']);
        $this->assertSame(
            $statusbefore,
            (int)$DB->get_field('local_taskflow_assignment', 'status', ['id' => $this->assignmentid])
        );
    }

    /**
     * An unknown status is refused and the valid values are listed.
     */
    public function test_unknown_status_lists_valid_values(): void {
        $preflight = $this->preflight([
            'assignmentid' => $this->assignmentid,
            'status' => 'nonexistent-status',
            'reason' => 'other',
        ], (int)get_admin()->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(change_assignment_status_skill::ISSUE_STATUS_UNKNOWN, $preflight->issuecodes);
        $message = (string)($preflight->issues[0]['message'] ?? '');
        $this->assertStringContainsString(
            assignment_status_facade::get_specific_names(assignment_status_facade::get_status_identifier('paused')),
            $message
        );

        $preflight = $this->preflight([
            'assignmentid' => $this->assignmentid,
            'status' => 'paused',
            'reason' => 'no-such-reason',
        ], (int)get_admin()->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(change_assignment_status_skill::ISSUE_REASON_UNKNOWN, $preflight->issuecodes);
    }

    /**
     * A user without edit scope is blocked in preflight and in execute.
     */
    public function test_foreign_user_is_denied(): void {
        global $DB;

        $input = ['assignmentid' => $this->assignmentid, 'status' => 'paused', 'reason' => 'other'];
        $preflight = $this->preflight($input, (int)$this->stranger->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(taskflow_skill_base::ISSUE_SCOPE_DENIED, $preflight->issuecodes);

        $statusbefore = (int)$DB->get_field('local_taskflow_assignment', 'status', ['id' => $this->assignmentid]);
        $result = $this->execute($input, (int)$this->stranger->id);
        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $result['status']);
        $this->assertContains(taskflow_skill_base::ISSUE_SCOPE_DENIED, $result['issue_codes']);
        $this->assertSame(
            $statusbefore,
            (int)$DB->get_field('local_taskflow_assignment', 'status', ['id' => $this->assignmentid])
        );

        // The assignee alone may not change the status of the own assignment.
        $preflight = $this->preflight($input, (int)$this->employee->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(taskflow_skill_base::ISSUE_SCOPE_DENIED, $preflight->issuecodes);
    }

    /**
     * The skill is a mutating R1 skill implementing the queue identity contract.
     */
    public function test_skill_metadata(): void {
        $skill = new change_assignment_status_skill();
        $this->assertFalse($skill->is_read_only());
        $this->assertSame('local_taskflow.change_assignment_status', $skill->get_name());
        $identity = $skill->build_queue_business_identity([
            'assignmentid' => $this->assignmentid,
            'status' => 'paused',
            'reason' => 'sickness',
        ]);
        $this->assertSame('local_taskflow.change_assignment_status', $identity['task_family']);
        $this->assertSame($this->assignmentid, $identity['target']['assignmentid']);
    }
}
