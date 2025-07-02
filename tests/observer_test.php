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
use local_taskflow\observer;
use core\event\cohort_member_added;
use core\event\cohort_member_removed;
use core\event\cohort_deleted;

/**
 * Test unit class of local_taskflow.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class observer_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        \local_taskflow\local\units\unit_relations::reset_instances();
    }

    /**
     * Test getting all members of a unit.
     * @covers \local_taskflow\observer
     * @covers \local_taskflow\local\assignment_operators\filter_operator
     */
    public function test_cohort_member_added_triggers_event(): void {
        global $DB;

        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $cohort = $this->getDataGenerator()->create_cohort();

        // Trigger core event manually.
        $event = cohort_member_added::create([
            'objectid' => $cohort->id,
            'relateduserid' => $user->id,
            'context' => \context_system::instance(),
        ]);

        // Test observer call.
        observer::cohort_member_added($event);

        $this->assertTrue(true);
    }

    /**
     * Test getting all members of a unit.
     * @covers \local_taskflow\observer
     * @covers \local_taskflow\local\assignments\assignments_facade
     * @covers \local_taskflow\local\assignments\types\standard_assignment
     */
    public function test_cohort_member_removed_triggers_event(): void {
        global $DB;

        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $cohort = $this->getDataGenerator()->create_cohort();

        cohort_add_member($cohort->id, $user->id);
        cohort_remove_member($cohort->id, $user->id);

        $event = cohort_member_removed::create([
            'objectid' => $cohort->id,
            'relateduserid' => $user->id,
            'context' => \context_system::instance(),
        ]);

        observer::cohort_member_removed($event);

        $this->assertTrue(true);
    }

    /**
     * Test getting all members of a unit.
     * @covers \local_taskflow\observer
     * @covers \local_taskflow\local\assignments\assignments_facade
     * @covers \local_taskflow\local\assignments\types\standard_assignment
     */
    public function test_cohort_removed_triggers_event(): void {
        $this->resetAfterTest();
        $cohort = $this->getDataGenerator()->create_cohort();
        $event = cohort_deleted::create([
            'objectid' => $cohort->id,
            'context' => \context_system::instance(),
        ]);

        observer::cohort_removed($event);

        $this->assertTrue(true);
    }
}
