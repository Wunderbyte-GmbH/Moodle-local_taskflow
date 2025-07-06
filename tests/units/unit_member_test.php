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
use local_taskflow\local\units\organisational_unit_factory;

/**
 * Class unit_member
 *
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class unit_member_test extends advanced_testcase {
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
     * @covers \local_taskflow\local\units\organisational_units\unit
     * @covers \local_taskflow\local\units\organisational_unit_factory
     */
    public function test_construct(): void {
        global $DB;
        $record = (object) [
            'name' => 'Testing HR',
        ];
        $unitinstance = organisational_unit_factory::create_unit($record);
        $sameunitinstance = organisational_unit_factory::instance($unitinstance->get_id());
        $firstname = $unitinstance->get_name();
        $this->assertEquals($firstname, $sameunitinstance->get_name());

        $sameunitinstance->update('Testing new HR');
        $this->assertNotEquals($firstname, $sameunitinstance->get_name());

        $sameunitinstance->add_member(1);
        $this->assertTrue($sameunitinstance->is_member(1));

        $members = $sameunitinstance->get_members();
        $this->assertCount(1, $members);
        $this->assertEquals($sameunitinstance->count_members(), 1);

        $sameunitinstance->delete_member(1);
        $this->assertFalse($sameunitinstance->is_member(1));

        $sameunitinstance->delete();
        $this->assertFalse(
            $DB->record_exists('local_taskflow_units', ['id' => $sameunitinstance->get_id()])
        );
    }
}
