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
use local_taskflow\local\wizard\taskflow\skills\create_rule_skill;
use local_taskflow\local\wizard\taskflow\skills\get_rule_details_skill;
use local_taskflow\local\wizard\taskflow\taskflow_rule_builder;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use local_taskflow\wizard\local_wizard_dependency;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../local_wizard_dependency.php');

/**
 * Skill local_taskflow.create_rule.
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\skills\create_rule_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class create_rule_skill_test extends advanced_testcase {
    /** @var \local_taskflow_generator Plugin generator. */
    private $generator;

    /** @var int Organisational unit of the rule. */
    private int $unitid = 0;

    /** @var int Course used as target. */
    private int $courseid = 0;

    /** @var int Message template id. */
    private int $messageid = 0;

    /** @var int Member matching the filter. */
    private int $matchinguser = 0;

    /** @var int Member not matching the filter. */
    private int $othermember = 0;

    /** @var int System context id. */
    private int $contextid = 0;

    /**
     * Setup: engine, standard adapter config, one unit with two members and a course target.
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

        $course = $this->getDataGenerator()->create_course(['fullname' => 'Data protection course']);
        $this->courseid = (int)$course->id;

        $matching = $this->getDataGenerator()->create_user(['firstname' => 'Anna', 'lastname' => 'Muster']);
        $other = $this->getDataGenerator()->create_user(['firstname' => 'Bert', 'lastname' => 'Beispiel']);
        $this->matchinguser = (int)$matching->id;
        $this->othermember = (int)$other->id;
        $DB->insert_record('user_info_data', (object)[
            'userid' => $this->matchinguser,
            'fieldid' => $fields['contract'],
            'data' => 'internal',
            'dataformat' => 0,
        ]);
        $DB->insert_record('user_info_data', (object)[
            'userid' => $this->othermember,
            'fieldid' => $fields['contract'],
            'data' => 'external',
            'dataformat' => 0,
        ]);

        $this->unitid = (int)unit::create_unit((object)['name' => 'Administration'])->get_id();
        foreach ([$this->matchinguser, $this->othermember] as $memberid) {
            $DB->insert_record('local_taskflow_unit_members', (object)[
                'unitid' => $this->unitid,
                'userid' => $memberid,
                'active' => 1,
                'timeadded' => time(),
                'timemodified' => time(),
                'usermodified' => 0,
            ]);
        }

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
     * Valid input for a unit rule with one filter, one target and one message template.
     *
     * @param array $override
     * @return array
     */
    private function valid_input(array $override = []): array {
        return $override + [
            'name' => 'Data protection basics',
            'description' => 'Mandatory for all staff',
            'ruletype' => taskflow_rule_builder::RULETYPE_UNIT,
            'unitid' => $this->unitid,
            'duedatetype' => taskflow_rule_builder::DUEDATE_DURATION,
            'duration' => 90 * DAYSECS,
            'extensionperiod' => 14 * DAYSECS,
            'filters' => [[
                'filtertype' => 'user_profile_field',
                'field' => 'contract',
                'operator' => 'not_equals',
                'value' => 'external',
            ]],
            'targets' => [['targettype' => 'moodlecourse', 'targetid' => $this->courseid, 'completebeforenext' => true]],
            'messageids' => [$this->messageid],
            'requests' => ['allowselfnotrelevant' => taskflow_rule_builder::RECEIVER_SUPERVISOR],
        ];
    }

    /**
     * Contract: name, mutating R2, capability, required fields, system scope.
     */
    public function test_contract(): void {
        $skill = new create_rule_skill();
        $this->assertSame('local_taskflow.create_rule', $skill->get_name());
        $this->assertFalse($skill->is_read_only());
        $this->assertSame(skill_risk_class::R2, $skill->get_risk_class());
        $this->assertSame(['local/taskflow:createrules'], $skill->get_required_native_capabilities());

        $schema = $skill->get_schema();
        $this->assertTrue($schema['properties']['name']['required']);
        $this->assertTrue($schema['properties']['ruletype']['required']);
        $this->assertTrue($schema['properties']['duedatetype']['required']);
        $this->assertSame(['system'], $schema['prompt_meta']['context_scopes']);
        $this->assertSame([], $skill->get_contextual_prompt_packs());
    }

    /**
     * Preflight ends in a confirmation whose tier-3 preview lists the rule and the dry run.
     */
    public function test_confirmable_proposal_with_dryrun(): void {
        global $USER;

        $skill = new create_rule_skill();
        $preflight = $skill->preflight($this->valid_input(), $this->contextid, (int)$USER->id);
        $this->assertSame('soft_block', $preflight->status);
        $this->assertContains(create_rule_skill::ISSUE_CONFIRM_REQUIRED, $preflight->issuecodes);

        $proposal = $skill->describe_proposed_action($preflight->preparedinput);
        $this->assertIsArray($proposal);
        $this->assertStringContainsString('Data protection basics', $proposal['title']);
        $this->assertStringContainsString(create_rule_skill::UPDATE_RULE_TASK, $proposal['summary']);

        $rows = array_column($proposal['rows'], 'value', 'label');
        $this->assertArrayHasKey(get_string('agent_rule_row_targetgroup', 'local_taskflow'), $rows);
        $this->assertSame('Administration', $rows[get_string('agent_rule_row_targetgroup', 'local_taskflow')]);
        $this->assertStringContainsString(
            'Data protection course',
            $rows[get_string('agent_rule_row_targets', 'local_taskflow')]
        );
        $this->assertStringContainsString(
            'contract',
            $rows[get_string('agent_rule_row_filters', 'local_taskflow')]
        );
        $this->assertStringContainsString(
            'Reminder 7d',
            $rows[get_string('agent_rule_row_messages', 'local_taskflow')]
        );

        // Dry run: only Anna Muster (contract != external) matches; both members were scanned.
        $affected = $rows[get_string('agent_rule_row_affected', 'local_taskflow')];
        $this->assertSame(
            get_string('agent_rule_affected_users', 'local_taskflow', (object)['matched' => 1, 'scanned' => 2]),
            $affected
        );

        // Nothing was written during the preflight.
        $this->assertSame(0, $GLOBALS['DB']->count_records('local_taskflow_rules'));
    }

    /**
     * The created rule is stored through the form path and readable with get_rule_details semantics.
     */
    public function test_creates_rule_and_queues_rollout(): void {
        global $DB, $USER;

        $skill = new create_rule_skill();
        $preflight = $skill->preflight($this->valid_input(), $this->contextid, (int)$USER->id);
        $result = $skill->execute($preflight->preparedinput, $this->contextid, (int)$USER->id);

        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status']);
        $ruleid = (int)$result['resultid'];
        $this->assertGreaterThan(0, $ruleid);

        $row = $DB->get_record('local_taskflow_rules', ['id' => $ruleid], '*', MUST_EXIST);
        $this->assertSame('Data protection basics', $row->rulename);
        $this->assertSame($this->unitid, (int)$row->unitid);
        $this->assertSame(0, (int)$row->userid);
        $this->assertSame(1, (int)$row->isactive);

        $document = json_decode($row->rulejson, true)['rulejson']['rule'];
        $this->assertSame('Data protection basics', $document['name']);
        $this->assertSame('duration', $document['duedatetype']);
        $this->assertSame(90 * DAYSECS, (int)$document['duration']);
        $this->assertSame(14 * DAYSECS, (int)$document['extensionperiod']);
        $this->assertCount(1, $document['filter']);
        $this->assertSame('user_profile_field', $document['filter'][0]['filtertype']);
        $this->assertSame('contract', $document['filter'][0]['userprofilefield']);
        $this->assertSame('not_equals', $document['filter'][0]['operator']);
        $this->assertSame('external', $document['filter'][0]['value']);
        $this->assertSame('role', $document['filter'][0]['key']);
        $this->assertCount(1, $document['actions'][0]['targets']);
        $this->assertSame('moodlecourse', $document['actions'][0]['targets'][0]['targettype']);
        $this->assertSame($this->courseid, (int)$document['actions'][0]['targets'][0]['targetid']);
        $this->assertSame('Data protection course', $document['actions'][0]['targets'][0]['targetname']);
        $this->assertSame('enroll', $document['actions'][0]['targets'][0]['actiontype']);
        $this->assertSame([['messageid' => $this->messageid]], $document['actions'][0]['messages']);
        $this->assertSame('0', (string)$document['actions'][0]['requests']['receiver_allowselfnotrelevant']);

        // Same view as get_rule_details.
        $details = (new get_rule_details_skill())->execute(['ruleid' => $ruleid], $this->contextid, (int)$USER->id);
        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $details['status']);
        $this->assertSame('Data protection basics', $details['rule']['name']);
        $this->assertSame($this->courseid, (int)$details['targets'][0]['targetid']);
        $this->assertSame('not_equals', $details['filters'][0]['operator']);

        // The roll-out is queued, not done.
        $this->assertSame(1, (int)$result['affected_users']['matched']);
        $this->assertSame(taskflow_skill_base::STATUS_QUEUED, $result['queued_effects'][0]['status']);
        $this->assertSame(create_rule_skill::UPDATE_RULE_TASK, $result['queued_effects'][0]['task']);
        $this->assertStringContainsString(create_rule_skill::UPDATE_RULE_TASK, $result['observation_full']);
        $this->assertSame(1, $this->queued_update_rule_tasks($ruleid));
        $this->assertSame(0, $DB->count_records('local_taskflow_assignment'));
        $this->assertSame(['ruleids' => [$ruleid]], $result['preview']['payload']);
    }

    /**
     * A personal rule stores the userid instead of the unit and has no filters.
     */
    public function test_creates_personal_rule(): void {
        global $DB, $USER;

        $input = [
            'name' => 'Personal onboarding',
            'ruletype' => taskflow_rule_builder::RULETYPE_USER,
            'userid' => $this->matchinguser,
            'duedatetype' => taskflow_rule_builder::DUEDATE_FIXEDDATE,
            'fixeddate' => 1893452400,
            'targets' => [['targettype' => 'moodlecourse', 'targetid' => $this->courseid]],
        ];
        $skill = new create_rule_skill();
        $preflight = $skill->preflight($input, $this->contextid, (int)$USER->id);
        $this->assertSame('soft_block', $preflight->status);
        $result = $skill->execute($preflight->preparedinput, $this->contextid, (int)$USER->id);

        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status']);
        $row = $DB->get_record('local_taskflow_rules', ['id' => (int)$result['resultid']], '*', MUST_EXIST);
        $this->assertSame($this->matchinguser, (int)$row->userid);
        $this->assertSame(0, (int)$row->unitid);
        $document = json_decode($row->rulejson, true)['rulejson']['rule'];
        $this->assertSame('fixeddate', $document['duedatetype']);
        $this->assertSame(1893452400, (int)$document['fixeddate']);
        $this->assertSame([], $document['filter']);
    }

    /**
     * An unknown operator is rejected in preflight, names the field and lists the valid values.
     */
    public function test_invalid_operator_is_rejected(): void {
        global $DB, $USER;

        $input = $this->valid_input(['filters' => [[
            'filtertype' => 'user_profile_field',
            'field' => 'contract',
            'operator' => 'is_definitely_not_an_operator',
            'value' => 'external',
        ]]]);

        $skill = new create_rule_skill();
        $preflight = $skill->preflight($input, $this->contextid, (int)$USER->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(taskflow_rule_builder::ISSUE_INVALID_VALUE, $preflight->issuecodes);
        $this->assertSame('filters[0].operator', $preflight->issues[0]['field']);
        $this->assertStringContainsString('not_equals', $preflight->issues[0]['message']);
        $this->assertSame(0, $DB->count_records('local_taskflow_rules'));
    }

    /**
     * An unknown target id is rejected and nothing is written.
     */
    public function test_unknown_target_is_rejected(): void {
        global $DB, $USER;

        $input = $this->valid_input(['targets' => [[
            'targettype' => 'moodlecourse',
            'targetid' => $this->courseid + 100000,
        ]]]);

        $skill = new create_rule_skill();
        $preflight = $skill->preflight($input, $this->contextid, (int)$USER->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(taskflow_rule_builder::ISSUE_TARGET_NOT_FOUND, $preflight->issuecodes);
        $this->assertSame('targets[0].targetid', $preflight->issues[0]['field']);
        $this->assertSame(0, $DB->count_records('local_taskflow_rules'));

        $result = $skill->execute($input, $this->contextid, (int)$USER->id);
        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $result['status']);
        $this->assertSame(0, $DB->count_records('local_taskflow_rules'));
    }

    /**
     * An unknown unit is rejected with the list of available units.
     */
    public function test_unknown_unit_is_rejected(): void {
        global $DB, $USER;

        $input = $this->valid_input(['unitid' => $this->unitid + 5000]);
        $preflight = (new create_rule_skill())->preflight($input, $this->contextid, (int)$USER->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(taskflow_rule_builder::ISSUE_UNIT_NOT_FOUND, $preflight->issuecodes);
        $this->assertSame('unitid', $preflight->issues[0]['field']);
        $this->assertSame(0, $DB->count_records('local_taskflow_rules'));
    }

    /**
     * Without local/taskflow:createrules preflight blocks and execute returns an error.
     */
    public function test_requires_createrules_capability(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $skill = new create_rule_skill();
        $preflight = $skill->preflight($this->valid_input(), $this->contextid, (int)$user->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(taskflow_skill_base::ISSUE_SCOPE_DENIED, $preflight->issuecodes);

        $result = $skill->execute($this->valid_input(), $this->contextid, (int)$user->id);
        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $result['status']);
        $this->assertSame([taskflow_skill_base::ISSUE_SCOPE_DENIED], $result['issue_codes']);
        $this->assertSame(0, $DB->count_records('local_taskflow_rules'));
    }

    /**
     * Number of queued update_rule adhoc tasks naming this rule.
     *
     * @param int $ruleid
     * @return int
     */
    private function queued_update_rule_tasks(int $ruleid): int {
        global $DB;

        $count = 0;
        foreach ($DB->get_records('task_adhoc') as $task) {
            if (strpos((string)$task->classname, 'update_rule') === false) {
                continue;
            }
            $data = json_decode((string)$task->customdata, true);
            if (is_array($data) && (int)($data['id'] ?? 0) === $ruleid) {
                $count++;
            }
        }
        return $count;
    }
}
