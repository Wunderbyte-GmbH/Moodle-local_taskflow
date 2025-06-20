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

/**
 * Rules table.
 *
 * @package     local_taskflow
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\table;

use advanced_testcase;
use local_taskflow\local\assignments\activity_status\assignment_activity_status;
use local_taskflow\local\assignments\status\assignment_status;
use moodle_url;
use stdClass;

/**
 * Rules table
 *
 * @package     local_taskflow
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class assignments_table_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        \local_taskflow\local\units\unit_relations::reset_instances();
        set_config(
            'organisational_unit_option',
            'cohort',
            'local_taskflow'
        );
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\table\assignments_table
     */
    public function test_col_actions_renders_correct_link(): void {
        $table = new assignments_table('testtable');

        $fake = new stdClass();
        $fake->id = 42;

        $output = $table->col_actions($fake);

        $expectedurl = new moodle_url('/local/taskflow/editassignment.php', ['id' => 42]);
        $expected = "<div><a href=\"" . $expectedurl->out() . "\"><i class='icon fa fa-edit'></i></a></div>";

        $this->assertEquals($expected, $output);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\table\assignments_table
     */
    public function test_col_targets(): void {
        $table = new assignments_table('dummy');

        $values = new stdClass();
        $values->targets = json_encode([
            (object)['targettype' => 'course', 'targetname' => 'Deutsch'],
            (object)['targettype' => 'quiz', 'targetname' => 'Grammatiktest']
        ]);

        $expectedtext = 'course: Deutschquiz: Grammatiktest';
        $output = $table->col_targets($values);

        $this->assertStringContainsString($expectedtext, $output);
        $this->assertStringContainsString('<div>', $output);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\table\assignments_table
     */
    public function test_col_description(): void {
        $table = new assignments_table('dummy');

        $values = new stdClass();
        $values->rulejson = json_encode([
            'rulejson' => [
                'rule' => [
                    'description' => 'Testbeschreibung',
                ],
            ],
        ]);

        $output = $table->col_description($values);
        $this->assertStringContainsString('Testbeschreibung', $output);
        $this->assertStringContainsString('<div>', $output);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\table\assignments_table
     * @covers \local_taskflow\local\assignments\activity_status\assignment_activity_status
     */
    public function test_col_isactive(): void {
        $table = new assignments_table('dummy');

        $values = new stdClass();
        $values->active = 1;

        $expectedlabel = 'Active';
        $output = $table->col_isactive($values);

        $this->assertStringContainsString('<div>', $output);
        $this->assertStringContainsString(assignment_activity_status::get_label(1), $output);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\table\assignments_table
     * @covers \local_taskflow\local\assignments\status\assignment_status
     */
    public function test_col_statuslabel(): void {
        $table = new assignments_table('dummy');

        $values = new stdClass();
        $values->status = 0;

        $label = assignment_status::get_label(0);

        $this->assertEquals($label, $table->col_statuslabel($values));
    }
}
