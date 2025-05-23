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
 * Unit class to manage users.
 *
 * @package local_taskflow
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\units;

use advanced_testcase;
use local_taskflow\local\units\organisational_units_factory;

/**
 * Class unit_member
 *
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class units_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        \local_taskflow\local\units\unit_relations::reset_instances();
        set_config(
            'organisational_unit_option',
            'unit',
            'local_taskflow'
        );
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\units\organisational_units\units
     * @covers \local_taskflow\local\units\organisational_units_factory
     */
    public function test_construct(): void {
        global $DB, $USER;

        $unit = (object)[
            'name' => 'Test Unit',
            'criteria' => '',
            'timecreated' => time(),
            'timemodified' => time(),
            'usermodified' => $USER->id,
        ];
        $unit->id = $DB->insert_record('local_taskflow_units', $unit);

        $unitsinstance = organisational_units_factory::instance();
        $result = $unitsinstance->get_units();

        $this->assertCount(1, $result);
        $this->assertArrayHasKey($unit->id, $result);
    }
}
