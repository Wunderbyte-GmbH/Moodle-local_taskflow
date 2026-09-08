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
use core\task\manager;
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\skills\get_rule_details_skill;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use local_taskflow\task\update_rule;
use local_taskflow\wizard\local_wizard_dependency;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../local_wizard_dependency.php');

/**
 * Skill local_taskflow.get_rule_details.
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\skills\get_rule_details_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class get_rule_details_skill_test extends advanced_testcase {
    /** @var int Rule under test. */
    private int $ruleid = 0;

    /** @var int Cohort used as organisational unit. */
    private int $cohortid = 0;

    /** @var int Course target id. */
    private int $courseid = 0;

    /** @var int Message template id. */
    private int $messageid = 0;

    /** @var int Assignee. */
    private int $userid = 0;

    /** @var int Booking option target id (0 when mod_booking is unavailable). */
    private int $optionid = 0;

    /** @var int System context id. */
    private int $contextid = 0;

    /**
     * Setup: engine, standard adapter config, one fully configured rule with an assignment.
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

        /** @var \local_taskflow_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_taskflow');
        $generator->set_config_values('standard');

        $cohort = $this->getDataGenerator()->create_cohort(['name' => 'Administration']);
        $this->cohortid = (int)$cohort->id;
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Data protection course', 'enablecompletion' => 1]);
        $this->courseid = (int)$course->id;
        $user = $this->getDataGenerator()->create_user();
        $this->userid = (int)$user->id;

        $this->messageid = (int)$DB->insert_record('local_taskflow_messages', (object)[
            'name' => 'Reminder 7d',
            'class' => 'standard',
            'message' => json_encode(['heading' => 'Reminder', 'body' => '<p>Due soon</p>']),
            'priority' => 1,
            'sending_settings' => '{}',
            'usermodified' => 2,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $targets = [
            ['targettype' => 'moodlecourse', 'targetid' => $this->courseid, 'completebeforenext' => 1],
            ['targettype' => 'competency', 'targetid' => 999, 'targetname' => 'Stored competency name'],
        ];
        $this->optionid = $this->create_booking_option($generator, $user);
        if ($this->optionid > 0) {
            $targets[] = ['targettype' => 'bookingoption', 'targetid' => $this->optionid];
        }

        $this->ruleid = $generator->create_rule([
            'name' => 'Data protection basics',
            'description' => 'Mandatory for all staff',
            'unitid' => $this->cohortid,
            'duedatetype' => 'duration',
            'duration' => 90 * DAYSECS,
            'extensionperiod' => 14 * DAYSECS,
            'cyclic' => 1,
            'cyclicduration' => 365 * DAYSECS,
            'inheritance' => 1,
            'recursive' => 0,
            'activationdelay' => 0,
            'filters' => [['userprofilefield' => 'contract', 'operator' => 'not_equals', 'value' => 'external']],
            'targets' => $targets,
            'messages' => [$this->messageid, 123456],
            'requests' => [
                'receiver_allowselfnotrelevant' => '0',
                'receiver_allowselfextension' => 'not_allowed',
                'receiver_allowuploadevidence' => '1',
            ],
        ]);
        $generator->create_user_assignment($this->userid, $this->ruleid);

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
     * Create a booking option when mod_booking is installed; 0 otherwise.
     *
     * @param \local_taskflow_generator $generator
     * @param \stdClass $manager
     * @return int
     */
    private function create_booking_option(\local_taskflow_generator $generator, \stdClass $manager): int {
        if (!class_exists('\mod_booking\singleton_service') || !\core_component::get_component_directory('mod_booking')) {
            return 0;
        }
        try {
            $options = $generator->create_booking_options($this, $this->courseid, $manager, 1, [], [
                'text' => 'Fire safety webinar',
            ]);
        } catch (\Throwable $e) {
            return 0;
        }
        return (int)(reset($options)->id ?? 0);
    }

    /**
     * Execute the skill as the current user after a passing preflight.
     *
     * @param array $input
     * @return array
     */
    private function run_skill(array $input): array {
        global $USER;
        $skill = new get_rule_details_skill();
        $preflight = $skill->preflight($input, $this->contextid, (int)$USER->id);
        $this->assertSame('pass', $preflight->status);
        return $skill->execute($preflight->preparedinput, $this->contextid, (int)$USER->id);
    }

    /**
     * Contract: name, read-only R0, native capability, required ruleid, system scope.
     */
    public function test_contract(): void {
        $skill = new get_rule_details_skill();
        $this->assertSame('local_taskflow.get_rule_details', $skill->get_name());
        $this->assertTrue($skill->is_read_only());
        $this->assertSame(['local/taskflow:viewrules'], $skill->get_required_native_capabilities());

        $schema = $skill->get_schema();
        $this->assertTrue($schema['properties']['ruleid']['required']);
        $this->assertSame(['ruleid'], $schema['prompt_meta']['input_fields_for_prompt']);
        $this->assertSame(['ruleid'], $schema['prompt_meta']['anchor_fields']);
        $this->assertSame(['system'], $schema['prompt_meta']['context_scopes']);
        $this->assertSame(['ruleid' => 17], $skill->get_example_input());
    }

    /**
     * The payload carries every rule field, filters, targets with names, messages, requests, stats and links.
     */
    public function test_details_payload(): void {
        $result = $this->run_skill(['ruleid' => (string)$this->ruleid]);

        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status']);
        $this->assertSame($this->ruleid, $result['resultid']);

        $rule = $result['rule'];
        $this->assertSame($this->ruleid, $rule['id']);
        $this->assertSame('Data protection basics', $rule['name']);
        $this->assertSame('Mandatory for all staff', $rule['description']);
        $this->assertSame('unit', $rule['type']);
        $this->assertSame($this->cohortid, $rule['unitid']);
        $this->assertSame('Administration', $rule['unitname']);
        $this->assertTrue($rule['enabled']);
        $this->assertTrue($rule['isactive']);
        $this->assertSame('duration', $rule['duedatetype']);
        $this->assertSame(90 * DAYSECS, $rule['duration']);
        $this->assertSame(0, $rule['fixeddate']);
        $this->assertSame(14 * DAYSECS, $rule['extensionperiod']);
        $this->assertSame(0, $rule['activationdelay']);
        $this->assertTrue($rule['cyclicvalidation']);
        $this->assertSame(365 * DAYSECS, $rule['cyclicduration']);
        $this->assertTrue($rule['inheritance']);
        $this->assertFalse($rule['recursive']);

        $this->assertCount(1, $result['filters']);
        $filter = $result['filters'][0];
        $this->assertSame('user_profile_field', $filter['filtertype']);
        $this->assertSame('contract', $filter['field']);
        $this->assertSame('not_equals', $filter['operator']);
        $this->assertSame(get_string('operator:equalsnot', 'local_taskflow'), $filter['operator_label']);
        $this->assertSame('external', $filter['value']);

        $targets = array_column($result['targets'], null, 'targettype');
        $this->assertCount($this->optionid > 0 ? 3 : 2, $result['targets']);
        $this->assertSame($this->courseid, $targets['moodlecourse']['targetid']);
        $this->assertSame('Data protection course', $targets['moodlecourse']['name']);
        $this->assertTrue($targets['moodlecourse']['completebeforenext']);
        $this->assertSame('enroll', $targets['moodlecourse']['actiontype']);
        $this->assertSame('Stored competency name', $targets['competency']['name']);
        $this->assertFalse($targets['competency']['completebeforenext']);
        if ($this->optionid > 0) {
            $this->assertSame($this->optionid, $targets['bookingoption']['targetid']);
            $this->assertSame('Fire safety webinar', $targets['bookingoption']['name']);
        }

        $this->assertCount(2, $result['messages']);
        $this->assertSame(
            ['id' => $this->messageid, 'name' => 'Reminder 7d', 'class' => 'standard', 'exists' => true],
            $result['messages'][0]
        );
        $this->assertSame(123456, $result['messages'][1]['id']);
        $this->assertFalse($result['messages'][1]['exists']);

        $this->assertSame([
            'allowselfextension' => get_rule_details_skill::RECEIVER_NOT_ALLOWED,
            'allowselfnotrelevant' => get_rule_details_skill::RECEIVER_SUPERVISOR,
            'allowuploadevidence' => get_rule_details_skill::RECEIVER_HR,
        ], $result['requests']);

        $this->assertSame(1, $result['assignments_total']);
        $this->assertCount(1, $result['assignments_by_status']);
        $status = $result['assignments_by_status'][0];
        $this->assertSame(1, $status['count']);
        $this->assertSame(assignment_status_facade::get_specific_names($status['status']), $status['label']);

        $this->assertSame(0, $result['pending_update_rule_tasks']);

        $this->assertStringEndsWith('/local/taskflow/editrule.php?id=' . $this->ruleid, $result['links']['page']);
        $this->assertStringEndsWith('/local/taskflow/index.php', $result['links']['dashboard']);
        $this->assertGreaterThanOrEqual(6, count($result['links']['docs']));
        foreach ($result['links']['docs'] as $doc) {
            $this->assertStringContainsString('/local/taskflow/documentation.php?file=', $doc);
        }

        $this->assertStringContainsString('Data protection basics', $result['usermessage']);
        $this->assertStringContainsString('Rule payload (JSON)', $result['observation_full']);
        $this->assertSame(taskflow_preview_renderer_factory::TYPE_RULE, $result['preview']['type']);
        $this->assertSame(['ruleids' => [$this->ruleid]], $result['preview']['payload']);
    }

    /**
     * A queued update_rule adhoc task for this rule is counted; tasks of other rules are not.
     */
    public function test_pending_update_rule_tasks(): void {
        global $DB;

        $row = $DB->get_record('local_taskflow_rules', ['id' => $this->ruleid]);
        $task = new update_rule();
        $task->set_custom_data($row);
        manager::queue_adhoc_task($task);

        $other = new update_rule();
        $other->set_custom_data((object)['id' => $this->ruleid + 1000]);
        manager::queue_adhoc_task($other);

        $result = $this->run_skill(['ruleid' => $this->ruleid]);
        $this->assertSame(1, $result['pending_update_rule_tasks']);
    }

    /**
     * Unknown rule ids and missing ids are rejected in preflight with issue codes.
     */
    public function test_not_found_and_missing_ruleid(): void {
        global $USER;
        $skill = new get_rule_details_skill();

        $preflight = $skill->preflight(['ruleid' => $this->ruleid + 1000], $this->contextid, (int)$USER->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(taskflow_skill_base::ISSUE_RULE_NOT_FOUND, $preflight->issuecodes);
        $this->assertSame('ruleid', $preflight->issues[0]['field']);

        $preflight = $skill->preflight([], $this->contextid, (int)$USER->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains('VALIDATION_ERROR', $preflight->issuecodes);

        $result = $skill->execute(['ruleid' => $this->ruleid + 1000], $this->contextid, (int)$USER->id);
        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $result['status']);
        $this->assertSame([taskflow_skill_base::ISSUE_RULE_NOT_FOUND], $result['issue_codes']);
    }

    /**
     * Without local/taskflow:viewrules preflight is invalid and execute returns an error.
     */
    public function test_requires_viewrules_capability(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $skill = new get_rule_details_skill();

        $preflight = $skill->preflight(['ruleid' => $this->ruleid], $this->contextid, (int)$user->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(taskflow_skill_base::ISSUE_SCOPE_DENIED, $preflight->issuecodes);

        $result = $skill->execute(['ruleid' => $this->ruleid], $this->contextid, (int)$user->id);
        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $result['status']);
        $this->assertArrayNotHasKey('rule', $result);
    }

    /**
     * The declared preview renders into a taskflow_rule card naming rule, unit and targets.
     */
    public function test_result_preview(): void {
        global $USER;
        $result = $this->run_skill(['ruleid' => $this->ruleid]);
        $preview = (new get_rule_details_skill())->get_result_preview($result, $this->contextid, (int)$USER->id);

        $this->assertNotNull($preview);
        $this->assertSame(taskflow_preview_renderer_factory::TYPE_RULE, $preview['type']);
        $this->assertStringContainsString('Data protection basics', $preview['html']);
        $this->assertStringContainsString('Administration', $preview['html']);
        $this->assertStringContainsString('Data protection course', $preview['html']);
        $this->assertStringContainsString('editrule.php?id=' . $this->ruleid, $preview['html']);
        $this->assertSame(['ruleids' => [$this->ruleid]], $preview['payload']);
    }
}
