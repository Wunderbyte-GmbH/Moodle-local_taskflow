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
use core_tag_tag;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\skills\create_message_template_skill;
use local_taskflow\local\wizard\taskflow\skills\message_template_skill_base;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use local_taskflow\wizard\local_wizard_dependency;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../local_wizard_dependency.php');

/**
 * Editor backed creation of message templates (local_taskflow.create_message_template).
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\skills\create_message_template_skill
 * @covers     \local_taskflow\local\wizard\taskflow\skills\message_template_skill_base
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class create_message_template_skill_test extends advanced_testcase {
    /** @var \local_taskflow_generator */
    private $generator;

    /** @var \stdClass Editor of message templates. */
    private \stdClass $editor;

    /** @var \stdClass User without the editmessages capability. */
    private \stdClass $stranger;

    /**
     * Setup: engine, standard adapter, one user holding local/taskflow:editmessages.
     */
    protected function setUp(): void {
        parent::setUp();
        local_wizard_dependency::require_installed();
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_taskflow');
        $this->generator->set_config_values('standard');
        \local_taskflow\local\external_adapter\external_api_base::destroy_instance();

        $this->editor = $this->getDataGenerator()->create_user();
        $this->stranger = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(
            message_template_skill_base::CAPABILITY,
            CAP_ALLOW,
            $roleid,
            context_system::instance()->id,
            true
        );
        role_assign($roleid, (int)$this->editor->id, context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Teardown: singletons.
     */
    protected function tearDown(): void {
        parent::tearDown();
        $this->generator->teardown();
    }

    /**
     * Valid template payload.
     *
     * @param array $override
     * @return array
     */
    private function payload(array $override = []): array {
        return $override + [
            'name' => 'Reminder 7 days',
            'type' => 'standard',
            'subject' => 'Reminder: <rulename>',
            'body' => '<p>Dear <firstname>, your assignment is due soon.</p>',
            'recipientrole' => ['assignee'],
            'senddirection' => 'before',
            'sendstart' => 'end',
            'senddays' => 7,
            'timeunit' => 'days',
            'priority' => 3,
            'package' => ['reminder'],
        ];
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
        return (new create_message_template_skill())->preflight($input, context_system::instance()->id, $userid);
    }

    /**
     * The proposal is confirmable and lists the resulting template fields.
     */
    public function test_confirmable_proposal(): void {
        $skill = new create_message_template_skill();
        $this->setUser((int)$this->editor->id);
        $preflight = $skill->preflight($this->payload(), context_system::instance()->id, (int)$this->editor->id);

        $this->assertSame('soft_block', $preflight->status);
        $this->assertContains(message_template_skill_base::ISSUE_CONFIRM, $preflight->issuecodes);

        $description = $skill->describe_proposed_action($preflight->preparedinput);
        $this->assertIsArray($description);
        $values = array_column($description['rows'], 'value');
        $this->assertContains('Reminder 7 days', $values);
        $this->assertContains('Reminder: <rulename>', $values);
        $this->assertContains('reminder', $values);
    }

    /**
     * The template row and its tags are written and verified.
     */
    public function test_creates_and_verifies_template(): void {
        global $DB;

        $preflight = $this->preflight($this->payload(), (int)$this->editor->id);
        $result = (new create_message_template_skill())->execute(
            $preflight->preparedinput,
            context_system::instance()->id,
            (int)$this->editor->id
        );

        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status'], (string)$result['detail']);
        $messageid = (int)$result['resultid'];
        $this->assertGreaterThan(0, $messageid);
        $this->assertSame([], $result['verification']['unverified']);

        $record = $DB->get_record('local_taskflow_messages', ['id' => $messageid], '*', MUST_EXIST);
        $this->assertSame('Reminder 7 days', (string)$record->name);
        $this->assertSame(3, (int)$record->priority);
        $decoded = json_decode((string)$record->message);
        $this->assertSame('Reminder: <rulename>', (string)$decoded->heading);
        $sending = json_decode((string)$record->sending_settings);
        $this->assertSame(['assignee'], (array)$sending->recipientrole);
        $this->assertSame('7', (string)$sending->senddays);

        $tags = core_tag_tag::get_item_tags_array('local_taskflow', 'local_taskflow_messages', $messageid);
        $this->assertContains('reminder', array_values($tags));

        $this->assertSame(
            taskflow_preview_renderer_factory::TYPE_MESSAGE_TEMPLATE_LIST,
            $result['preview']['type']
        );
        $this->assertSame([$messageid], $result['preview']['payload']['messageids']);
    }

    /**
     * Data the editor rejects blocks the call and writes nothing.
     */
    public function test_editor_validation_blocks_the_write(): void {
        global $DB;

        // A standard template without a recipient role is refused by editmessagesmanager::validation().
        $preflight = $this->preflight($this->payload(['recipientrole' => []]), (int)$this->editor->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(message_template_skill_base::ISSUE_VALIDATION_FAILED, $preflight->issuecodes);
        $this->assertSame(0, $DB->count_records('local_taskflow_messages'));

        // Sending before an anchor other than the end date is an invalid combination.
        $preflight = $this->preflight($this->payload(['sendstart' => 'start']), (int)$this->editor->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(message_template_skill_base::ISSUE_VALIDATION_FAILED, $preflight->issuecodes);

        // The guard also holds when execute() is called directly.
        $result = (new create_message_template_skill())->execute(
            $this->payload(['recipientrole' => []]),
            context_system::instance()->id,
            (int)$this->editor->id
        );
        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $result['status']);
        $this->assertContains(message_template_skill_base::ISSUE_VALIDATION_FAILED, $result['issue_codes']);
        $this->assertSame(0, $DB->count_records('local_taskflow_messages'));
    }

    /**
     * Missing mandatory fields are reported before the editor is asked.
     */
    public function test_mandatory_fields(): void {
        $preflight = $this->preflight(['name' => '', 'subject' => '', 'body' => ''], (int)$this->editor->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertCount(3, $preflight->issues);
    }

    /**
     * Without local/taskflow:editmessages nothing happens.
     */
    public function test_scope_denied(): void {
        global $DB;

        $preflight = $this->preflight($this->payload(), (int)$this->stranger->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(taskflow_skill_base::ISSUE_SCOPE_DENIED, $preflight->issuecodes);

        $result = (new create_message_template_skill())->execute(
            $this->payload(),
            context_system::instance()->id,
            (int)$this->stranger->id
        );
        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $result['status']);
        $this->assertContains(taskflow_skill_base::ISSUE_SCOPE_DENIED, $result['issue_codes']);
        $this->assertSame(0, $DB->count_records('local_taskflow_messages'));
    }
}
