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
use local_taskflow\local\external_adapter\external_api_base;
use local_taskflow\local\history\history;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\skills\diagnose_message_delivery_skill;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use local_taskflow\wizard\local_wizard_dependency;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../local_wizard_dependency.php');

/**
 * Skill local_taskflow.diagnose_message_delivery.
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\skills\diagnose_message_delivery_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class diagnose_message_delivery_skill_test extends advanced_testcase {
    /** @var \local_taskflow_generator */
    private $generator;

    /** @var \stdClass Supervisor of the employee. */
    private \stdClass $supervisor;

    /** @var \stdClass Assignee with a supervisor. */
    private \stdClass $employee;

    /** @var \stdClass Assignee without a supervisor. */
    private \stdClass $orphan;

    /** @var int Supervisor profile field id. */
    private int $supervisorfield = 0;

    /** @var int Message template addressing assignee and supervisor. */
    private int $messageid = 0;

    /** @var int Rule using the template. */
    private int $ruleid = 0;

    /** @var int Assignment of the employee. */
    private int $assignmentid = 0;

    /** @var int Assignment of the user without a supervisor. */
    private int $orphanassignmentid = 0;

    /** @var int System context id. */
    private int $contextid = 0;

    /**
     * Setup: standard adapter, a template addressing assignee + supervisor, two assignments.
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
        $fields = $this->generator->create_custom_profile_fields(['supervisor']);
        $this->supervisorfield = (int)$fields['supervisor'];
        external_api_base::destroy_instance();

        $this->supervisor = $this->getDataGenerator()->create_user(['firstname' => 'Emily', 'lastname' => 'Smith']);
        $this->employee = $this->getDataGenerator()->create_user(['firstname' => 'Anna', 'lastname' => 'Muster']);
        $this->orphan = $this->getDataGenerator()->create_user(['firstname' => 'Bert', 'lastname' => 'Beispiel']);
        $DB->insert_record('user_info_data', (object)[
            'userid' => $this->employee->id,
            'fieldid' => $this->supervisorfield,
            'data' => (string)$this->supervisor->id,
            'dataformat' => 0,
        ]);

        $this->messageid = (int)$DB->insert_record('local_taskflow_messages', (object)[
            'name' => 'Reminder 7 days',
            'class' => 'standard',
            'message' => json_encode(['heading' => 'Reminder', 'body' => '<p>Due soon</p>']),
            'priority' => 2,
            'sending_settings' => json_encode([
                'recipientrole' => ['assignee', 'supervisor'],
                'carboncopyrole' => [],
                'senddirection' => 'before',
                'senddays' => '7',
                'timeunit' => 'days',
                'sendstart' => 'end',
                'sendingcondition' => 'always',
            ]),
            'usermodified' => 2,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $this->ruleid = (int)$this->generator->create_rule([
            'name' => 'Data protection basics',
            'messages' => [$this->messageid],
        ]);
        $this->assignmentid = $this->create_assignment((int)$this->employee->id);
        $this->orphanassignmentid = $this->create_assignment((int)$this->orphan->id);

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
     * Create an assignment of the rule for a user and return its id.
     *
     * @param int $userid
     * @return int
     */
    private function create_assignment(int $userid): int {
        global $DB;

        $this->generator->create_user_assignment($userid, $this->ruleid);
        return (int)$DB->get_field(
            'local_taskflow_assignment',
            'id',
            ['userid' => $userid, 'ruleid' => $this->ruleid],
            MUST_EXIST
        );
    }

    /**
     * Execute the skill as the current user after a passing preflight.
     *
     * @param array $input
     * @return array
     */
    private function run_skill(array $input): array {
        global $USER;
        $skill = new diagnose_message_delivery_skill();
        $preflight = $skill->preflight($input, $this->contextid, (int)$USER->id);
        $this->assertSame('pass', $preflight->status);
        return $skill->execute($preflight->preparedinput, $this->contextid, (int)$USER->id);
    }

    /**
     * Contract: name, read-only R0, no hard native capability, messageid required.
     */
    public function test_contract(): void {
        $skill = new diagnose_message_delivery_skill();
        $this->assertSame('local_taskflow.diagnose_message_delivery', $skill->get_name());
        $this->assertTrue($skill->is_read_only());
        $this->assertSame([], $skill->get_required_native_capabilities());

        $schema = $skill->get_schema();
        $this->assertTrue($schema['properties']['messageid']['required']);
        $this->assertSame(['system'], $schema['prompt_meta']['context_scopes']);
    }

    /**
     * Everything resolves: template attached, recipients present, verdict deliverable.
     */
    public function test_deliverable(): void {
        $result = $this->run_skill([
            'messageid' => $this->messageid,
            'assignmentid' => $this->assignmentid,
        ]);

        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status']);
        $this->assertSame(diagnose_message_delivery_skill::VERDICT_DELIVERABLE, $result['verdict']);
        $this->assertSame([], $result['blockers']);
        $this->assertTrue($result['attached_to_rule']);
        $this->assertSame('always', $result['sending_condition']['identifier']);

        $ids = array_map(static fn(array $row): int => (int)$row['userid'], $result['resolved_recipients']);
        $this->assertContains((int)$this->employee->id, $ids);
        $this->assertContains((int)$this->supervisor->id, $ids);
        $this->assertSame([], $result['sent_log']);
        $this->assertSame([], $result['pending_tasks']);
        $this->assertArrayHasKey('sendmailstodeputy', $result['settings_in_effect']);
    }

    /**
     * A row in the send log shows up in sent_log and turns the verdict into already_sent.
     */
    public function test_sent_log(): void {
        global $DB;

        $DB->insert_record('local_taskflow_sent_messages', (object)[
            'messageid' => $this->messageid,
            'ruleid' => $this->ruleid,
            'userid' => $this->employee->id,
            'timesent' => time() - HOURSECS,
        ]);
        history::log(
            $this->assignmentid,
            (int)$this->employee->id,
            history::TYPE_MAIL_SEND,
            ['action' => 'mail_send', 'data' => 'Reminder 7 days']
        );

        $result = $this->run_skill([
            'messageid' => $this->messageid,
            'assignmentid' => $this->assignmentid,
        ]);

        $this->assertCount(1, $result['sent_log']);
        $this->assertSame($this->messageid, $result['sent_log'][0]['messageid']);
        $this->assertSame((int)$this->employee->id, $result['sent_log'][0]['userid']);
        $this->assertCount(1, $result['history']);
        $this->assertSame(diagnose_message_delivery_skill::VERDICT_ALREADY_SENT, $result['verdict']);
    }

    /**
     * A missing supervisor is reported as a blocker and turns the verdict into blocked.
     */
    public function test_missing_supervisor_is_a_blocker(): void {
        $result = $this->run_skill([
            'messageid' => $this->messageid,
            'assignmentid' => $this->orphanassignmentid,
        ]);

        $this->assertContains('supervisor_missing', $result['blockers']);
        $this->assertSame(diagnose_message_delivery_skill::VERDICT_BLOCKED, $result['verdict']);

        $found = false;
        foreach ($result['checks'] as $row) {
            if ($row['check'] === get_string('agent_diagnose_message_supervisor_missing', 'local_taskflow')) {
                $found = true;
                $this->assertSame('fail', $row['status']);
            }
        }
        $this->assertTrue($found, 'The checklist must carry the missing-supervisor row.');
    }

    /**
     * A template that no rule references is reported as not attached.
     */
    public function test_not_attached_to_rule(): void {
        global $DB;

        $orphantemplate = (int)$DB->insert_record('local_taskflow_messages', (object)[
            'name' => 'Unused template',
            'class' => 'standard',
            'message' => json_encode(['heading' => 'Unused', 'body' => '<p>Unused</p>']),
            'priority' => 1,
            'sending_settings' => json_encode(['recipientrole' => ['assignee'], 'carboncopyrole' => []]),
            'usermodified' => 2,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $result = $this->run_skill([
            'messageid' => $orphantemplate,
            'assignmentid' => $this->assignmentid,
        ]);

        $this->assertFalse($result['attached_to_rule']);
        $this->assertContains('not_attached_to_rule', $result['blockers']);
        $this->assertSame(diagnose_message_delivery_skill::VERDICT_BLOCKED, $result['verdict']);
    }

    /**
     * A queued send task for this template is listed as pending.
     */
    public function test_pending_task(): void {
        global $DB;

        $DB->insert_record('task_adhoc', (object)[
            'component' => 'local_taskflow',
            'classname' => '\local_taskflow\task\send_taskflow_message',
            'nextruntime' => time() + DAYSECS,
            'customdata' => json_encode([
                'userid' => (int)$this->employee->id,
                'messageid' => $this->messageid,
                'ruleid' => $this->ruleid,
                'manualchanged' => false,
            ]),
            'blocking' => 0,
            'faildelay' => 0,
        ]);

        $result = $this->run_skill([
            'messageid' => $this->messageid,
            'assignmentid' => $this->assignmentid,
        ]);

        $this->assertCount(1, $result['pending_tasks']);
        $this->assertSame((int)$this->employee->id, $result['pending_tasks'][0]['userid']);
    }

    /**
     * An unknown template is rejected in the preflight.
     */
    public function test_unknown_template(): void {
        global $USER;

        $skill = new diagnose_message_delivery_skill();
        $preflight = $skill->preflight(
            ['messageid' => $this->messageid + 1000],
            $this->contextid,
            (int)$USER->id
        );

        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(diagnose_message_delivery_skill::ISSUE_MESSAGE_NOT_FOUND, $preflight->issuecodes);
    }

    /**
     * Neither editmessages nor supervisor: access denied.
     */
    public function test_scope_denied_for_stranger(): void {
        $stranger = $this->getDataGenerator()->create_user();
        $this->setUser($stranger);

        $skill = new diagnose_message_delivery_skill();
        $input = ['messageid' => $this->messageid, 'assignmentid' => $this->assignmentid];

        $preflight = $skill->preflight($input, $this->contextid, (int)$stranger->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(taskflow_skill_base::ISSUE_SCOPE_DENIED, $preflight->issuecodes);

        $result = $skill->execute($input, $this->contextid, (int)$stranger->id);
        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $result['status']);
    }

    /**
     * The result declares the shared diagnostic checklist preview with rows and a verdict.
     */
    public function test_preview_data(): void {
        $result = $this->run_skill([
            'messageid' => $this->messageid,
            'assignmentid' => $this->assignmentid,
        ]);

        $preview = $result['preview'];
        $this->assertSame(taskflow_preview_renderer_factory::TYPE_DIAGNOSTIC_CHECKLIST, $preview['type']);
        $this->assertSame([$this->messageid], $preview['payload']['messageids']);
        $this->assertSame([$this->assignmentid], $preview['payload']['assignmentids']);
        $this->assertSame(
            diagnose_message_delivery_skill::VERDICT_DELIVERABLE,
            $preview['data']['verdict']['code']
        );
        $this->assertSame(
            get_string('agent_preview_verdict_deliverable', 'local_taskflow'),
            $preview['data']['verdict']['label']
        );
        $this->assertSame([$this->assignmentid], $preview['data']['ids']['assignmentids']);

        // The shared checklist renderer accepts the declared data.
        global $USER;
        $rendered = (new diagnose_message_delivery_skill())
            ->get_result_preview($result, $this->contextid, (int)$USER->id);
        if ($rendered !== null) {
            $this->assertSame(taskflow_preview_renderer_factory::TYPE_DIAGNOSTIC_CHECKLIST, $rendered['type']);
            $this->assertStringContainsString('Reminder 7 days', $rendered['html']);
            $this->assertStringContainsString(
                get_string('agent_preview_verdict_deliverable', 'local_taskflow'),
                $rendered['html']
            );
        }
        $this->assertNotEmpty($preview['data']['rows']);
        foreach ($preview['data']['rows'] as $row) {
            $this->assertContains($row['status'], ['ok', 'fail', 'warn']);
            $this->assertArrayHasKey('check', $row);
            $this->assertArrayHasKey('detail', $row);
            $this->assertArrayHasKey('url', $row);
        }
    }
}
