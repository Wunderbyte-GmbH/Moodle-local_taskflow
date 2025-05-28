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

namespace local_taskflow\multistepform;

use advanced_testcase;

/**
 * Rules table
 *
 * @package     local_taskflow
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class editrulesmanager_test extends advanced_testcase {
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
     * @covers \local_taskflow\multistepform\editrulesmanager
     */
    public function test_persist_inserts_rule_record(): void {
        global $DB;
        $mockformclass = new class {
            /**
             * Example test: Ensure external data is loaded.
             * @param array $steps
             * @return array
             */
            public function get_data_to_persist(array $steps): array {
                return [
                    'unitid' => 99,
                    'rulename' => 'Test Rule',
                    'rulejson' => json_encode(['name' => 'Test Rule']),
                    'isactive' => 1,
                ];
            }
        };

        $mockclassname = 'local_taskflow_form_mockstep_' . uniqid();
        class_alias(get_class($mockformclass), $mockclassname);

        $steps = [
            1 => [
                'formclass' => $mockclassname,
                'unitid' => 99,
                'name' => 'Test Rule',
                'enabled' => 1,
                'description' => 'Testing rule creation',
            ],
        ];

        $manager = new class ($steps) extends editrulesmanager {
            /**
             * Example test: Ensure external data is loaded.
             * @param array $steps
             */
            public function __construct(array $steps) {
                $this->steps = $steps;
                $this->uniqueid = 'testform';
                $this->recordid = 0;
            }
        };

        // Step 5: Call persist() and assert DB result.
        $manager->persist();

        $records = $DB->get_records('local_taskflow_rules');
        $this->assertCount(1, $records);

        $record = reset($records);
        $this->assertEquals('Test Rule', $record->rulename);
        $this->assertEquals(99, $record->unitid);
        $this->assertEquals(1, $record->isactive);
    }
}
