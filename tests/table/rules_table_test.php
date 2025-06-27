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

/**
 * Rules table
 *
 * @package     local_taskflow
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rules_table_test extends advanced_testcase {
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
     * @covers \local_taskflow\table\rules_table
     */
    public function test_col_actions_generates_correct_link(): void {
        global $PAGE;
        $PAGE->set_url(new \moodle_url('/'));
        $table = new rules_table('dummy');

        $row = (object)[
            'id' => 42,
        ];

        $result = $table->col_actions($row);
        $this->assertStringContainsString('/local/taskflow/editrule.php?id=42', $result);
        $this->assertStringContainsString('fa-edit', $result);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\table\rules_table
     */
    public function test_col_description_parses_json_correctly(): void {
        $table = new rules_table('dummy');

        $row = (object)[
            'rulejson' => json_encode(
                [
                    'rulejson' => [
                        'rule' => [
                            'description' => 'This is a test rule.',
                        ],
                    ],
                ],
            ),
        ];

        $result = $table->col_description($row);
        $this->assertStringContainsString('This is a test rule.', $result);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\table\rules_table
     */
    public function test_col_isactive_renders_yes_or_no(): void {
        $table = new rules_table('dummy');
        $rowyes = (object)['isactive' => 1];
        $rowno = (object)['isactive' => 0];
        $resultyes = $table->col_isactive($rowyes);
        $resultno = $table->col_isactive($rowno);

        $this->assertStringContainsString(get_string('yes'), $resultyes);
        $this->assertStringContainsString(get_string('no'), $resultno);
    }
}
