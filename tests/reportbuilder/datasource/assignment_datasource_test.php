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

declare(strict_types=1);

namespace local_taskflow\reportbuilder\datasource;

use core_reportbuilder_generator;
use core_reportbuilder\local\filters\boolean_select;
use core_reportbuilder\local\filters\date;
use core_reportbuilder\local\filters\number;
use core_reportbuilder\local\filters\select;
use core_reportbuilder\local\filters\text;
use core_reportbuilder\manager;
use core_reportbuilder\tests\core_reportbuilder_testcase;
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\reportbuilder\local\entities\assignment;
use local_taskflow\reportbuilder\local\entities\rule;
use local_taskflow\reportbuilder\local\filters\profile_field_current_user;
use local_taskflow\reportbuilder\local\filters\timestamp_years_past;
use local_taskflow_generator;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Assignment datasource tests.
 *
 * @package    local_taskflow
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <https://www.wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(assignment_datasource::class)]
#[CoversClass(assignment::class)]
#[CoversClass(rule::class)]
#[CoversClass(profile_field_current_user::class)]
#[CoversClass(timestamp_years_past::class)]
final class assignment_datasource_test extends core_reportbuilder_testcase {
    /**
     * Set up: supervisor profile field and standard adapter configuration.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        /** @var local_taskflow_generator $plugingenerator */
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingenerator->create_custom_profile_fields(['supervisor', 'units']);
        $plugingenerator->set_config_values();
    }

    /**
     * Create a rule record.
     *
     * @param string $rulename
     * @param int $isactive
     * @param int $unitid
     * @return int Rule ID
     */
    private function create_rule(string $rulename, int $isactive = 1, int $unitid = 1): int {
        global $DB;

        return $DB->insert_record('local_taskflow_rules', (object) [
            'rulename' => $rulename,
            'rulejson' => '{}',
            'isactive' => $isactive,
            'unitid' => $unitid,
        ]);
    }

    /**
     * Create an assignment record directly, bypassing events and status handling.
     *
     * @param array $data Overrides of the record fields
     * @return int Assignment ID
     */
    private function create_assignment(array $data): int {
        global $DB;

        $now = time();
        $record = (object) array_merge([
            'targets' => '[]',
            'messages' => '[]',
            'unitid' => 1,
            'active' => 1,
            'status' => 0,
            'assigneddate' => $now,
            'duedate' => $now + DAYSECS,
            'completeddate' => null,
            'usermodified' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
            'keepchanges' => 0,
            'overduecounter' => 0,
            'prolongedcounter' => 0,
        ], $data);

        return $DB->insert_record('local_taskflow_assignment', $record);
    }

    /**
     * Store the supervisor of a user in the supervisor profile field.
     *
     * @param int $userid
     * @param int $supervisorid
     */
    private function set_supervisor(int $userid, int $supervisorid): void {
        global $DB;

        $fieldid = $DB->get_field('user_info_field', 'id', ['shortname' => 'supervisor'], MUST_EXIST);
        if ($existing = $DB->get_record('user_info_data', ['userid' => $userid, 'fieldid' => $fieldid])) {
            $existing->data = (string) $supervisorid;
            $DB->update_record('user_info_data', $existing);
        } else {
            $DB->insert_record('user_info_data', (object) [
                'userid' => $userid,
                'fieldid' => $fieldid,
                'data' => (string) $supervisorid,
                'dataformat' => FORMAT_PLAIN,
            ]);
        }
    }

    /**
     * Encode a list of targets as stored on an assignment.
     *
     * @param array $targets Each entry [type, name, completionstatus]
     * @return string
     */
    private function encode_targets(array $targets): string {
        $encoded = [];
        foreach ($targets as $index => [$type, $name, $completed]) {
            $encoded[] = [
                'targetid' => (string) ($index + 1),
                'targettype' => $type,
                'targetname' => $name,
                'sortorder' => $index + 1,
                'actiontype' => 'enroll',
                'completebeforenext' => false,
                'completionstatus' => $completed,
            ];
        }
        return json_encode($encoded);
    }

    /**
     * Create a report over the datasource with the username as first (sorted)
     * column, followed by the given columns, filters and conditions.
     *
     * @param string[] $columns
     * @param string[] $filters
     * @param string[] $conditions
     * @return int Report ID
     */
    private function create_report(array $columns, array $filters = [], array $conditions = []): int {
        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');

        $report = $generator->create_report([
            'name' => 'Assignments',
            'source' => assignment_datasource::class,
            'default' => 0,
        ]);
        $reportid = (int) $report->get('id');

        $generator->create_column(['reportid' => $reportid, 'uniqueidentifier' => 'user:username', 'sortenabled' => 1]);
        foreach ($columns as $column) {
            $generator->create_column(['reportid' => $reportid, 'uniqueidentifier' => $column]);
        }
        foreach ($filters as $filter) {
            $generator->create_filter(['reportid' => $reportid, 'uniqueidentifier' => $filter]);
        }
        foreach ($conditions as $condition) {
            $generator->create_condition(['reportid' => $reportid, 'uniqueidentifier' => $condition]);
        }

        return $reportid;
    }

    /**
     * Return report content as rows of cell values.
     *
     * @param int $reportid
     * @param array $filtervalues
     * @return array[]
     */
    private function get_rows(int $reportid, array $filtervalues = []): array {
        return array_map('array_values', $this->get_custom_report_content($reportid, 30, $filtervalues));
    }

    /**
     * Apply condition values to a report.
     *
     * @param int $reportid
     * @param array $values
     */
    private function set_condition_values(int $reportid, array $values): void {
        $instance = manager::get_report_from_id($reportid);
        $instance->set_condition_values($values);
    }

    /**
     * Test the default report: default columns, sorting and the "active" condition.
     */
    public function test_datasource_default(): void {
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Regular', 'lastname' => 'User']);
        $ruleid = $this->create_rule('Safety training');

        $assigned = strtotime('2026-01-10 10:00');
        $due = strtotime('2026-03-01 10:00');
        $this->create_assignment([
            'userid' => $user->id,
            'ruleid' => $ruleid,
            'targets' => $this->encode_targets([['moodlecourse', 'Course A', 0]]),
            'assigneddate' => $assigned,
            'duedate' => $due,
            'status' => 0,
        ]);

        // Inactive assignment, excluded by the default "active" condition.
        $this->create_assignment([
            'userid' => $user->id,
            'ruleid' => $ruleid,
            'active' => 0,
        ]);

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $generator->create_report([
            'name' => 'Assignments',
            'source' => assignment_datasource::class,
            'default' => 1,
        ]);

        $content = $this->get_rows((int) $report->get('id'));

        $this->assertEquals([
            [
                fullname($user),
                'Safety training',
                'Course A',
                assignment_status_facade::get_specific_names(0),
                userdate($assigned),
                userdate($due),
            ],
        ], $content);
    }

    /**
     * Test that assignments are linked to their rules, including rule filters
     * and assignments whose rule no longer exists.
     */
    public function test_rule_linking(): void {
        global $DB;

        $user1 = $this->getDataGenerator()->create_user(['username' => 'user1']);
        $user2 = $this->getDataGenerator()->create_user(['username' => 'user2']);
        $user3 = $this->getDataGenerator()->create_user(['username' => 'user3']);

        $activerule = $this->create_rule('Active rule', 1, 5);
        $inactiverule = $this->create_rule('Inactive rule', 0, 6);
        $deletedrule = $this->create_rule('Deleted rule');

        $this->create_assignment(['userid' => $user1->id, 'ruleid' => $activerule]);
        $this->create_assignment(['userid' => $user2->id, 'ruleid' => $inactiverule]);
        $this->create_assignment(['userid' => $user3->id, 'ruleid' => $deletedrule]);
        $DB->delete_records('local_taskflow_rules', ['id' => $deletedrule]);

        $reportid = $this->create_report(
            ['rule:id', 'rule:rulename', 'rule:isactive', 'rule:unitid', 'assignment:ruleid'],
            ['rule:rulename', 'rule:isactive', 'rule:id', 'assignment:ruleid']
        );

        $this->assertEquals([
            ['user1', $activerule, 'Active rule', get_string('yes'), 5, $activerule],
            ['user2', $inactiverule, 'Inactive rule', get_string('no'), 6, $inactiverule],
            ['user3', '', '', '', '', $deletedrule],
        ], $this->get_rows($reportid));

        // Filter by rule name.
        $rows = $this->get_rows($reportid, [
            'rule:rulename_operator' => text::CONTAINS,
            'rule:rulename_value' => 'Inactive',
        ]);
        $this->assertEquals(['user2'], array_column($rows, 0));

        // Filter by active rules only.
        $rows = $this->get_rows($reportid, [
            'rule:isactive_operator' => boolean_select::CHECKED,
        ]);
        $this->assertEquals(['user1'], array_column($rows, 0));

        // Filter by the rule ID stored on the assignment (works for deleted rules too).
        $rows = $this->get_rows($reportid, [
            'assignment:ruleid_operator' => number::EQUAL_TO,
            'assignment:ruleid_value1' => $deletedrule,
        ]);
        $this->assertEquals(['user3'], array_column($rows, 0));
    }

    /**
     * Test the supervisor entity columns and the "supervisor is current user" condition.
     */
    public function test_supervisor(): void {
        $supervisora = $this->getDataGenerator()->create_user(['firstname' => 'Alice', 'lastname' => 'Boss']);
        $supervisorb = $this->getDataGenerator()->create_user(['firstname' => 'Bob', 'lastname' => 'Chief']);
        $user1 = $this->getDataGenerator()->create_user(['username' => 'user1']);
        $user2 = $this->getDataGenerator()->create_user(['username' => 'user2']);
        $user3 = $this->getDataGenerator()->create_user(['username' => 'user3']);
        $user4 = $this->getDataGenerator()->create_user(['username' => 'user4']);

        $this->set_supervisor((int) $user1->id, (int) $supervisora->id);
        $this->set_supervisor((int) $user2->id, (int) $supervisora->id);
        $this->set_supervisor((int) $user3->id, (int) $supervisorb->id);

        $ruleid = $this->create_rule('Rule');
        foreach ([$user1, $user2, $user3, $user4] as $user) {
            $this->create_assignment(['userid' => $user->id, 'ruleid' => $ruleid]);
        }

        $reportid = $this->create_report(
            ['supervisor:fullname', 'supervisor:email'],
            ['supervisor:fullname'],
            ['user:supervisor']
        );

        // Supervisor columns.
        $this->assertEquals([
            ['user1', 'Alice Boss', $supervisora->email],
            ['user2', 'Alice Boss', $supervisora->email],
            ['user3', 'Bob Chief', $supervisorb->email],
            ['user4', '', ''],
        ], $this->get_rows($reportid));

        // Supervisor filter, from the supervisor user entity.
        $rows = $this->get_rows($reportid, [
            'supervisor:fullname_operator' => text::CONTAINS,
            'supervisor:fullname_value' => 'Chief',
        ]);
        $this->assertEquals(['user3'], array_column($rows, 0));

        // Condition: supervisor is current user.
        $this->set_condition_values($reportid, [
            'user:supervisor_operator' => profile_field_current_user::CURRENT_USER,
        ]);
        $this->setUser($supervisora);
        $this->assertEquals(['user1', 'user2'], array_column($this->get_rows($reportid), 0));

        $this->setUser($supervisorb);
        $this->assertEquals(['user3'], array_column($this->get_rows($reportid), 0));

        $this->setUser($user4);
        $this->assertEquals([], $this->get_rows($reportid));

        // Condition: supervisor equals given user ID.
        $this->setAdminUser();
        $this->set_condition_values($reportid, [
            'user:supervisor_operator' => profile_field_current_user::IS_EQUAL_TO,
            'user:supervisor_value' => (string) $supervisorb->id,
        ]);
        $this->assertEquals(['user3'], array_column($this->get_rows($reportid), 0));

        // Empty value means no restriction.
        $this->set_condition_values($reportid, [
            'user:supervisor_operator' => profile_field_current_user::IS_EQUAL_TO,
            'user:supervisor_value' => ' ',
        ]);
        $this->assertCount(4, $this->get_rows($reportid));

        // Any value.
        $this->set_condition_values($reportid, [
            'user:supervisor_operator' => profile_field_current_user::ANYVALUE,
        ]);
        $this->assertCount(4, $this->get_rows($reportid));
    }

    /**
     * Test the supervisor entity and condition are absent when no supervisor field is configured.
     */
    public function test_supervisor_not_configured(): void {
        set_config(\local_taskflow\plugininfo\taskflowadapter::TRANSLATOR_USER_SUPERVISOR, '', 'taskflowadapter_standard');
        set_config('supervisor', '', 'taskflowadapter_standard');
        \cache_helper::invalidate_by_event('config', ['local_taskflow']);

        $this->assertSame(0, assignment_datasource::get_supervisor_field_id());

        $user = $this->getDataGenerator()->create_user(['username' => 'user1']);
        $this->create_assignment(['userid' => $user->id, 'ruleid' => $this->create_rule('Rule')]);

        $reportid = $this->create_report([]);
        $instance = manager::get_report_from_id($reportid);
        $this->assertArrayNotHasKey('supervisor:fullname', $instance->get_columns());
        $this->assertArrayNotHasKey('user:supervisor', $instance->get_conditions());

        $this->assertEquals([['user1']], $this->get_rows($reportid));
    }

    /**
     * Test the target columns and the targets filter.
     */
    public function test_targets(): void {
        $user1 = $this->getDataGenerator()->create_user(['username' => 'user1']);
        $user2 = $this->getDataGenerator()->create_user(['username' => 'user2']);
        $user3 = $this->getDataGenerator()->create_user(['username' => 'user3']);
        $ruleid = $this->create_rule('Rule');

        $this->create_assignment([
            'userid' => $user1->id,
            'ruleid' => $ruleid,
            'targets' => $this->encode_targets([
                ['moodlecourse', 'Course A', 1],
                ['bookingoption', 'Option <B>', 0],
            ]),
        ]);
        $this->create_assignment([
            'userid' => $user2->id,
            'ruleid' => $ruleid,
            'targets' => $this->encode_targets([['competency', 'Competency C', 0]]),
        ]);
        // No targets at all, and invalid JSON, must not break the report.
        $this->create_assignment(['userid' => $user3->id, 'ruleid' => $ruleid, 'targets' => 'not json']);

        $reportid = $this->create_report(['assignment:targets', 'assignment:targetnames'], ['assignment:targets']);
        $rows = $this->get_rows($reportid);

        $this->assertEquals(['user1', 'user2', 'user3'], array_column($rows, 0));
        $this->assertEquals(['Course A, Option &lt;B&gt;', 'Competency C', ''], array_column($rows, 2));

        $completed = get_string('completed', 'local_taskflow');
        $notcompleted = get_string('notcompleted', 'local_taskflow');
        $this->assertStringContainsString('Course A (' . s($completed) . ')', $rows[0][1]);
        $this->assertStringContainsString('Option &lt;B&gt; (' . s($notcompleted) . ')', $rows[0][1]);
        $this->assertStringContainsString('<br', $rows[0][1]);
        $this->assertStringContainsString(s(get_string('moodlecourse', 'local_taskflow')), $rows[0][1]);
        $this->assertStringContainsString('Competency C (' . s($notcompleted) . ')', $rows[1][1]);
        $this->assertSame('', $rows[2][1]);

        // Filter on the stored targets (by name).
        $rows = $this->get_rows($reportid, [
            'assignment:targets_operator' => text::CONTAINS,
            'assignment:targets_value' => 'Competency C',
        ]);
        $this->assertEquals(['user2'], array_column($rows, 0));

        // Filter on the stored targets (by type).
        $rows = $this->get_rows($reportid, [
            'assignment:targets_operator' => text::CONTAINS,
            'assignment:targets_value' => 'bookingoption',
        ]);
        $this->assertEquals(['user1'], array_column($rows, 0));
    }

    /**
     * Test the status column and select filter.
     */
    public function test_status_filter(): void {
        $user1 = $this->getDataGenerator()->create_user(['username' => 'user1']);
        $user2 = $this->getDataGenerator()->create_user(['username' => 'user2']);
        $ruleid = $this->create_rule('Rule');

        $completed = assignment_status_facade::get_status_identifier('completed');
        $this->create_assignment(['userid' => $user1->id, 'ruleid' => $ruleid, 'status' => 0]);
        $this->create_assignment(['userid' => $user2->id, 'ruleid' => $ruleid, 'status' => $completed]);

        $reportid = $this->create_report(['assignment:status'], ['assignment:status']);

        $this->assertEquals([
            ['user1', assignment_status_facade::get_specific_names(0)],
            ['user2', assignment_status_facade::get_specific_names($completed)],
        ], $this->get_rows($reportid));

        $rows = $this->get_rows($reportid, [
            'assignment:status_operator' => select::EQUAL_TO,
            'assignment:status_value' => $completed,
        ]);
        $this->assertEquals(['user2'], array_column($rows, 0));

        $rows = $this->get_rows($reportid, [
            'assignment:status_operator' => select::NOT_EQUAL_TO,
            'assignment:status_value' => $completed,
        ]);
        $this->assertEquals(['user1'], array_column($rows, 0));
    }

    /**
     * Test the date filters and the "within the past X years" filters.
     */
    public function test_date_filters(): void {
        $user1 = $this->getDataGenerator()->create_user(['username' => 'user1']);
        $user2 = $this->getDataGenerator()->create_user(['username' => 'user2']);
        $user3 = $this->getDataGenerator()->create_user(['username' => 'user3']);
        $ruleid = $this->create_rule('Rule');

        $now = time();
        // Recent assignment, due in the future, not completed.
        $this->create_assignment([
            'userid' => $user1->id,
            'ruleid' => $ruleid,
            'assigneddate' => $now - DAYSECS,
            'duedate' => $now + WEEKSECS,
        ]);
        // Old assignment, overdue, completed two years ago.
        $this->create_assignment([
            'userid' => $user2->id,
            'ruleid' => $ruleid,
            'assigneddate' => strtotime('-3 years', $now),
            'duedate' => strtotime('-2 years', $now),
            'completeddate' => strtotime('-2 years', $now),
        ]);
        // Assignment without any dates.
        $this->create_assignment([
            'userid' => $user3->id,
            'ruleid' => $ruleid,
            'assigneddate' => null,
            'duedate' => null,
        ]);

        $reportid = $this->create_report(
            ['assignment:completeddate'],
            [
                'assignment:duedate',
                'assignment:assigneddate',
                'assignment:assigneddateyears',
                'assignment:completeddateyears',
                'assignment:duedateyears',
            ]
        );

        $rows = $this->get_rows($reportid);
        $this->assertEquals(['user1', 'user2', 'user3'], array_column($rows, 0));
        $this->assertEquals(['', userdate(strtotime('-2 years', $now)), ''], array_column($rows, 1));

        // Due in the future.
        $rows = $this->get_rows($reportid, ['assignment:duedate_operator' => date::DATE_FUTURE]);
        $this->assertEquals(['user1'], array_column($rows, 0));

        // Due in the past.
        $rows = $this->get_rows($reportid, ['assignment:duedate_operator' => date::DATE_PAST]);
        $this->assertEquals(['user2'], array_column($rows, 0));

        // No due date.
        $rows = $this->get_rows($reportid, ['assignment:duedate_operator' => date::DATE_EMPTY]);
        $this->assertEquals(['user3'], array_column($rows, 0));

        // Assigned within the past year.
        $rows = $this->get_rows($reportid, [
            'assignment:assigneddateyears_operator' => timestamp_years_past::WITHIN_LAST_YEARS,
            'assignment:assigneddateyears_value' => 1,
        ]);
        $this->assertEquals(['user1'], array_column($rows, 0));

        // Assigned within the past five years.
        $rows = $this->get_rows($reportid, [
            'assignment:assigneddateyears_operator' => timestamp_years_past::WITHIN_LAST_YEARS,
            'assignment:assigneddateyears_value' => 5,
        ]);
        $this->assertEquals(['user1', 'user2'], array_column($rows, 0));

        // Completed within the past five years.
        $rows = $this->get_rows($reportid, [
            'assignment:completeddateyears_operator' => timestamp_years_past::WITHIN_LAST_YEARS,
            'assignment:completeddateyears_value' => 5,
        ]);
        $this->assertEquals(['user2'], array_column($rows, 0));

        // Due within the past five years: excludes future and empty due dates.
        $rows = $this->get_rows($reportid, [
            'assignment:duedateyears_operator' => timestamp_years_past::WITHIN_LAST_YEARS,
            'assignment:duedateyears_value' => 5,
        ]);
        $this->assertEquals(['user2'], array_column($rows, 0));

        // Zero years or "any value" apply no restriction.
        $rows = $this->get_rows($reportid, [
            'assignment:assigneddateyears_operator' => timestamp_years_past::WITHIN_LAST_YEARS,
            'assignment:assigneddateyears_value' => 0,
        ]);
        $this->assertCount(3, $rows);
        $rows = $this->get_rows($reportid, [
            'assignment:assigneddateyears_operator' => timestamp_years_past::ANYVALUE,
            'assignment:assigneddateyears_value' => 5,
        ]);
        $this->assertCount(3, $rows);
    }

    /**
     * Test the remaining assignment columns and boolean filters.
     */
    public function test_assignment_columns_and_boolean_filters(): void {
        $user1 = $this->getDataGenerator()->create_user(['username' => 'user1']);
        $user2 = $this->getDataGenerator()->create_user(['username' => 'user2']);
        $ruleid = $this->create_rule('Rule');

        $id1 = $this->create_assignment([
            'userid' => $user1->id,
            'ruleid' => $ruleid,
            'unitid' => 7,
            'keepchanges' => 1,
            'overduecounter' => 2,
            'prolongedcounter' => 3,
        ]);
        $id2 = $this->create_assignment(['userid' => $user2->id, 'ruleid' => $ruleid, 'active' => 0]);

        $reportid = $this->create_report(
            [
                'assignment:id',
                'assignment:userid',
                'assignment:unitid',
                'assignment:active',
                'assignment:keepchanges',
                'assignment:overduecounter',
                'assignment:prolongedcounter',
            ],
            ['assignment:active', 'assignment:keepchanges', 'assignment:overduecounter']
        );

        $this->assertEquals([
            ['user1', $id1, $user1->id, 7, get_string('yes'), get_string('yes'), 2, 3],
            ['user2', $id2, $user2->id, 1, get_string('no'), get_string('no'), 0, 0],
        ], $this->get_rows($reportid));

        $rows = $this->get_rows($reportid, ['assignment:active_operator' => boolean_select::NOT_CHECKED]);
        $this->assertEquals(['user2'], array_column($rows, 0));

        $rows = $this->get_rows($reportid, ['assignment:keepchanges_operator' => boolean_select::CHECKED]);
        $this->assertEquals(['user1'], array_column($rows, 0));

        $rows = $this->get_rows($reportid, [
            'assignment:overduecounter_operator' => number::GREATER_THAN,
            'assignment:overduecounter_value1' => 1,
        ]);
        $this->assertEquals(['user1'], array_column($rows, 0));
    }

    /**
     * Stress test the datasource: every column, aggregation and condition.
     */
    public function test_stress_datasource(): void {
        $supervisor = $this->getDataGenerator()->create_user();
        $user = $this->getDataGenerator()->create_user();
        $this->set_supervisor((int) $user->id, (int) $supervisor->id);
        $this->create_assignment([
            'userid' => $user->id,
            'ruleid' => $this->create_rule('Rule'),
            'targets' => $this->encode_targets([['moodlecourse', 'Course A', 1]]),
            'completeddate' => time(),
        ]);

        $this->datasource_stress_test_columns(assignment_datasource::class);
        $this->datasource_stress_test_columns_aggregation(assignment_datasource::class);
        $this->datasource_stress_test_conditions(assignment_datasource::class, 'assignment:id');
    }
}
