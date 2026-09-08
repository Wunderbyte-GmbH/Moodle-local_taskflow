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
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\skills\send_message_now_skill;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use local_taskflow\wizard\local_wizard_dependency;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../local_wizard_dependency.php');

/**
 * Immediate sending of one message template (local_taskflow.send_message_now).
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\skills\send_message_now_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class send_message_now_skill_test extends advanced_testcase {
    /** @var \local_taskflow_generator */
    private $generator;

    /** @var \stdClass Editor of message templates. */
    private \stdClass $editor;

    /** @var \stdClass User without the editmessages capability. */
    private \stdClass $stranger;

    /** @var \stdClass Assignee whose message was already sent. */
    private \stdClass $contacted;

    /** @var \stdClass Assignee who still has to receive the message. */
    private \stdClass $fresh;

    /** @var int Rule of both assignments. */
    private int $ruleid = 0;

    /** @var int Assignment of the already contacted user. */
    private int $contactedassignment = 0;

    /** @var int Assignment of the untouched user. */
    private int $freshassignment = 0;

    /** @var int Message template that is sent. */
    private int $messageid = 0;

    /**
     * Setup: engine, one standard template, two assignments, one of them already contacted.
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
        $this->preventResetByRollback();
        $this->setAdminUser();

        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_taskflow');
        $this->generator->set_config_values('standard');
        \local_taskflow\local\external_adapter\external_api_base::destroy_instance();
        set_config('sendmanualmailsmultipletimes', 0, 'local_taskflow');

        $this->editor = $this->getDataGenerator()->create_user();
        $this->stranger = $this->getDataGenerator()->create_user();
        $this->contacted = $this->getDataGenerator()->create_user(['firstname' => 'Anna', 'lastname' => 'Muster']);
        $this->fresh = $this->getDataGenerator()->create_user(['firstname' => 'Bert', 'lastname' => 'Beispiel']);

        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(
            send_message_now_skill::CAPABILITY,
            CAP_ALLOW,
            $roleid,
            context_system::instance()->id,
            true
        );
        role_assign($roleid, (int)$this->editor->id, context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->messageid = (int)$DB->insert_record('local_taskflow_messages', (object)[
            'name' => 'Reminder 7 days',
            'class' => 'standard',
            'priority' => 2,
            'message' => json_encode(['heading' => 'Reminder', 'body' => '<p>Please finish your assignment.</p>']),
            'sending_settings' => json_encode([
                'recipientrole' => ['assignee'],
                'userid' => 0,
                'carboncopyrole' => [],
                'ccuserid' => 0,
                'senddirection' => 'before',
                'eventlist' => [],
                'sendingcondition' => 0,
                'sendstart' => 'end',
                'sendstartrequest' => '',
                'senddays' => '7',
                'timeunit' => 'days',
            ]),
            'usermodified' => (int)$this->editor->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $this->ruleid = (int)$this->generator->create_rule(['name' => 'Data protection']);
        $this->contactedassignment = $this->create_assignment((int)$this->contacted->id);
        $this->freshassignment = $this->create_assignment((int)$this->fresh->id);

        $DB->insert_record('local_taskflow_sent_messages', (object)[
            'messageid' => $this->messageid,
            'ruleid' => $this->ruleid,
            'userid' => (int)$this->contacted->id,
            'timesent' => time() - DAYSECS,
        ]);
    }

    /**
     * Teardown: singletons.
     */
    protected function tearDown(): void {
        parent::tearDown();
        $this->generator->teardown();
    }

    /**
     * Create an assignment for the shared rule and return its id.
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
     * Preflight as the given user.
     *
     * @param array $input
     * @param int $userid
     * @return object
     */
    private function preflight(array $input, int $userid) {
        $this->setUser($userid);
        return (new send_message_now_skill())->preflight($input, context_system::instance()->id, $userid);
    }

    /**
     * The proposal is confirmable and states how many mails actually go out.
     */
    public function test_confirmable_proposal_states_the_number_of_mails(): void {
        $skill = new send_message_now_skill();
        $this->setUser((int)$this->editor->id);
        $preflight = $skill->preflight([
            'messageid' => $this->messageid,
            'assignmentids' => [$this->contactedassignment, $this->freshassignment],
        ], context_system::instance()->id, (int)$this->editor->id);

        $this->assertSame('soft_block', $preflight->status);
        $this->assertContains(send_message_now_skill::ISSUE_CONFIRM, $preflight->issuecodes);

        $plan = $preflight->preparedinput['plan'];
        $this->assertCount(2, $plan);
        $this->assertTrue((bool)$plan[0]['skipped']);
        $this->assertFalse((bool)$plan[1]['skipped']);

        $description = $skill->describe_proposed_action($preflight->preparedinput);
        $this->assertIsArray($description);
        $rows = [];
        foreach ($description['rows'] as $row) {
            $rows[$row['label']] = $row['value'];
        }
        $this->assertContains('1', array_values($rows), 'The number of mails that will go out must be shown.');
        $this->assertStringContainsString('Bert Beispiel', implode(' ', array_values($rows)));
    }

    /**
     * One mail goes out, the other one is skipped and creates no send log row.
     */
    public function test_mixed_outcome(): void {
        global $DB;

        $mailsink = $this->redirectEmails();
        $messagesink = $this->redirectMessages();

        $preflight = $this->preflight([
            'messageid' => $this->messageid,
            'assignmentids' => [$this->contactedassignment, $this->freshassignment],
        ], (int)$this->editor->id);
        $result = (new send_message_now_skill())->execute(
            $preflight->preparedinput,
            context_system::instance()->id,
            (int)$this->editor->id
        );

        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status'], (string)$result['detail']);
        $this->assertSame(1, $result['sent']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $result['errors']);

        $outcomes = [];
        foreach ($result['outcomes'] as $outcome) {
            $outcomes[(int)$outcome['assignmentid']] = (string)$outcome['outcome'];
        }
        $this->assertSame(send_message_now_skill::OUTCOME_SKIPPED, $outcomes[$this->contactedassignment]);
        $this->assertSame(send_message_now_skill::OUTCOME_SENT, $outcomes[$this->freshassignment]);

        // The skipped assignment must not gain a second send log row.
        $this->assertSame(1, $DB->count_records('local_taskflow_sent_messages', [
            'messageid' => $this->messageid,
            'userid' => (int)$this->contacted->id,
        ]));
        $this->assertSame(1, $DB->count_records('local_taskflow_sent_messages', [
            'messageid' => $this->messageid,
            'ruleid' => $this->ruleid,
            'userid' => (int)$this->fresh->id,
        ]));

        $this->assertSame(
            taskflow_preview_renderer_factory::TYPE_MESSAGE_PREVIEW,
            $result['preview']['type']
        );

        $mailsink->close();
        $messagesink->close();
    }

    /**
     * When every assignment was already contacted nothing is sent at all.
     */
    public function test_nothing_to_send(): void {
        $preflight = $this->preflight([
            'messageid' => $this->messageid,
            'assignmentids' => [$this->contactedassignment],
        ], (int)$this->editor->id);

        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(send_message_now_skill::ISSUE_NOTHING_TO_SEND, $preflight->issuecodes);
    }

    /**
     * Unknown template, empty list and oversized list are refused.
     */
    public function test_input_gates(): void {
        $preflight = $this->preflight([
            'messageid' => $this->messageid + 1000,
            'assignmentids' => [$this->freshassignment],
        ], (int)$this->editor->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(send_message_now_skill::ISSUE_MESSAGE_NOT_FOUND, $preflight->issuecodes);

        $preflight = $this->preflight([
            'messageid' => $this->messageid,
            'assignmentids' => [],
        ], (int)$this->editor->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(send_message_now_skill::ISSUE_NO_ASSIGNMENTS, $preflight->issuecodes);

        $preflight = $this->preflight([
            'messageid' => $this->messageid,
            'assignmentids' => range(1, send_message_now_skill::MAX_ASSIGNMENTS + 5),
        ], (int)$this->editor->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(send_message_now_skill::ISSUE_TOO_MANY, $preflight->issuecodes);

        $preflight = $this->preflight([
            'messageid' => $this->messageid,
            'assignmentids' => [$this->freshassignment, $this->freshassignment + 1000],
        ], (int)$this->editor->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(send_message_now_skill::ISSUE_ASSIGNMENT_UNRESOLVED, $preflight->issuecodes);
    }

    /**
     * Without local/taskflow:editmessages nothing is sent.
     */
    public function test_scope_denied(): void {
        global $DB;

        $preflight = $this->preflight([
            'messageid' => $this->messageid,
            'assignmentids' => [$this->freshassignment],
        ], (int)$this->stranger->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(taskflow_skill_base::ISSUE_SCOPE_DENIED, $preflight->issuecodes);

        $result = (new send_message_now_skill())->execute([
            'messageid' => $this->messageid,
            'assignmentids' => [$this->freshassignment],
        ], context_system::instance()->id, (int)$this->stranger->id);
        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $result['status']);
        $this->assertContains(taskflow_skill_base::ISSUE_SCOPE_DENIED, $result['issue_codes']);
        $this->assertSame(1, $DB->count_records('local_taskflow_sent_messages'));
    }
}
