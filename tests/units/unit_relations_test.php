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
use local_taskflow\local\units\unit_relations;

/**
 * Class unit_relations
 *
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class unit_relations_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        unit_relations::reset_instances();
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\units\unit_relations
     */
    public function test_construct(): void {
        global $DB;
        $unitrelationsinstance = unit_relations::create(1, 2);
        $this->assertEquals(1, $unitrelationsinstance->get_childid());
        $this->assertEquals(2, $unitrelationsinstance->get_parentid());
        $this->assertEquals(1, $unitrelationsinstance->get_active());
        $newinstanceid = $unitrelationsinstance->get_childid();
        $dbinstance = unit_relations::instance($newinstanceid);

        $dbinstance->change_activision();
        $this->assertEquals(0, $unitrelationsinstance->get_active());

        $dbinstance->delete();
        $this->assertFalse(
            $DB->record_exists(
                'local_taskflow_unit_relations',
                ['id' => $dbinstance->get_id()]
            )
        );
    }
}
