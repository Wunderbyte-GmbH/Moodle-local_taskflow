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
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\skills\preview_message_skill;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use local_taskflow\wizard\local_wizard_dependency;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../local_wizard_dependency.php');

/**
 * Skill local_taskflow.preview_message (renders, never sends).
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\skills\preview_message_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class preview_message_skill_test extends advanced_testcase {
    /** @var \local_taskflow_generator */
    private $generator;

    /** @var \stdClass Supervisor of the assignee. */
    private \stdClass $supervisor;

    /** @var \stdClass Assignee. */
    private \stdClass $employee;

    /** @var int Message template. */
    private int $messageid = 0;

    /** @var int Rule. */
    private int $ruleid = 0;

    /** @var int Assignment. */
    private int $assignmentid = 0;

    /** @var int System context id. */
    private int $contextid = 0;

    /**
     * Setup: standard adapter, supervisor mapping, one template with placeholders and one assignment.
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
        external_api_base::destroy_instance();

        $this->supervisor = $this->getDataGenerator()->create_user(['firstname' => 'Emily', 'lastname' => 'Smith']);
        $this->employee = $this->getDataGenerator()->create_user(['firstname' => 'Anna', 'lastname' => 'Muster']);
        $DB->insert_record('user_info_data', (object)[
            'userid' => $this->employee->id,
            'fieldid' => $fields['supervisor'],
            'data' => (string)$this->supervisor->id,
            'dataformat' => 0,
        ]);

        $this->messageid = (int)$DB->insert_record('local_taskflow_messages', (object)[
            'name' => 'Reminder 7 days',
            'class' => 'standard',
            'message' => json_encode([
                'heading' => 'Reminder for <firstname>',
                'body' => '<p>Hello <firstname> <lastname>, your training is due.</p>',
            ]),
            'priority' => 2,
            'sending_settings' => json_encode([
                'recipientrole' => ['assignee'],
                'carboncopyrole' => ['supervisor'],
                'senddirection' => 'before',
                'senddays' => '7',
                'timeunit' => 'days',
                'sendstart' => 'end',
            ]),
            'usermodified' => 2,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $this->ruleid = (int)$this->generator->create_rule([
            'name' => 'Data protection basics',
            'messages' => [$this->messageid],
        ]);
        $this->generator->create_user_assignment((int)$this->employee->id, $this->ruleid);
        $this->assignmentid = (int)$DB->get_field(
            'local_taskflow_assignment',
            'id',
            ['userid' => $this->employee->id, 'ruleid' => $this->ruleid],
            MUST_EXIST
        );

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
     * Execute the skill as the current user after a passing preflight.
     *
     * @param array $input
     * @return array
     */
    private function run_skill(array $input): array {
        global $USER;
        $skill = new preview_message_skill();
        $preflight = $skill->preflight($input, $this->contextid, (int)$USER->id);
        $this->assertSame('pass', $preflight->status);
        return $skill->execute($preflight->preparedinput, $this->contextid, (int)$USER->id);
    }

    /**
     * Contract: name, read-only R0, native capability, both ids required.
     */
    public function test_contract(): void {
        $skill = new preview_message_skill();
        $this->assertSame('local_taskflow.preview_message', $skill->get_name());
        $this->assertTrue($skill->is_read_only());
        $this->assertSame(['local/taskflow:editmessages'], $skill->get_required_native_capabilities());

        $schema = $skill->get_schema();
        $this->assertTrue($schema['properties']['messageid']['required']);
        $this->assertTrue($schema['properties']['assignmentid']['required']);
        $this->assertSame(['system'], $schema['prompt_meta']['context_scopes']);
    }

    /**
     * Placeholders are resolved and NOTHING is sent: no send-log row, no history entry, no task.
     */
    public function test_renders_without_sending(): void {
        global $DB;

        $before = [
            'sent' => $DB->count_records('local_taskflow_sent_messages'),
            'history' => $DB->count_records('local_taskflow_history'),
            'tasks' => $DB->count_records('task_adhoc'),
        ];

        $result = $this->run_skill([
            'messageid' => (string)$this->messageid,
            'assignmentid' => (string)$this->assignmentid,
        ]);

        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status']);
        $this->assertSame($this->messageid, $result['resultid']);
        $this->assertFalse($result['sent']);

        // The <firstname> placeholder is replaced in both subject and body.
        $this->assertSame('Reminder for Anna', $result['subject']);
        $this->assertStringContainsString('Hello Anna Muster', $result['body_html']);
        $this->assertStringNotContainsString('<firstname>', $result['body_html']);
        $this->assertContains('<firstname>', $result['placeholders_used']);
        $this->assertContains('<lastname>', $result['placeholders_used']);

        $this->assertSame($before['sent'], $DB->count_records('local_taskflow_sent_messages'));
        $this->assertSame(0, $DB->count_records('local_taskflow_sent_messages', [
            'messageid' => $this->messageid,
        ]));
        $this->assertSame($before['history'], $DB->count_records('local_taskflow_history'));
        $this->assertSame($before['tasks'], $DB->count_records('task_adhoc'));
    }

    /**
     * Recipients and CC are resolved through message_recipient.
     */
    public function test_recipients(): void {
        $result = $this->run_skill([
            'messageid' => $this->messageid,
            'assignmentid' => $this->assignmentid,
        ]);

        $roles = [];
        foreach ($result['recipients'] as $recipient) {
            $roles[(int)$recipient['userid']] = (string)$recipient['role'];
        }
        $this->assertSame('to', $roles[(int)$this->employee->id]);
        $this->assertSame('cc', $roles[(int)$this->supervisor->id]);
    }

    /**
     * Unknown ids are rejected in the preflight with dedicated issue codes.
     */
    public function test_not_found(): void {
        global $USER;
        $skill = new preview_message_skill();

        $preflight = $skill->preflight(
            ['messageid' => $this->messageid + 1000, 'assignmentid' => $this->assignmentid],
            $this->contextid,
            (int)$USER->id
        );
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(preview_message_skill::ISSUE_MESSAGE_NOT_FOUND, $preflight->issuecodes);

        $preflight = $skill->preflight(
            ['messageid' => $this->messageid, 'assignmentid' => $this->assignmentid + 1000],
            $this->contextid,
            (int)$USER->id
        );
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(taskflow_skill_base::ISSUE_ASSIGNMENT_NOT_FOUND, $preflight->issuecodes);
    }

    /**
     * Without local/taskflow:editmessages the preflight blocks and execute errors out.
     */
    public function test_requires_editmessages_capability(): void {
        $this->setUser($this->employee);
        $skill = new preview_message_skill();
        $input = ['messageid' => $this->messageid, 'assignmentid' => $this->assignmentid];

        $preflight = $skill->preflight($input, $this->contextid, (int)$this->employee->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(taskflow_skill_base::ISSUE_SCOPE_DENIED, $preflight->issuecodes);

        $result = $skill->execute($input, $this->contextid, (int)$this->employee->id);
        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $result['status']);
        $this->assertArrayNotHasKey('body_html', $result);
    }

    /**
     * The declared preview renders a mail card with recipients, subject and body.
     */
    public function test_result_preview(): void {
        global $USER;
        $result = $this->run_skill([
            'messageid' => $this->messageid,
            'assignmentid' => $this->assignmentid,
        ]);
        $preview = (new preview_message_skill())->get_result_preview($result, $this->contextid, (int)$USER->id);

        $this->assertNotNull($preview);
        $this->assertSame(taskflow_preview_renderer_factory::TYPE_MESSAGE_PREVIEW, $preview['type']);
        $this->assertSame([$this->messageid], $preview['payload']['messageids']);
        $this->assertSame([$this->assignmentid], $preview['payload']['assignmentids']);
        $this->assertStringContainsString('Reminder for Anna', $preview['html']);
        $this->assertStringContainsString('Anna Muster', $preview['html']);
        $this->assertStringContainsString('Emily Smith', $preview['html']);
        $this->assertStringContainsString(
            get_string('agent_preview_message_nosend', 'local_taskflow'),
            $preview['html']
        );
    }
}
