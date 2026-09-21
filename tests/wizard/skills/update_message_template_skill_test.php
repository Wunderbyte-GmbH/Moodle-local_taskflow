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
use local_taskflow\local\messages_form\message_form_entity;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\skills\create_message_template_skill;
use local_taskflow\local\wizard\taskflow\skills\message_template_skill_base;
use local_taskflow\local\wizard\taskflow\skills\update_message_template_skill;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use local_taskflow\wizard\local_wizard_dependency;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../local_wizard_dependency.php');

/**
 * Partial updates of message templates (local_taskflow.update_message_template).
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\skills\update_message_template_skill
 * @covers     \local_taskflow\local\wizard\taskflow\skills\message_template_skill_base
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class update_message_template_skill_test extends advanced_testcase {
    /** @var \local_taskflow_generator */
    private $generator;

    /** @var \stdClass Editor of message templates. */
    private \stdClass $editor;

    /** @var \stdClass User without the editmessages capability. */
    private \stdClass $stranger;

    /** @var int Id of the template under test. */
    private int $messageid = 0;

    /**
     * Setup: engine, standard adapter, one stored template.
     */
    protected function setUp(): void {
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

        $this->setUser((int)$this->editor->id);
        $create = new create_message_template_skill();
        $preflight = $create->preflight([
            'name' => 'Reminder 7 days',
            'type' => 'standard',
            'subject' => 'Reminder',
            'body' => '<p>Body</p>',
            'recipientrole' => ['assignee'],
            'senddirection' => 'before',
            'sendstart' => 'end',
            'senddays' => 7,
            'timeunit' => 'days',
            'priority' => 2,
            'package' => ['reminder'],
        ], context_system::instance()->id, (int)$this->editor->id);
        $result = $create->execute(
            $preflight->preparedinput,
            context_system::instance()->id,
            (int)$this->editor->id
        );
        $this->messageid = (int)$result['resultid'];
    }

    /**
     * Teardown: singletons.
     */
    protected function tearDown(): void {
        parent::tearDown();
        $this->generator->teardown();
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
        return (new update_message_template_skill())->preflight($input, context_system::instance()->id, $userid);
    }

    /**
     * The proposal is confirmable and names the changed field.
     */
    public function test_confirmable_proposal(): void {
        $skill = new update_message_template_skill();
        $this->setUser((int)$this->editor->id);
        $preflight = $skill->preflight([
            'messageid' => $this->messageid,
            'subject' => 'New subject',
        ], context_system::instance()->id, (int)$this->editor->id);

        $this->assertSame('soft_block', $preflight->status);
        $this->assertContains(message_template_skill_base::ISSUE_CONFIRM, $preflight->issuecodes);

        $description = $skill->describe_proposed_action($preflight->preparedinput);
        $this->assertIsArray($description);
        $values = implode(' | ', array_column($description['rows'], 'value'));
        $this->assertStringContainsString('New subject', $values);
        $this->assertStringContainsString('Reminder -> New subject', $values);
    }

    /**
     * Only the given field changes; everything else keeps its stored value.
     */
    public function test_updates_only_the_given_fields(): void {
        global $DB;

        $preflight = $this->preflight([
            'messageid' => $this->messageid,
            'subject' => 'New subject',
        ], (int)$this->editor->id);
        $result = (new update_message_template_skill())->execute(
            $preflight->preparedinput,
            context_system::instance()->id,
            (int)$this->editor->id
        );

        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status'], (string)$result['detail']);
        $this->assertSame([], $result['verification']['unverified']);
        $this->assertSame($this->messageid, (int)$result['resultid']);

        $stored = (new message_form_entity())->prepare_record_for_form($this->messageid);
        $this->assertSame('New subject', (string)$stored->heading);
        $this->assertSame('Reminder 7 days', (string)$stored->messagename);
        $this->assertSame(['assignee'], array_values((array)$stored->recipientrole));
        $this->assertSame('7', (string)$stored->senddays);
        $this->assertContains('reminder', array_values((array)$stored->tags));
        $this->assertSame(1, $DB->count_records('local_taskflow_messages'));

        $this->assertSame(
            taskflow_preview_renderer_factory::TYPE_MESSAGE_TEMPLATE_LIST,
            $result['preview']['type']
        );
        $this->assertArrayHasKey('heading', $result['changes']);
    }

    /**
     * An unknown or missing template id blocks the call.
     */
    public function test_unknown_template_is_rejected(): void {
        $preflight = $this->preflight(['messageid' => $this->messageid + 1000], (int)$this->editor->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(message_template_skill_base::ISSUE_MESSAGE_NOT_FOUND, $preflight->issuecodes);

        $preflight = $this->preflight(['subject' => 'No id'], (int)$this->editor->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertNotEmpty($preflight->issues);
    }

    /**
     * Data the editor rejects blocks the update and leaves the record untouched.
     */
    public function test_editor_validation_blocks_the_update(): void {
        $preflight = $this->preflight([
            'messageid' => $this->messageid,
            'recipientrole' => [],
        ], (int)$this->editor->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(message_template_skill_base::ISSUE_VALIDATION_FAILED, $preflight->issuecodes);

        $stored = (new message_form_entity())->prepare_record_for_form($this->messageid);
        $this->assertSame(['assignee'], array_values((array)$stored->recipientrole));
    }

    /**
     * Without local/taskflow:editmessages nothing happens.
     */
    public function test_scope_denied(): void {
        $preflight = $this->preflight([
            'messageid' => $this->messageid,
            'subject' => 'Hijacked',
        ], (int)$this->stranger->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(taskflow_skill_base::ISSUE_SCOPE_DENIED, $preflight->issuecodes);

        $result = (new update_message_template_skill())->execute([
            'messageid' => $this->messageid,
            'subject' => 'Hijacked',
        ], context_system::instance()->id, (int)$this->stranger->id);
        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $result['status']);

        $stored = (new message_form_entity())->prepare_record_for_form($this->messageid);
        $this->assertSame('Reminder', (string)$stored->heading);
    }
}
