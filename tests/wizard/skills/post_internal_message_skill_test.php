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
use local_taskflow\local\assignments\assignment;
use local_taskflow\local\internal_messages\internal_messages;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\skills\post_internal_message_skill;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use local_taskflow\wizard\local_wizard_dependency;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../local_wizard_dependency.php');

/**
 * Setting gate, scope and verification of local_taskflow.post_internal_message.
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\skills\post_internal_message_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class post_internal_message_skill_test extends advanced_testcase {
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
     * Setup: standard adapter, internal communication on, one assignment of Anna Muster.
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
        set_config('allowinternalcommunication', 1, 'local_taskflow');

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
        $this->setUser($userid);
        return (new post_internal_message_skill())->preflight($input, context_system::instance()->id, $userid);
    }

    /**
     * Execute the skill as the given user.
     *
     * @param array $input
     * @param int $userid
     * @return array
     */
    private function execute(array $input, int $userid): array {
        $this->setUser($userid);
        return (new post_internal_message_skill())->execute($input, context_system::instance()->id, $userid);
    }

    /**
     * The assignee posts a message; the row exists and the recipients are named.
     */
    public function test_assignee_posts_message(): void {
        global $DB;

        $input = ['assignmentid' => $this->assignmentid, 'text' => 'Please upload the certificate.'];
        $preflight = $this->preflight($input, (int)$this->employee->id);
        $this->assertSame('soft_block', $preflight->status);
        $this->assertContains(post_internal_message_skill::ISSUE_CONFIRM_REQUIRED, $preflight->issuecodes);

        $descriptor = (new post_internal_message_skill())->describe_proposed_action($preflight->preparedinput);
        $this->assertStringContainsString('#' . $this->assignmentid, $descriptor['title']);
        $this->assertStringContainsString('Anna Muster', $descriptor['title']);
        $this->assertNotSame('', $descriptor['summary']);
        $labels = array_column($descriptor['rows'], 'label');
        $values = array_column($descriptor['rows'], 'value');
        $this->assertContains(get_string('assignment', 'local_taskflow'), $labels);
        $this->assertContains(get_string('agent_post_message_row_recipients', 'local_taskflow'), $labels);
        $this->assertContains(get_string('internalcommunication', 'local_taskflow'), $labels);
        $this->assertContains('Please upload the certificate.', $values);
        $this->assertStringContainsString('Emily Smith', implode(' ', $values));

        $result = $this->execute($preflight->preparedinput, (int)$this->employee->id);
        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status']);
        $this->assertGreaterThan(0, $result['internalmessageid']);
        $this->assertSame(1, $result['messages_total']);
        $this->assertContains('Anna Muster', $result['recipients']);
        $this->assertContains('Emily Smith', $result['recipients']);

        $records = $DB->get_records(internal_messages::TABLENAME, ['assignmentid' => $this->assignmentid]);
        $this->assertCount(1, $records);
        $record = reset($records);
        $this->assertSame('Please upload the certificate.', (string)$record->message);
        $this->assertSame((int)$this->employee->id, (int)$record->usermodified);

        $this->assertSame(taskflow_preview_renderer_factory::TYPE_ASSIGNMENT, $result['preview']['type']);
        $this->assertSame([$this->assignmentid], $result['preview']['payload']['assignmentids']);
        $this->assertStringContainsString('internalmessageid=', $result['observation_full']);
    }

    /**
     * The supervisor may post as well; the admin too.
     */
    public function test_supervisor_and_admin_may_post(): void {
        global $DB;

        $result = $this->execute(
            ['assignmentid' => $this->assignmentid, 'text' => 'Extension granted.'],
            (int)$this->supervisor->id
        );
        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status']);

        $result = $this->execute(
            ['assignmentid' => $this->assignmentid, 'text' => 'Noted by HR.'],
            (int)get_admin()->id
        );
        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status']);
        $this->assertSame(
            2,
            (int)$DB->count_records(internal_messages::TABLENAME, ['assignmentid' => $this->assignmentid])
        );
    }

    /**
     * With internal communication switched off nothing is posted.
     */
    public function test_chat_disabled_blocks_the_skill(): void {
        global $DB;

        set_config('allowinternalcommunication', 0, 'local_taskflow');
        $input = ['assignmentid' => $this->assignmentid, 'text' => 'Hello'];

        $preflight = $this->preflight($input, (int)$this->employee->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(post_internal_message_skill::ISSUE_CHAT_DISABLED, $preflight->issuecodes);

        $result = $this->execute($input, (int)$this->employee->id);
        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $result['status']);
        $this->assertContains(post_internal_message_skill::ISSUE_CHAT_DISABLED, $result['issue_codes']);
        $this->assertSame(
            0,
            (int)$DB->count_records(internal_messages::TABLENAME, ['assignmentid' => $this->assignmentid])
        );
    }

    /**
     * A foreign user is blocked in preflight and in execute; empty text is refused.
     */
    public function test_foreign_user_and_empty_text_are_refused(): void {
        global $DB;

        $input = ['assignmentid' => $this->assignmentid, 'text' => 'Hello'];
        $preflight = $this->preflight($input, (int)$this->stranger->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(taskflow_skill_base::ISSUE_SCOPE_DENIED, $preflight->issuecodes);

        $result = $this->execute($input, (int)$this->stranger->id);
        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $result['status']);
        $this->assertContains(taskflow_skill_base::ISSUE_SCOPE_DENIED, $result['issue_codes']);
        $this->assertSame(
            0,
            (int)$DB->count_records(internal_messages::TABLENAME, ['assignmentid' => $this->assignmentid])
        );

        $preflight = $this->preflight(
            ['assignmentid' => $this->assignmentid, 'text' => '  '],
            (int)$this->employee->id
        );
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains('VALIDATION_ERROR', $preflight->issuecodes);
    }

    /**
     * The skill is a mutating R1 skill implementing the queue identity contract.
     */
    public function test_skill_metadata(): void {
        $skill = new post_internal_message_skill();
        $this->assertFalse($skill->is_read_only());
        $this->assertSame('local_taskflow.post_internal_message', $skill->get_name());
        $identity = $skill->build_queue_business_identity([
            'assignmentid' => $this->assignmentid,
            'text' => 'Hello',
        ]);
        $this->assertSame('local_taskflow.post_internal_message', $identity['task_family']);
        $this->assertSame($this->assignmentid, $identity['target']['assignmentid']);
    }
}
