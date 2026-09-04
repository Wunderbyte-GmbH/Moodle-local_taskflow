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
use core_reportbuilder\manager;
use core_reportbuilder\tests\core_reportbuilder_testcase;
use local_taskflow\reportbuilder\local\entities\deputy;
use local_taskflow\reportbuilder\local\filters\user_in_list;
use local_taskflow_generator;
use PHPUnit\Framework\Attributes\CoversClass;
use stdClass;

/**
 * Supervisor datasource and deputy entity tests.
 *
 * @package    local_taskflow
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <https://www.wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(supervisor_datasource::class)]
#[CoversClass(assignment_datasource::class)]
#[CoversClass(deputy::class)]
#[CoversClass(user_in_list::class)]
final class supervisor_datasource_test extends core_reportbuilder_testcase {
    /**
     * Set up: supervisor and deputy profile fields and standard adapter configuration.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        /** @var local_taskflow_generator $plugingenerator */
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingenerator->create_custom_profile_fields(['supervisor', 'units', 'deputy']);
        $plugingenerator->set_config_values();
        deputy::reset_cache();
    }

    /**
     * Store a value in a custom profile field of a user.
     *
     * @param int $userid
     * @param string $shortname
     * @param string $value
     */
    private function set_profile_field(int $userid, string $shortname, string $value): void {
        global $DB;

        $fieldid = $DB->get_field('user_info_field', 'id', ['shortname' => $shortname], MUST_EXIST);
        if ($existing = $DB->get_record('user_info_data', ['userid' => $userid, 'fieldid' => $fieldid])) {
            $existing->data = $value;
            $DB->update_record('user_info_data', $existing);
        } else {
            $DB->insert_record('user_info_data', (object) [
                'userid' => $userid,
                'fieldid' => $fieldid,
                'data' => $value,
                'dataformat' => FORMAT_PLAIN,
            ]);
        }
    }

    /**
     * Create a user with the given first name (last name "User").
     *
     * @param string $firstname
     * @return stdClass
     */
    private function create_user(string $firstname): stdClass {
        return $this->getDataGenerator()->create_user([
            'username' => strtolower($firstname),
            'firstname' => $firstname,
            'lastname' => 'User',
        ]);
    }

    /**
     * Create a report over the supervisor datasource with the username as first
     * (sorted) column, followed by the given columns, filters and conditions.
     *
     * @param string[] $columns
     * @param string[] $filters
     * @param string[] $conditions
     * @param string $source
     * @return int Report ID
     */
    private function create_report(
        array $columns,
        array $filters = [],
        array $conditions = [],
        string $source = supervisor_datasource::class
    ): int {
        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');

        $report = $generator->create_report(['name' => 'Supervisors', 'source' => $source, 'default' => 0]);
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
        manager::get_report_from_id($reportid)->set_condition_values($values);
    }

    /**
     * Test the default report: supervisors with deputies, their names comma separated.
     */
    public function test_datasource_default(): void {
        $deputy1 = $this->create_user('Dora');
        $deputy2 = $this->create_user('Emil');
        $anna = $this->create_user('Anna');
        $bert = $this->create_user('Bert');
        $this->create_user('Carla');

        $this->set_profile_field((int) $anna->id, 'deputy', "{$deputy1->id},{$deputy2->id}");
        $this->set_profile_field((int) $bert->id, 'deputy', (string) $deputy2->id);

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $generator->create_report([
            'name' => 'Supervisors',
            'source' => supervisor_datasource::class,
            'default' => 1,
        ]);

        $rows = $this->get_rows((int) $report->get('id'));

        $this->assertCount(2, $rows);
        $this->assertStringContainsString('Anna User', $rows[0][0]);
        $this->assertEquals([$anna->email, 'Dora User, Emil User', 2], array_slice($rows[0], 1));
        $this->assertStringContainsString('Bert User', $rows[1][0]);
        $this->assertEquals([$bert->email, 'Emil User', 1], array_slice($rows[1], 1));
    }

    /**
     * Test the deputy columns: order, links, raw IDs and handling of deleted or unknown IDs.
     */
    public function test_deputy_columns(): void {
        global $DB;

        $deputy1 = $this->create_user('Dora');
        $deputy2 = $this->create_user('Emil');
        $deleted = $this->create_user('Gone');
        $anna = $this->create_user('Anna');
        $bert = $this->create_user('Bert');
        $carla = $this->create_user('Carla');

        // Stored order must be kept, unknown and deleted IDs skipped.
        $this->set_profile_field((int) $anna->id, 'deputy', "{$deputy2->id},{$deputy1->id}");
        $this->set_profile_field((int) $bert->id, 'deputy', " {$deleted->id}, 999999 ,{$deputy1->id},");
        $this->set_profile_field((int) $carla->id, 'deputy', '');
        delete_user($deleted);
        deputy::reset_cache();

        $reportid = $this->create_report(
            ['deputy:deputies', 'deputy:deputieswithlink', 'deputy:deputyids', 'deputy:deputycount'],
            [],
            ['deputy:hasdeputies']
        );
        $this->set_condition_values($reportid, ['deputy:hasdeputies_operator' => boolean_select::CHECKED]);

        $rows = $this->get_rows($reportid);
        $this->assertEquals(['anna', 'bert'], array_column($rows, 0));

        $this->assertEquals('Emil User, Dora User', $rows[0][1]);
        $this->assertEquals("{$deputy2->id},{$deputy1->id}", $rows[0][3]);
        $this->assertEquals(2, $rows[0][4]);
        $this->assertStringContainsString('/user/profile.php?id=' . $deputy2->id, $rows[0][2]);
        $this->assertStringContainsString('>Emil User</a>, <a', $rows[0][2]);

        $this->assertEquals('Dora User', $rows[1][1]);
        $this->assertEquals(1, $rows[1][4]);
        $this->assertStringNotContainsString('Gone', $rows[1][2]);

        // The deputy count only counts existing users, so the deleted one is not counted.
        $this->assertSame(1, $DB->count_records('user', ['id' => $deleted->id, 'deleted' => 1]));
    }

    /**
     * Test the "has deputies", "deputy is user" and "is supervisor" filters.
     */
    public function test_deputy_filters(): void {
        $deputy1 = $this->create_user('Dora');
        $deputy2 = $this->create_user('Emil');
        $anna = $this->create_user('Anna');
        $bert = $this->create_user('Bert');
        $carla = $this->create_user('Carla');
        $fred = $this->create_user('Fred');
        $subordinate = $this->create_user('Sub');

        $this->set_profile_field((int) $anna->id, 'deputy', "{$deputy1->id},{$deputy2->id}");
        $this->set_profile_field((int) $bert->id, 'deputy', (string) $deputy2->id);
        // A list whose entry merely contains deputy one's ID as a substring must not match.
        $this->set_profile_field((int) $fred->id, 'deputy', $deputy1->id . '0' . ',1' . $deputy1->id);
        // Only Anna is somebody's supervisor.
        $this->set_profile_field((int) $subordinate->id, 'supervisor', (string) $anna->id);

        $reportid = $this->create_report(
            [],
            ['deputy:hasdeputies', 'deputy:deputy', 'deputy:issupervisor']
        );

        // Has deputies.
        $rows = $this->get_rows($reportid, ['deputy:hasdeputies_operator' => boolean_select::CHECKED]);
        $this->assertEquals(['anna', 'bert', 'fred'], array_column($rows, 0));

        // Has no deputies: everybody else, including users without any deputy field row.
        $rows = $this->get_rows($reportid, ['deputy:hasdeputies_operator' => boolean_select::NOT_CHECKED]);
        $usernames = array_column($rows, 0);
        $this->assertContains('carla', $usernames);
        $this->assertContains('dora', $usernames);
        $this->assertContains('sub', $usernames);
        $this->assertNotContains('anna', $usernames);
        $this->assertNotContains('bert', $usernames);
        $this->assertNotContains('fred', $usernames);

        // Deputy is a given user.
        $rows = $this->get_rows($reportid, [
            'deputy:deputy_operator' => user_in_list::IS_USER,
            'deputy:deputy_value' => $deputy1->id,
        ]);
        $this->assertEquals(['anna'], array_column($rows, 0));

        $rows = $this->get_rows($reportid, [
            'deputy:deputy_operator' => user_in_list::IS_USER,
            'deputy:deputy_value' => $deputy2->id,
        ]);
        $this->assertEquals(['anna', 'bert'], array_column($rows, 0));

        // Deputy is the current user.
        $this->setUser($deputy2);
        $rows = $this->get_rows($reportid, ['deputy:deputy_operator' => user_in_list::CURRENT_USER]);
        $this->assertEquals(['anna', 'bert'], array_column($rows, 0));

        $this->setUser($carla);
        $rows = $this->get_rows($reportid, ['deputy:deputy_operator' => user_in_list::CURRENT_USER]);
        $this->assertEquals([], $rows);
        $this->setAdminUser();

        // Missing user ID or "any value" apply no restriction.
        $rows = $this->get_rows($reportid, ['deputy:deputy_operator' => user_in_list::IS_USER, 'deputy:deputy_value' => 0]);
        $this->assertGreaterThan(3, count($rows));
        $rows = $this->get_rows($reportid, ['deputy:deputy_operator' => user_in_list::ANYVALUE]);
        $this->assertGreaterThan(3, count($rows));

        // Is supervisor of at least one user.
        $rows = $this->get_rows($reportid, ['deputy:issupervisor_operator' => boolean_select::CHECKED]);
        $this->assertEquals(['anna'], array_column($rows, 0));

        // Combined: supervisors with deputies.
        $rows = $this->get_rows($reportid, [
            'deputy:hasdeputies_operator' => boolean_select::CHECKED,
            'deputy:issupervisor_operator' => boolean_select::NOT_CHECKED,
        ]);
        $this->assertEquals(['bert', 'fred'], array_column($rows, 0));
    }

    /**
     * Test the datasource without a configured deputy field: plain user list, no deputy entity.
     */
    public function test_deputy_not_configured(): void {
        unset_config('deputy', 'taskflowadapter_standard');
        \cache_helper::invalidate_by_event('config', ['local_taskflow']);

        $this->assertSame(0, deputy::get_deputy_field_id());
        $anna = $this->create_user('Anna');

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $generator->create_report([
            'name' => 'Supervisors',
            'source' => supervisor_datasource::class,
            'default' => 1,
        ]);
        $instance = manager::get_report_from_persistent($report);
        $this->assertArrayNotHasKey('deputy:deputies', $instance->get_columns());
        $this->assertArrayNotHasKey('deputy:hasdeputies', $instance->get_conditions());

        $rows = $this->get_rows((int) $report->get('id'));
        $this->assertContains($anna->email, array_column($rows, 1));
    }

    /**
     * Test the deputy entity within the assignment datasource: deputies of the
     * assigned user's supervisor, and the "deputy is current user" condition.
     */
    public function test_deputies_in_assignment_datasource(): void {
        global $DB;

        $deputy1 = $this->create_user('Dora');
        $deputy2 = $this->create_user('Emil');
        $anna = $this->create_user('Anna');
        $bert = $this->create_user('Bert');
        $sub1 = $this->create_user('Sub1');
        $sub2 = $this->create_user('Sub2');

        $this->set_profile_field((int) $anna->id, 'deputy', (string) $deputy1->id);
        $this->set_profile_field((int) $sub1->id, 'supervisor', (string) $anna->id);
        $this->set_profile_field((int) $sub2->id, 'supervisor', (string) $bert->id);

        $ruleid = $DB->insert_record('local_taskflow_rules', (object) ['rulename' => 'Rule', 'rulejson' => '{}']);
        foreach ([$sub1, $sub2] as $user) {
            $DB->insert_record('local_taskflow_assignment', (object) [
                'userid' => $user->id,
                'ruleid' => $ruleid,
                'targets' => '[]',
                'messages' => '[]',
                'unitid' => 1,
                'active' => 1,
                'status' => 0,
                'assigneddate' => time(),
                'duedate' => time() + DAYSECS,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }

        $reportid = $this->create_report(
            ['supervisor:fullname', 'deputy:deputies'],
            [],
            ['deputy:deputy'],
            assignment_datasource::class
        );

        $this->assertEquals([
            ['sub1', 'Anna User', 'Dora User'],
            ['sub2', 'Bert User', ''],
        ], $this->get_rows($reportid));

        $this->set_condition_values($reportid, ['deputy:deputy_operator' => user_in_list::CURRENT_USER]);

        $this->setUser($deputy1);
        $this->assertEquals(['sub1'], array_column($this->get_rows($reportid), 0));

        $this->setUser($deputy2);
        $this->assertEquals([], $this->get_rows($reportid));
    }

    /**
     * Stress test the datasource: every column, aggregation and condition.
     */
    public function test_stress_datasource(): void {
        $deputy1 = $this->create_user('Dora');
        $anna = $this->create_user('Anna');
        $sub = $this->create_user('Sub');
        $this->set_profile_field((int) $anna->id, 'deputy', (string) $deputy1->id);
        $this->set_profile_field((int) $sub->id, 'supervisor', (string) $anna->id);

        $this->datasource_stress_test_columns(supervisor_datasource::class);
        $this->datasource_stress_test_columns_aggregation(supervisor_datasource::class);
        $this->datasource_stress_test_conditions(supervisor_datasource::class, 'user:username');
    }
}
