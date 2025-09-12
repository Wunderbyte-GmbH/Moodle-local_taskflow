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

namespace local_taskflow\assignment_status;

use advanced_testcase;
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\assignment_status\types\assigned;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/user/profile/lib.php');

/**
 * Test unit class of local_taskflow.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class assignment_status_facade_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        \local_taskflow\local\units\unit_relations::reset_instances();
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\assignment_status\assignment_status_facade
     * @covers \local_taskflow\local\assignment_status\assignment_status_base
     * @covers \local_taskflow\local\assignment_status\types\assigned
     * @covers \local_taskflow\local\assignment_status\types\completed
     * @covers \local_taskflow\local\assignment_status\types\droppedout
     * @covers \local_taskflow\local\assignment_status\types\enrolled
     * @covers \local_taskflow\local\assignment_status\types\overdue
     * @covers \local_taskflow\local\assignment_status\types\planned
     * @covers \local_taskflow\local\assignment_status\types\paused
     * @covers \local_taskflow\local\assignment_status\types\prolonged
     * @covers \local_taskflow\local\assignment_status\types\reprimand
     * @covers \local_taskflow\local\assignment_status\types\sanction
     * @covers \local_taskflow\local\assignment_status\types\partially_completed
     *
     */
    public function test_external_data_is_loaded(): void {
        global $DB;
        $assignment = (object)$this->get_assignment();
        assignment_status_facade::change_status(
            $assignment,
            9999
        );
        assignment_status_facade::change_status(
            $assignment,
            assignment_status_facade::get_status_identifier('assigned')
        );
        $this->assertEquals($assignment->active, 1);
        $this->assertEquals($assignment->status, 0);
        $assigned = assigned::get_instance();
        $assigned->execute((array)$assignment);
        $this->assertEquals($assigned->get_activation(), $assignment->active);
        $this->assertEquals(
            $assigned->get_activation(),
            assignment_status_facade::get_status_activation('assigned')
        );
        assignment_status_facade::change_status(
            $assignment,
            assignment_status_facade::get_status_identifier('completed')
        );
        $this->assertEquals($assignment->active, 1);
        $this->assertEquals($assignment->status, 15);
        assignment_status_facade::change_status(
            $assignment,
            assignment_status_facade::get_status_identifier('droppedout')
        );
        $this->assertEquals($assignment->active, 0);
        $this->assertEquals($assignment->status, 16);
        $this->assertNull($assignment->duedate);
        assignment_status_facade::change_status(
            $assignment,
            assignment_status_facade::get_status_identifier('enrolled')
        );
        $this->assertEquals($assignment->active, 1);
        $this->assertEquals($assignment->status, 0);
        assignment_status_facade::change_status(
            $assignment,
            assignment_status_facade::get_status_identifier('overdue')
        );
        $this->assertEquals($assignment->active, 1);
        $this->assertEquals($assignment->status, 10);
        assignment_status_facade::change_status(
            $assignment,
            assignment_status_facade::get_status_identifier('planned')
        );
        $this->assertEquals($assignment->active, 0);
        $this->assertEquals($assignment->active, 0);
        $this->assertEquals($assignment->status, -1);
        assignment_status_facade::change_status(
            $assignment,
            assignment_status_facade::get_status_identifier('prolonged')
        );
        $this->assertEquals($assignment->active, 1);
        $this->assertEquals($assignment->status, 5);
        $this->assertEquals($assignment->prolongedcounter, 1);
        assignment_status_facade::change_status(
            $assignment,
            assignment_status_facade::get_status_identifier('reprimand')
        );
        $this->assertEquals($assignment->active, 1);
        $this->assertEquals($assignment->status, 11);
        assignment_status_facade::change_status(
            $assignment,
            assignment_status_facade::get_status_identifier('sanction')
        );
        $this->assertEquals($assignment->active, 1);
        $this->assertEquals($assignment->status, 12);
        assignment_status_facade::change_status(
            $assignment,
            assignment_status_facade::get_status_identifier('paused')
        );
        $this->assertEquals($assignment->active, 0);
        $this->assertEquals($assignment->status, 4);
        assignment_status_facade::change_status(
            $assignment,
            assignment_status_facade::get_status_identifier('partially_completed')
        );
        $this->assertEquals($assignment->active, 1);
        $this->assertEquals($assignment->status, 7);
        $oldassignment = (object)$this->get_assignment();
        assignment_status_facade::execute(
            $assignment,
            (array)$oldassignment
        );
    }

    /**
     * Example test: Ensure external data is loaded.
     * @return array
     */
    private function get_assignment(): array {
        $assignment = [
            "id" => 1,
            "targets" => [
                [
                    "targetid" => 11,
                    "targettype" => "moodlecourse",
                    "targetname" => "mytargetname2",
                    "sortorder" => 2,
                    "actiontype" => "enroll",
                    "completebeforenext" => false,
                ],
            ],
            "messages" => [],
            "userid" => 12,
            "ruleid" => 4,
            "unitid" => 3,
            "active" => 1,
            "status" => 0,
            "duedate" => time(),
            "assigneddate" => time(),
            "completeddate" => time(),
            "usermodified" => 1,
            "timecreated" => 1,
            "timemodified" => 1,
            "keepchagnes" => "0",
            "overduecounter" => "0",
            "prolongedcounter" => "0",
        ];
        return $assignment;
    }
}
