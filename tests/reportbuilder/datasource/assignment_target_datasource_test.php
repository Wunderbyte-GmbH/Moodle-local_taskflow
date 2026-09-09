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

use core_customfield_generator;
use core_reportbuilder_generator;
use core_reportbuilder\local\filters\boolean_select;
use core_reportbuilder\local\filters\text;
use core_reportbuilder\tests\core_reportbuilder_testcase;
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow_generator;
use mod_booking\singleton_service;
use stdClass;

/**
 * Assignment target datasource tests.
 *
 * @package    local_taskflow
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <https://www.wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_taskflow\reportbuilder\datasource\assignment_target_datasource
 * @covers     \local_taskflow\reportbuilder\local\entities\assignment_target
 * @covers     \local_taskflow\reportbuilder\local\helpers\target_sql
 * @covers     \local_taskflow\reportbuilder\local\helpers\supervisor_entities
 */
final class assignment_target_datasource_test extends core_reportbuilder_testcase {
    /** @var stdClass[] Competencies c1, c2 (persistent records as stdClass). */
    private array $competencies = [];

    /** @var stdClass[] Booking options A (c1), B (c2,c1), C (c2), keyed by letter. */
    private array $options = [];

    /** @var stdClass[] Users keyed by username. */
    private array $users = [];

