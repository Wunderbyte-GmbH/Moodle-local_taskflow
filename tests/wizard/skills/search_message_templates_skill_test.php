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
use local_taskflow\local\wizard\taskflow\skills\search_message_templates_skill;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use local_taskflow\wizard\local_wizard_dependency;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../local_wizard_dependency.php');

/**
 * Skill local_taskflow.search_message_templates.
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\skills\search_message_templates_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class search_message_templates_skill_test extends advanced_testcase {
    /** @var int Standard reminder template. */
    private int $reminderid = 0;

    /** @var int Request template. */
    private int $requestid = 0;

    /** @var int Rule using the reminder template. */
    private int $ruleid = 0;

    /** @var int System context id. */
    private int $contextid = 0;

    /**
     * Setup: engine, standard adapter config, two templates and a rule using one of them.
     */
    protected function setUp(): void {
        parent::setUp();
        local_wizard_dependency::require_installed();
        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var \local_taskflow_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_taskflow');
        $generator->set_config_values('standard');

        $this->reminderid = $this->create_template('Reminder 7 days', 'standard', [
            'recipientrole' => ['assignee'],
            'carboncopyrole' => ['supervisor'],
            'senddirection' => 'before',
            'senddays' => '7',
            'timeunit' => 'days',
            'sendstart' => 'end',
            'sendingcondition' => 'always',
        ], 2);
        $this->requestid = $this->create_template('Request opened', 'onrequestcreated', [
            'recipientrole' => [],
            'carboncopyrole' => [],
            'senddirection' => 'after',
            'senddays' => '0',
            'timeunit' => 'hours',
            'sendstart' => '',
            'sendstartrequest' => 'onrequestcreated',
        ], 1);

        $this->ruleid = $generator->create_rule([
            'name' => 'Data protection basics',
            'unitid' => 1,
            'messages' => [$this->reminderid],
        ]);

        $this->contextid = (int)context_system::instance()->id;
    }

    /**
     * Teardown singletons.
     */
    protected function tearDown(): void {
        $this->getDataGenerator()->get_plugin_generator('local_taskflow')->teardown();
        parent::tearDown();
    }

    /**
     * Insert one message template row.
     *
     * @param string $name
     * @param string $class
     * @param array $sending
     * @param int $priority
     * @return int
     */
    private function create_template(string $name, string $class, array $sending, int $priority): int {
        global $DB;

        return (int)$DB->insert_record('local_taskflow_messages', (object)[
            'name' => $name,
            'class' => $class,
            'message' => json_encode(['heading' => $name . ' subject', 'body' => '<p>Hello</p>']),
            'priority' => $priority,
            'sending_settings' => json_encode($sending),
            'usermodified' => 2,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Execute the skill as the current user after a passing preflight.
     *
     * @param array $input
     * @return array
     */
    private function run_skill(array $input): array {
        global $USER;
        $skill = new search_message_templates_skill();
        $preflight = $skill->preflight($input, $this->contextid, (int)$USER->id);
        $this->assertSame('pass', $preflight->status);
        return $skill->execute($preflight->preparedinput, $this->contextid, (int)$USER->id);
    }

    /**
     * Contract: name, read-only R0, no hard native capability, system scope.
     */
    public function test_contract(): void {
        $skill = new search_message_templates_skill();
        $this->assertSame('local_taskflow.search_message_templates', $skill->get_name());
        $this->assertTrue($skill->is_read_only());
        $this->assertSame([], $skill->get_required_native_capabilities());

        $schema = $skill->get_schema();
        $this->assertSame(['system'], $schema['prompt_meta']['context_scopes']);
        $this->assertSame(
            ['standard', 'request', 'chat'],
            $schema['properties']['type']['enum']
        );
    }

    /**
     * Every template is returned with recipients, CC, timing, package and rule usage.
     */
    public function test_lists_templates_with_usage(): void {
        $result = $this->run_skill([]);

        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status']);
        $this->assertSame(2, $result['total']);
        $this->assertCount(2, $result['templates']);

        $bykey = [];
        foreach ($result['templates'] as $template) {
            $bykey[(int)$template['id']] = $template;
        }

        $reminder = $bykey[$this->reminderid];
        $this->assertSame('Reminder 7 days', $reminder['name']);
        $this->assertSame('standard', $reminder['type']);
        $this->assertSame('standard', $reminder['class']);
        $this->assertSame(['assignee'], $reminder['recipients']);
        $this->assertSame(['supervisor'], $reminder['cc']);
        $this->assertSame('before', $reminder['timing']['senddirection']);
        $this->assertSame('7', $reminder['timing']['senddays']);
        $this->assertSame('end', $reminder['timing']['sendstart']);
        $this->assertStringContainsString('7', $reminder['timing_label']);
        $this->assertSame(2, $reminder['priority']);
        $this->assertSame([], $reminder['package']);
        $this->assertCount(1, $reminder['used_in_rules']);
        $this->assertSame($this->ruleid, $reminder['used_in_rules'][0]['id']);
        $this->assertSame('Data protection basics', $reminder['used_in_rules'][0]['name']);

        $request = $bykey[$this->requestid];
        $this->assertSame('request', $request['type']);
        $this->assertSame('onrequestcreated', $request['class']);
        $this->assertSame([], $request['used_in_rules']);

        $this->assertSame(taskflow_preview_renderer_factory::TYPE_MESSAGE_TEMPLATE_LIST, $result['preview']['type']);
        $this->assertSame(
            [$this->reminderid, $this->requestid],
            $result['preview']['payload']['messageids']
        );
    }

    /**
     * The type filter maps persisted classes to the form level types.
     */
    public function test_type_filter(): void {
        $result = $this->run_skill(['type' => 'request']);
        $this->assertCount(1, $result['templates']);
        $this->assertSame($this->requestid, $result['templates'][0]['id']);

        $result = $this->run_skill(['type' => 'standard']);
        $this->assertCount(1, $result['templates']);
        $this->assertSame($this->reminderid, $result['templates'][0]['id']);
    }

    /**
     * The name query matches a substring; the id query matches the id.
     */
    public function test_query(): void {
        $result = $this->run_skill(['query' => 'reminder']);
        $this->assertCount(1, $result['templates']);
        $this->assertSame($this->reminderid, $result['templates'][0]['id']);

        $result = $this->run_skill(['query' => (string)$this->requestid]);
        $this->assertCount(1, $result['templates']);
        $this->assertSame($this->requestid, $result['templates'][0]['id']);

        $result = $this->run_skill(['query' => 'nothing matches this']);
        $this->assertSame([], $result['templates']);
        $this->assertSame(
            get_string('agent_search_message_templates_none', 'local_taskflow'),
            $result['usermessage']
        );
    }

    /**
     * An unknown type is rejected in the preflight with the dedicated issue code.
     */
    public function test_invalid_type_is_rejected(): void {
        global $USER;

        $skill = new search_message_templates_skill();
        $preflight = $skill->preflight(['type' => 'carrier pigeon'], $this->contextid, (int)$USER->id);

        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(
            search_message_templates_skill::ISSUE_INVALID_MESSAGETYPE,
            $preflight->issuecodes
        );
    }

    /**
     * A user with neither editmessages nor viewrules is denied.
     */
    public function test_scope_denied(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $skill = new search_message_templates_skill();
        $preflight = $skill->preflight([], $this->contextid, (int)$user->id);

        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(taskflow_skill_base::ISSUE_SCOPE_DENIED, $preflight->issuecodes);

        $result = $skill->execute([], $this->contextid, (int)$user->id);
        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $result['status']);
        $this->assertSame([taskflow_skill_base::ISSUE_SCOPE_DENIED], $result['issue_codes']);
    }
}
