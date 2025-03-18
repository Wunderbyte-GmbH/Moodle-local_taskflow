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

namespace local_taskflow;

use advanced_testcase;
use local_taskflow\local\units\unit;

/**
 * Test unit class of local_taskflow.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class unitmembership_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        $this->resetAfterTest(true);
    }

    /**
     * Data provider for unit membership tests.
     *
     * @return array
     */
    public static function unit_membership_data_provider(): array {
        return [
            ['Unit 1', 1],
            ['Unit 2', 2],
            ['Unit 3', 3],
            ['Unit 4', 4],
            ['Unit 5', 5],
        ];
    }

    /**
     * Test adding a member to the unit.
     * @param string $unitname
     * @param string $userid
     * @covers \local_taskflow\local\units\unit::create
     * @dataProvider unit_membership_data_provider
     */
    public function test_add_member($unitname, $userid): void {
        // Create a unit.
        $unit = unit::create($unitname, json_encode(['field' => 'value']));

        // Add member.
        $unit->add_member($userid);
        $this->assertTrue($unit->is_member($userid), "User {$userid} should be a member of {$unitname}");
    }

    /**
     * Test deleting a member from the unit.
     * @param string $unitname
     * @param string $userid
     * @covers \local_taskflow\local\units\unit::create
     * @dataProvider unit_membership_data_provider
     */
    public function test_delete_member($unitname, $userid): void {
        // Create and add member.
        $unit = unit::create($unitname, json_encode(['field' => 'value']));

        $unit->add_member($userid);

        // Delete member.
        $unit->delete_member($userid);
        $this->assertFalse($unit->is_member($userid), "User {$userid} should no longer be a member of {$unitname}");
    }

    /**
     * Test counting the number of members in the unit.
     * @param string $unitname
     * @covers \local_taskflow\local\units\unit::create
     * @dataProvider unit_membership_data_provider
     */
    public function test_count_members($unitname): void {
        // Create a unit and add some members.
        $unit = unit::create($unitname, json_encode(['field' => 'value']));

        $unit->add_member(1);
        $unit->add_member(2);

        // Check member count.
        $count = $unit->count_members();
        $this->assertEquals(2, $count, "The unit {$unitname} should have 2 members.");
    }

    /**
     * Test getting all members of a unit.
     * @param string $unitname
     * @covers \local_taskflow\local\units\unit::create
     * @dataProvider unit_membership_data_provider
     */
    public function test_get_members($unitname): void {
        // Create a unit and add members.
        $unit = unit::create($unitname, json_encode(['field' => 'value']));

        $unit->add_member(1);
        $unit->add_member(2);

        // Get all members.
        $members = $unit->get_members();
        $this->assertContains("1", $members, "User 1 should be a member of {$unitname}");
        $this->assertContains("2", $members, "User 2 should be a member of {$unitname}");
    }
}
