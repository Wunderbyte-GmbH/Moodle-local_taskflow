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
use local_taskflow\local\units\organisational_units\unit;
use local_taskflow\local\wizard\engine\skill_risk_class;
use local_taskflow\local\wizard\taskflow\skills\update_rule_skill;
use local_taskflow\local\wizard\taskflow\taskflow_rule_builder;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use local_taskflow\wizard\local_wizard_dependency;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../local_wizard_dependency.php');

/**
 * Skill local_taskflow.update_rule.
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\skills\update_rule_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class update_rule_skill_test extends advanced_testcase {
    /** @var \local_taskflow_generator Plugin generator. */
    private $generator;

    /** @var int Rule under test. */
    private int $ruleid = 0;

    /** @var int Organisational unit. */
    private int $unitid = 0;

    /** @var int First course target. */
    private int $courseid = 0;

    /** @var int Second course, used for add/remove target tests. */
    private int $secondcourseid = 0;

    /** @var int Message template attached to the rule. */
    private int $messageid = 0;

    /** @var int Second message template. */
    private int $secondmessageid = 0;

    /** @var int Unit member. */
    private int $memberid = 0;

    /** @var int System context id. */
    private int $contextid = 0;

    /**
     * Setup: engine, one fully configured rule with a filter, a target and a message template.
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
        $this->generator->set_config_values('standard', ['organisational_unit_option' => 'unit']);
        set_config('allowselfnotrelevant', 1, 'local_taskflow');
        $fields = $this->generator->create_custom_profile_fields(['contract']);

        $this->courseid = (int)$this->getDataGenerator()->create_course(['fullname' => 'Data protection course'])->id;
        $this->secondcourseid = (int)$this->getDataGenerator()->create_course(['fullname' => 'Refresher course'])->id;

        $member = $this->getDataGenerator()->create_user(['firstname' => 'Anna', 'lastname' => 'Muster']);
        $this->memberid = (int)$member->id;
        $DB->insert_record('user_info_data', (object)[
            'userid' => $this->memberid,
            'fieldid' => $fields['contract'],
            'data' => 'internal',
            'dataformat' => 0,
        ]);

        $this->unitid = (int)unit::create_unit((object)['name' => 'Administration'])->get_id();
        $DB->insert_record('local_taskflow_unit_members', (object)[
            'unitid' => $this->unitid,
            'userid' => $this->memberid,
            'active' => 1,
            'timeadded' => time(),
            'timemodified' => time(),
            'usermodified' => 0,
        ]);

        $this->messageid = $this->create_template('Reminder 7d');
        $this->secondmessageid = $this->create_template('Overdue mail');

        $this->ruleid = (int)$this->generator->create_rule([
            'name' => 'Data protection basics',
            'description' => 'Mandatory for all staff',
            'unitid' => $this->unitid,
            'duedatetype' => 'duration',
            'duration' => 90 * DAYSECS,
            'extensionperiod' => 14 * DAYSECS,
            'filters' => [[
                'filtertype' => 'user_profile_field',
                'userprofilefield' => 'contract',
                'operator' => 'not_equals',
                'value' => 'external',
            ]],
            'targets' => [['targettype' => 'moodlecourse', 'targetid' => $this->courseid, 'completebeforenext' => 1]],
            'messages' => [$this->messageid],
            'requests' => ['receiver_allowselfnotrelevant' => '0'],
        ]);
        $this->generator->create_user_assignment($this->memberid, $this->ruleid);

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
     * Create a message template.
     *
     * @param string $name
     * @return int
     */
    private function create_template(string $name): int {
        global $DB;

        return (int)$DB->insert_record('local_taskflow_messages', (object)[
            'name' => $name,
            'class' => 'standard',
            'message' => json_encode(['heading' => $name, 'body' => '<p>Body</p>']),
            'priority' => 1,
            'sending_settings' => '{}',
            'usermodified' => 2,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Run preflight and execute as the current user.
     *
     * @param array $input
     * @return array
     */
    private function run_skill(array $input): array {
        global $USER;

        $skill = new update_rule_skill();
        $preflight = $skill->preflight($input, $this->contextid, (int)$USER->id);
        $this->assertSame('soft_block', $preflight->status);
        return $skill->execute($preflight->preparedinput, $this->contextid, (int)$USER->id);
    }

    /**
     * The stored rule document.
     *
     * @return array
     */
    private function stored_document(): array {
        global $DB;

        $row = $DB->get_record('local_taskflow_rules', ['id' => $this->ruleid], '*', MUST_EXIST);
        return json_decode($row->rulejson, true)['rulejson']['rule'];
    }

    /**
     * Contract: name, mutating R2, capability, required ruleid.
     */
    public function test_contract(): void {
        $skill = new update_rule_skill();
        $this->assertSame('local_taskflow.update_rule', $skill->get_name());
        $this->assertFalse($skill->is_read_only());
        $this->assertSame(skill_risk_class::R2, $skill->get_risk_class());
        $this->assertSame(['local/taskflow:createrules'], $skill->get_required_native_capabilities());

        $schema = $skill->get_schema();
        $this->assertTrue($schema['properties']['ruleid']['required']);
        $this->assertSame(['ruleid'], $schema['prompt_meta']['anchor_fields']);
        $this->assertSame(['system'], $schema['prompt_meta']['context_scopes']);
    }

    /**
     * The preview shows old → new for every changed field plus the propagation warning.
     */
    public function test_proposal_shows_old_and_new(): void {
        global $USER;

        $skill = new update_rule_skill();
        $input = ['ruleid' => $this->ruleid, 'duration' => 120 * DAYSECS, 'name' => 'Data protection advanced'];
        $preflight = $skill->preflight($input, $this->contextid, (int)$USER->id);
        $this->assertSame('soft_block', $preflight->status);
        $this->assertContains(update_rule_skill::ISSUE_CONFIRM_REQUIRED, $preflight->issuecodes);

        $proposal = $skill->describe_proposed_action($preflight->preparedinput);
        $this->assertIsArray($proposal);
        $this->assertStringContainsString('#' . $this->ruleid, $proposal['title']);

        $rows = array_column($proposal['rows'], 'value', 'label');
        $this->assertStringContainsString('→', $rows[get_string('name')]);
        $this->assertStringContainsString('Data protection basics', $rows[get_string('name')]);
        $this->assertStringContainsString('Data protection advanced', $rows[get_string('name')]);
        $this->assertStringContainsString('→', $rows[get_string('agent_rule_row_duedate', 'local_taskflow')]);
        $this->assertSame('1', $rows[get_string('agent_rule_row_assignments', 'local_taskflow')]);
        $this->assertStringContainsString(
            'not recursive',
            $rows[get_string('agent_rule_row_warning', 'local_taskflow')]
        );
    }

    /**
     * Only the given fields change; filters, targets, messages and requests are kept.
     */
    public function test_updates_only_given_fields(): void {
        global $DB;

        $result = $this->run_skill(['ruleid' => $this->ruleid, 'duration' => 120 * DAYSECS]);
        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status']);
        $this->assertSame([ 'duedatetype' ], $result['changed_fields']);

        $document = $this->stored_document();
        $this->assertSame(120 * DAYSECS, (int)$document['duration']);
        $this->assertSame('Data protection basics', $document['name']);
        $this->assertSame('Mandatory for all staff', $document['description']);
        $this->assertSame(14 * DAYSECS, (int)$document['extensionperiod']);
        $this->assertCount(1, $document['filter']);
        $this->assertSame('not_equals', $document['filter'][0]['operator']);
        $this->assertSame('contract', $document['filter'][0]['userprofilefield']);
        $this->assertCount(1, $document['actions'][0]['targets']);
        $this->assertSame($this->courseid, (int)$document['actions'][0]['targets'][0]['targetid']);
        $this->assertSame(1, (int)$document['actions'][0]['targets'][0]['completebeforenext']);
        $this->assertSame([['messageid' => $this->messageid]], $document['actions'][0]['messages']);
        $this->assertSame('0', (string)$document['actions'][0]['requests']['receiver_allowselfnotrelevant']);

        $this->assertSame(1, (int)$DB->get_field('local_taskflow_rules', 'isactive', ['id' => $this->ruleid]));
        $this->assertSame(taskflow_skill_base::STATUS_QUEUED, $result['queued_effects'][0]['status']);
        $this->assertSame(1, (int)$result['assignments_total']);
    }

    /**
     * Targets and message templates can be added and removed incrementally.
     */
    public function test_add_and_remove_targets_and_messages(): void {
        $result = $this->run_skill([
            'ruleid' => $this->ruleid,
            'addtargets' => [['targettype' => 'moodlecourse', 'targetid' => $this->secondcourseid]],
            'addmessageids' => [$this->secondmessageid],
            'removemessageids' => [$this->messageid],
        ]);
        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status']);

        $document = $this->stored_document();
        $targetids = array_map('intval', array_column($document['actions'][0]['targets'], 'targetid'));
        $this->assertSame([$this->courseid, $this->secondcourseid], $targetids);
        $this->assertSame([['messageid' => $this->secondmessageid]], $document['actions'][0]['messages']);

        $result = $this->run_skill([
            'ruleid' => $this->ruleid,
            'removetargetids' => [$this->courseid],
        ]);
        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status']);
        $document = $this->stored_document();
        $this->assertSame(
            [$this->secondcourseid],
            array_map('intval', array_column($document['actions'][0]['targets'], 'targetid'))
        );
    }

    /**
     * A pure deactivation runs through the same persistence path and queues the propagation.
     */
    public function test_deactivation(): void {
        global $DB, $USER;

        $skill = new update_rule_skill();
        $input = ['ruleid' => $this->ruleid, 'isactive' => false];
        $preflight = $skill->preflight($input, $this->contextid, (int)$USER->id);
        $this->assertSame('soft_block', $preflight->status);

        $proposal = $skill->describe_proposed_action($preflight->preparedinput);
        $this->assertStringContainsString(
            get_string('agent_update_rule_title_deactivate', 'local_taskflow', (object)[
                'id' => $this->ruleid,
                'name' => 'Data protection basics',
            ]),
            $proposal['title']
        );

        $result = $skill->execute($preflight->preparedinput, $this->contextid, (int)$USER->id);
        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status']);
        $this->assertSame(['enabled'], $result['changed_fields']);
        $this->assertSame(0, (int)$DB->get_field('local_taskflow_rules', 'isactive', ['id' => $this->ruleid]));
        $this->assertSame(0, (int)$this->stored_document()['enabled']);

        // Reactivating works the same way.
        $result = $this->run_skill(['ruleid' => $this->ruleid, 'isactive' => true]);
        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status']);
        $this->assertSame(1, (int)$DB->get_field('local_taskflow_rules', 'isactive', ['id' => $this->ruleid]));
    }

    /**
     * Input that changes nothing is rejected instead of writing a pointless revision.
     */
    public function test_no_changes_is_rejected(): void {
        global $USER;

        $preflight = (new update_rule_skill())->preflight(
            ['ruleid' => $this->ruleid, 'duration' => 90 * DAYSECS],
            $this->contextid,
            (int)$USER->id
        );
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(update_rule_skill::ISSUE_NO_CHANGES, $preflight->issuecodes);
    }

    /**
     * Invalid values and unknown rules are rejected; the stored rule stays untouched.
     */
    public function test_invalid_input_leaves_rule_untouched(): void {
        global $DB, $USER;

        $before = $DB->get_field('local_taskflow_rules', 'rulejson', ['id' => $this->ruleid]);

        $preflight = (new update_rule_skill())->preflight([
            'ruleid' => $this->ruleid,
            'addtargets' => [['targettype' => 'moodlecourse', 'targetid' => $this->courseid + 100000]],
        ], $this->contextid, (int)$USER->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(taskflow_rule_builder::ISSUE_TARGET_NOT_FOUND, $preflight->issuecodes);
        $this->assertSame('addtargets[0].targetid', $preflight->issues[0]['field']);

        $preflight = (new update_rule_skill())->preflight(
            ['ruleid' => $this->ruleid + 5000],
            $this->contextid,
            (int)$USER->id
        );
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(taskflow_skill_base::ISSUE_RULE_NOT_FOUND, $preflight->issuecodes);

        $this->assertSame($before, $DB->get_field('local_taskflow_rules', 'rulejson', ['id' => $this->ruleid]));
    }

    /**
     * Without local/taskflow:createrules preflight blocks and execute returns an error.
     */
    public function test_requires_createrules_capability(): void {
        global $DB;

        $before = $DB->get_field('local_taskflow_rules', 'rulejson', ['id' => $this->ruleid]);
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $skill = new update_rule_skill();
        $preflight = $skill->preflight(['ruleid' => $this->ruleid, 'name' => 'Hijacked'], $this->contextid, (int)$user->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(taskflow_skill_base::ISSUE_SCOPE_DENIED, $preflight->issuecodes);

        $result = $skill->execute(['ruleid' => $this->ruleid, 'name' => 'Hijacked'], $this->contextid, (int)$user->id);
        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $result['status']);
        $this->assertSame($before, $DB->get_field('local_taskflow_rules', 'rulejson', ['id' => $this->ruleid]));
    }
}
