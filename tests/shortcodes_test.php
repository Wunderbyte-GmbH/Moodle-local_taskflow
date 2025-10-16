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
use tool_mocktesttime\time_mock;

/**
 * Test unit class of local_taskflow.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class shortcodes_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        $this->resetAfterTest(true);
        \local_taskflow\local\units\unit_relations::reset_instances();
    }

    /**
     * Test getting all members of a unit.
     * @covers \local_taskflow\shortcodes
     * @covers \local_taskflow\output\assignmentsdashboard
     * @covers \local_taskflow\local\assignments\assignment_query_builder
     *
     */
    public function test_assignmentsdashboard_renders_output(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $output = shortcodes::assignmentsdashboard(
            'shortcode',
            [
                'columns' => 'fullname,status',
                'toclarify' => '1',
                'status' => '1,2,3,4,5',
            ],
            null,
            (object)[],
            function () {
            }
        );

        $this->assertStringContainsString('assignmentstable', $output);
    }

    /**
     * Test getting all members of a unit.
     * @covers \local_taskflow\shortcodes
     * @covers \local_taskflow\output\assignmentsdashboard
     */
    public function test_rulesdashboard_renders_output(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $output = shortcodes::rulesdashboard(
            'shortcode',
            [],
            null,
            (object)[],
            function () {
            }
        );

        $this->assertStringContainsString('Rules dashboard', $output);
    }

    /**
     * Test getting all members of a unit.
     * @covers \local_taskflow\shortcodes
     * @covers \local_taskflow\output\assignmentsdashboard
     * @covers \local_taskflow\local\assignments\assignment_query_builder
     *
     */
    public function test_supervisordashboard_renders_output(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $output = shortcodes::supervisorassignments(
            'shortcode',
            [],
            null,
            (object)[],
            function () {
            }
        );

        $this->assertStringContainsString('supervisor', $output);
        $output = shortcodes::supervisorassignments(
            'shortcode',
            [
                'active' => 2,
                'toclarify' => 1,
                'assignmentstatus' => '0,1,2,3,4,5,overdue,assigned',
                'counters' => 'overdue;>=;1,dueover;>=;1,overdue;=>;testing,overdue;==;testing',
            ],
            null,
            (object)[],
            function () {
            }
        );
        $this->assertStringContainsString('Overdue', $output);
    }
}
