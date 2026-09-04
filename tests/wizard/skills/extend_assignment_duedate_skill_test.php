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
use local_taskflow\local\wizard\taskflow\skills\extend_assignment_duedate_skill;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use local_taskflow\wizard\local_wizard_dependency;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../local_wizard_dependency.php');

/**
 * Due date changes, extension decisions and the adapter extension limit of
 * local_taskflow.extend_assignment_duedate.
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\skills\extend_assignment_duedate_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class extend_assignment_duedate_skill_test extends advanced_testcase {
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

    /** @var int */
    private int $duedate = 0;

    /**
     * Setup: standard adapter, one assignment due in one hour.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
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
        $this->duedate = (int)$DB->get_field('local_taskflow_assignment', 'duedate', ['id' => $this->assignmentid]);
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
     * Switch the site to the tuines adapter (usingprolongedstate on).
     */
    private function use_tuines_adapter(): void {
        $this->generator->set_config_values('tuines');
        \local_taskflow\local\external_adapter\external_api_base::destroy_instance();
        assignment::destroy_instance();
    }

    /**
     * Preflight the skill as the given user.
     *
     * @param array $input
     * @param int $userid
     * @return object
     */
    private function preflight(array $input, int $userid) {
        return (new extend_assignment_duedate_skill())->preflight($input, context_system::instance()->id, $userid);
    }

    /**
     * Execute the skill as the given user.
     *
     * @param array $input
     * @param int $userid
     * @return array
     */
    private function execute(array $input, int $userid): array {
        return (new extend_assignment_duedate_skill())->execute($input, context_system::instance()->id, $userid);
    }

    /**
     * extenddays moves the due date; the proposal shows old, new and the difference.
     */
    public function test_extend_by_days_moves_duedate(): void {
        global $DB;

        $expected = $this->duedate + 14 * DAYSECS;
        $preflight = $this->preflight([
            'assignmentid' => $this->assignmentid,
            'extenddays' => 14,
            'comment' => 'More time needed',
        ], (int)get_admin()->id);
        $this->assertSame('soft_block', $preflight->status);
        $this->assertContains(extend_assignment_duedate_skill::ISSUE_CONFIRM_REQUIRED, $preflight->issuecodes);
        $this->assertSame($expected, $preflight->preparedinput['newduedate']);

        $descriptor = (new extend_assignment_duedate_skill())->describe_proposed_action($preflight->preparedinput);
        $this->assertStringContainsString('Anna Muster', $descriptor['title']);
        $this->assertNotSame('', $descriptor['summary']);
        $labels = array_column($descriptor['rows'], 'label');
        $values = array_column($descriptor['rows'], 'value');
        $this->assertContains(get_string('duedate', 'local_taskflow'), $labels);
        $this->assertContains(get_string('agent_extend_duedate_row_days', 'local_taskflow'), $labels);
        $this->assertContains(get_string('agent_extend_duedate_row_status', 'local_taskflow'), $labels);
        $this->assertContains(get_string('agent_extend_duedate_row_counter', 'local_taskflow'), $labels);
        $this->assertContains('14', $values);
        $this->assertStringContainsString('→', implode(' ', $values));

        $result = $this->execute($preflight->preparedinput, (int)get_admin()->id);
        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status']);
        $this->assertSame($expected, $result['duedate_after']);
        $this->assertSame(
            $expected,
            (int)$DB->get_field('local_taskflow_assignment', 'duedate', ['id' => $this->assignmentid])
        );
        $this->assertSame(taskflow_preview_renderer_factory::TYPE_ASSIGNMENT, $result['preview']['type']);
        $this->assertStringContainsString('→', (string)$result['preview']['data']['assignment']['change']);
        $this->assertTrue($DB->record_exists('local_taskflow_history', [
            'assignmentid' => $this->assignmentid,
            'type' => history::TYPE_MANUAL_CHANGE,
        ]));
    }

    /**
     * A granted extension sets the prolonged status and raises the extension counter.
     */
    public function test_grant_sets_prolonged_status(): void {
        global $DB;

        $this->use_tuines_adapter();
        $prolonged = assignment_status_facade::get_status_identifier('prolonged');
        $this->assertFalse(assignment_status_facade::check_excluded((string)$prolonged));
        $newduedate = $this->duedate + 7 * DAYSECS;

        $preflight = $this->preflight([
            'assignmentid' => $this->assignmentid,
            'newduedate' => date('Y-m-d H:i', $newduedate),
            'decision' => extend_assignment_duedate_skill::DECISION_GRANT,
            'comment' => 'Granted',
        ], (int)get_admin()->id);
        $this->assertSame('soft_block', $preflight->status);

        $result = $this->execute($preflight->preparedinput, (int)get_admin()->id);
        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status']);
        $record = $DB->get_record('local_taskflow_assignment', ['id' => $this->assignmentid], '*', MUST_EXIST);
        $this->assertSame($prolonged, (int)$record->status);
        $this->assertSame(1, (int)$record->prolongedcounter);
        $this->assertGreaterThan($this->duedate, (int)$record->duedate);
    }

    /**
     * A denied extension leaves the due date untouched but raises the counter.
     */
    public function test_deny_keeps_duedate_and_raises_counter(): void {
        global $DB;

        $this->use_tuines_adapter();
        $preflight = $this->preflight([
            'assignmentid' => $this->assignmentid,
            'decision' => extend_assignment_duedate_skill::DECISION_DENY,
            'comment' => 'Not justified',
        ], (int)get_admin()->id);
        $this->assertSame('soft_block', $preflight->status);

        $result = $this->execute($preflight->preparedinput, (int)get_admin()->id);
        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status']);
        $record = $DB->get_record('local_taskflow_assignment', ['id' => $this->assignmentid], '*', MUST_EXIST);
        $this->assertSame($this->duedate, (int)$record->duedate);
        $this->assertSame(1, (int)$record->prolongedcounter);
        $this->assertSame(1, $result['prolongedcounter']);
    }

    /**
     * The extension limit blocks further extensions, but only while the adapter uses the prolonged state.
     */
    public function test_extension_limit_reached(): void {
        global $DB;

        $DB->set_field('local_taskflow_assignment', 'prolongedcounter', 2, ['id' => $this->assignmentid]);
        assignment::destroy_instance();

        // Standard adapter: usingprolongedstate is off, so no limit applies.
        $preflight = $this->preflight([
            'assignmentid' => $this->assignmentid,
            'extenddays' => 7,
        ], (int)get_admin()->id);
        $this->assertSame('soft_block', $preflight->status);

        $this->use_tuines_adapter();
        $preflight = $this->preflight([
            'assignmentid' => $this->assignmentid,
            'extenddays' => 7,
        ], (int)get_admin()->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(extend_assignment_duedate_skill::ISSUE_LIMIT_REACHED, $preflight->issuecodes);
        $this->assertSame(2, (int)$preflight->issues[0]['prolongedcounter']);
        $this->assertStringContainsString('2', (string)$preflight->issues[0]['message']);

        $result = $this->execute([
            'assignmentid' => $this->assignmentid,
            'newduedate' => $this->duedate + 7 * DAYSECS,
        ], (int)get_admin()->id);
        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $result['status']);
        $this->assertSame(
            $this->duedate,
            (int)$DB->get_field('local_taskflow_assignment', 'duedate', ['id' => $this->assignmentid])
        );
    }

    /**
     * A date in the past and contradictory date input are refused.
     */
    public function test_invalid_date_input_is_refused(): void {
        $preflight = $this->preflight([
            'assignmentid' => $this->assignmentid,
            'newduedate' => (string)(time() - DAYSECS),
        ], (int)get_admin()->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(extend_assignment_duedate_skill::ISSUE_DATE_NOT_FUTURE, $preflight->issuecodes);

        $preflight = $this->preflight([
            'assignmentid' => $this->assignmentid,
            'newduedate' => (string)($this->duedate + DAYSECS),
            'extenddays' => 7,
        ], (int)get_admin()->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains('VALIDATION_ERROR', $preflight->issuecodes);

        $preflight = $this->preflight(['assignmentid' => $this->assignmentid], (int)get_admin()->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains('VALIDATION_ERROR', $preflight->issuecodes);

        $preflight = $this->preflight([
            'assignmentid' => $this->assignmentid,
            'extenddays' => 7,
            'decision' => 'maybe',
        ], (int)get_admin()->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains('VALIDATION_ERROR', $preflight->issuecodes);
    }

    /**
     * A user without edit scope is blocked in preflight and in execute.
     */
    public function test_foreign_user_is_denied(): void {
        global $DB;

        $input = ['assignmentid' => $this->assignmentid, 'extenddays' => 7];
        $preflight = $this->preflight($input, (int)$this->stranger->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(taskflow_skill_base::ISSUE_SCOPE_DENIED, $preflight->issuecodes);

        $result = $this->execute($input, (int)$this->stranger->id);
        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $result['status']);
        $this->assertContains(taskflow_skill_base::ISSUE_SCOPE_DENIED, $result['issue_codes']);
        $this->assertSame(
            $this->duedate,
            (int)$DB->get_field('local_taskflow_assignment', 'duedate', ['id' => $this->assignmentid])
        );
    }

    /**
     * The skill is a mutating R1 skill implementing the queue identity contract.
     */
    public function test_skill_metadata(): void {
        $skill = new extend_assignment_duedate_skill();
        $this->assertFalse($skill->is_read_only());
        $this->assertSame('local_taskflow.extend_assignment_duedate', $skill->get_name());
        $identity = $skill->build_queue_business_identity([
            'assignmentid' => $this->assignmentid,
            'extenddays' => 14,
            'decision' => 'grant',
        ]);
        $this->assertSame('local_taskflow.extend_assignment_duedate', $identity['task_family']);
        $this->assertSame($this->assignmentid, $identity['target']['assignmentid']);
    }
}