    /**
     * Set up: supervisor profile field and standard adapter configuration.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var local_taskflow_generator $plugingenerator */
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingenerator->create_custom_profile_fields(['supervisor', 'units']);
        $plugingenerator->set_config_values();
    }

    /**
     * Create a rule record.
     *
     * @param string $rulename
     * @return int Rule ID
     */
    private function create_rule(string $rulename): int {
        global $DB;

        return $DB->insert_record('local_taskflow_rules', (object) [
            'rulename' => $rulename,
            'rulejson' => '{}',
            'isactive' => 1,
            'unitid' => 1,
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
     * Create a booking answer record directly.
     *
     * @param int $userid
     * @param stdClass $option
     * @param int $waitinglist
     */
    private function create_answer(int $userid, stdClass $option, int $waitinglist): void {
        global $DB;

        $bookingid = (int) $DB->get_field('booking_options', 'bookingid', ['id' => $option->id], MUST_EXIST);
        $now = time();
        $DB->insert_record('booking_answers', (object) [
            'bookingid' => $bookingid,
            'userid' => $userid,
            'optionid' => (int) $option->id,
            'timemodified' => $now,
            'timecreated' => $now,
            'timebooked' => $now,
            'waitinglist' => $waitinglist,
            'frombookingid' => 0,
            'numrec' => 0,
            'status' => 0,
            'syncruleid' => 0,
            'startdate' => 0,
            'enddate' => 0,
        ]);
    }

    /**
     * Encode a target as stored on an assignment, in the key order written by the targets form.
     *
     * @param string $type
     * @param int|string $targetid
     * @param string $name
     * @return array
     */
    private function target(string $type, $targetid, string $name): array {
        return [
            'targettype' => $type,
            'targetid' => $targetid,
            'completebeforenext' => false,
            'sortorder' => 2,
            'targetname' => $name,
            'actiontype' => 'enroll',
        ];
    }

    /**
     * Create competencies c1, c2, booking options A (c1), B (c2,c1), C (c2) with a
     * "klassifizierung" select custom field, and the users and assignments:
     *
     * - userx: competency c1 (string ID) plus a course target with the ID of c2 (must not match c2),
     *   booked on A, deleted answer on B.
     * - usery: competency c2 (numeric ID, reverse key order), no answers.
     * - userz: invalid targets JSON, userw: no targets - both without rows.
     * - usero: not assigned, on the waiting list of B.
     */
    private function create_fixture(): void {
        global $DB;

        $generator = $this->getDataGenerator();
        /** @var local_taskflow_generator $plugingenerator */
        $plugingenerator = $generator->get_plugin_generator('local_taskflow');

        $course = $generator->create_course(['enablecompletion' => 1]);
        $manager = $generator->create_user(['username' => 'manager']);

        [$c1, $c2] = $plugingenerator->create_competencies($this, 2);
        $this->competencies = [$c1->to_record(), $c2->to_record()];
        $c1id = (int) $c1->get('id');
        $c2id = (int) $c2->get('id');

        [$a, $b, $c] = $plugingenerator->create_booking_options($this, (int) $course->id, $manager, 3, [], [
            ['text' => 'Option A'],
            ['text' => 'Option B'],
            ['text' => 'Option C'],
        ]);
        $this->options = ['A' => $a, 'B' => $b, 'C' => $c];
        $DB->set_field('booking_options', 'competencies', (string) $c1id, ['id' => $a->id]);
        $DB->set_field('booking_options', 'competencies', "{$c2id},{$c1id}", ['id' => $b->id]);
        $DB->set_field('booking_options', 'competencies', (string) $c2id, ['id' => $c->id]);
        foreach ($this->options as $option) {
            singleton_service::destroy_booking_option_singleton((int) $option->id);
        }

        /** @var core_customfield_generator $cfgenerator */
        $cfgenerator = $generator->get_plugin_generator('core_customfield');
        $category = $cfgenerator->create_category(['component' => 'mod_booking', 'area' => 'booking']);
        $field = $cfgenerator->create_field([
            'categoryid' => $category->get('id'),
            'shortname' => 'klassifizierung',
            'name' => 'Klassifizierung',
            'type' => 'select',
            'configdata' => ['options' => "interprofessionell\nüberfachlich\nDiverse Bereiche"],
        ]);
        $cfgenerator->add_instance_data($field, (int) $a->id, 2);

        foreach (['userx', 'usery', 'userz', 'userw', 'usero'] as $username) {
            $this->users[$username] = $generator->create_user(['username' => $username]);
        }
        $ruleid = $this->create_rule('Rule');

        $this->create_assignment([
            'userid' => $this->users['userx']->id,
            'ruleid' => $ruleid,
            'targets' => json_encode([
                $this->target('competency', (string) $c1id, 'Kompetenz Eins'),
                $this->target('moodlecourse', (string) $c2id, 'Course with competency ID'),
            ]),
        ]);
        $this->create_assignment([
            'userid' => $this->users['usery']->id,
            'ruleid' => $ruleid,
            'targets' => json_encode([[
                'targetid' => $c2id,
                'targettype' => 'competency',
                'targetname' => 'Kompetenz Zwei',
                'sortorder' => 1,
                'actiontype' => 'enroll',
                'completebeforenext' => false,
            ]]),
        ]);
        $this->create_assignment(['userid' => $this->users['userz']->id, 'ruleid' => $ruleid, 'targets' => 'not json']);
        $this->create_assignment(['userid' => $this->users['userw']->id, 'ruleid' => $ruleid, 'targets' => '[]']);

        $this->create_answer((int) $this->users['userx']->id, $a, 0);
        $this->create_answer((int) $this->users['userx']->id, $b, 5);
        $this->create_answer((int) $this->users['usero']->id, $b, 1);
    }

    /**
     * Create a report over the datasource with username and option name as sorted
     * first columns, followed by the given columns, filters and conditions.
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
            'name' => 'Assignment targets',
            'source' => assignment_target_datasource::class,
            'default' => 0,
        ]);
        $reportid = (int) $report->get('id');

        $generator->create_column(['reportid' => $reportid, 'uniqueidentifier' => 'user:username', 'sortenabled' => 1]);
        $generator->create_column([
            'reportid' => $reportid,
            'uniqueidentifier' => 'booking_options:text',
            'sortenabled' => 1,
        ]);
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
     * One row per competency target and booking option, with the booked flags.
     */
    public function test_rows_and_booked_flags(): void {
        $this->create_fixture();
        $yes = get_string('yes');
        $no = get_string('no');
        [$c1, $c2] = $this->competencies;

        $reportid = $this->create_report([
            'assignment_target:name',
            'assignment_target:targetname',
            'assignment_target:competencyid',
            'assignment_target:booked',
            'assignment_target:bookedany',
        ], ['assignment_target:booked', 'assignment_target:bookedany', 'assignment_target:name']);

        $this->assertEquals([
            ['userx', 'Option A', $c1->shortname, 'Kompetenz Eins', $c1->id, $yes, $yes],
            ['userx', 'Option B', $c1->shortname, 'Kompetenz Eins', $c1->id, $no, $yes],
            ['usery', 'Option B', $c2->shortname, 'Kompetenz Zwei', $c2->id, $no, $no],
            ['usery', 'Option C', $c2->shortname, 'Kompetenz Zwei', $c2->id, $no, $no],
        ], $this->get_rows($reportid));

        // Not booked on the option of the row.
        $rows = $this->get_rows($reportid, ['assignment_target:booked_operator' => boolean_select::NOT_CHECKED]);
        $this->assertEquals(
            [['userx', 'Option B'], ['usery', 'Option B'], ['usery', 'Option C']],
            array_map(static fn(array $row): array => array_slice($row, 0, 2), $rows)
        );

        // Booked on the option of the row.
        $rows = $this->get_rows($reportid, ['assignment_target:booked_operator' => boolean_select::CHECKED]);
        $this->assertEquals(
            [['userx', 'Option A']],
            array_map(static fn(array $row): array => array_slice($row, 0, 2), $rows)
        );

        // Not booked on any option of the competency (the reference "HAVING COUNT(answers) = 0").
        $rows = $this->get_rows($reportid, ['assignment_target:bookedany_operator' => boolean_select::NOT_CHECKED]);
        $this->assertEquals(['usery', 'usery'], array_column($rows, 0));

        // Competency name filter.
        $rows = $this->get_rows($reportid, [
            'assignment_target:name_operator' => text::IS_EQUAL_TO,
            'assignment_target:name_value' => $c2->shortname,
        ]);
        $this->assertEquals(['usery', 'usery'], array_column($rows, 0));
    }

    /**
     * A competency target no booking option carries still gives one row, with an empty option
     * and the competency short name as fallback target name.
     */
    public function test_target_without_option(): void {
        global $DB;

        $this->create_fixture();
        [$c1] = $this->competencies;
        $DB->set_field('booking_options', 'competencies', '', ['id' => $this->options['A']->id]);
        $DB->set_field('booking_options', 'competencies', '', ['id' => $this->options['B']->id]);

        $user = $this->getDataGenerator()->create_user(['username' => 'userq']);
        $this->create_assignment([
            'userid' => $user->id,
            'ruleid' => $this->create_rule('Rule 2'),
            'targets' => json_encode([[
                'targettype' => 'competency',
                'targetid' => (int) $c1->id,
            ]]),
        ]);

        $reportid = $this->create_report(['assignment_target:targetname', 'assignment_target:booked']);
        $rows = $this->get_rows($reportid);

        $this->assertEquals([
            ['userq', '', $c1->shortname, get_string('no')],
            ['userx', '', 'Kompetenz Eins', get_string('no')],
            ['usery', 'Option C', 'Kompetenz Zwei', get_string('no')],
        ], $rows);
    }

    /**
     * Booking option custom fields are available through the booking options entity.
     */
    public function test_booking_option_customfield(): void {
        $this->create_fixture();

        $reportid = $this->create_report(['booking_options:customfield_klassifizierung']);

        $this->assertEquals([
            ['userx', 'Option A', 'überfachlich'],
            ['userx', 'Option B', ''],
            ['usery', 'Option B', ''],
            ['usery', 'Option C', ''],
        ], $this->get_rows($reportid));
    }

    /**
     * Default report: default columns and the conditions "active", "not completed" and
     * "not booked on any option of the competency".
     */
    public function test_datasource_default(): void {
        $this->create_fixture();
        $assigned = strtotime('2026-01-10 10:00');
        $due = strtotime('2026-03-01 10:00');
        [$c1] = $this->competencies;

        // Completed and inactive assignments with an unbooked competency: excluded by the default conditions.
        $userv = $this->getDataGenerator()->create_user(['username' => 'userv']);
        $ruleid = $this->create_rule('Rule 2');
        $this->create_assignment([
            'userid' => $userv->id,
            'ruleid' => $ruleid,
            'targets' => json_encode([$this->target('competency', (string) $c1->id, 'Kompetenz Eins')]),
            'status' => assignment_status_facade::get_status_identifier('completed'),
        ]);
        $this->create_assignment([
            'userid' => $userv->id,
            'ruleid' => $ruleid,
            'targets' => json_encode([$this->target('competency', (string) $c1->id, 'Kompetenz Eins')]),
            'active' => 0,
        ]);

        global $DB;
        $DB->set_field('local_taskflow_assignment', 'assigneddate', $assigned, ['userid' => $this->users['usery']->id]);
        $DB->set_field('local_taskflow_assignment', 'duedate', $due, ['userid' => $this->users['usery']->id]);

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $generator->create_report([
            'name' => 'Assignment targets',
            'source' => assignment_target_datasource::class,
            'default' => 1,
        ]);

        $usery = $this->users['usery'];
        $this->assertEqualsCanonicalizing([
            [fullname($usery), 'Kompetenz Zwei', 'Option B', userdate($assigned), userdate($due)],
            [fullname($usery), 'Kompetenz Zwei', 'Option C', userdate($assigned), userdate($due)],
        ], $this->get_rows((int) $report->get('id')));
    }

    /**
     * Stress test datasource columns.
     */
    public function test_stress_columns(): void {
        $this->create_fixture();

        $this->datasource_stress_test_columns(assignment_target_datasource::class);
    }

    /**
     * Stress test datasource column aggregation.
     */
    public function test_stress_columns_aggregation(): void {
        $this->create_fixture();

        $this->datasource_stress_test_columns_aggregation(assignment_target_datasource::class);
    }

    /**
     * Stress test datasource conditions.
     */
    public function test_stress_conditions(): void {
        $this->create_fixture();

        $this->datasource_stress_test_conditions(assignment_target_datasource::class, 'assignment:id');
    }
}
