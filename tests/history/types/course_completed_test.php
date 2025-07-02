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

namespace local_taskflow\history\types;

use advanced_testcase;
use local_taskflow\local\history\types\course_completed;

/**
 * Test unit class of local_taskflow.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class course_completed_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\history\types\course_completed
     * @covers \local_taskflow\local\history\types\base
     */
    public function test_has_additional_data_always_returns_true(): void {
        $jsondata = (object)[
            'data' => (object)[
                'change_reason' => 1,
                'comment' => 'Updated for accuracy',
                'status' => 3,
            ],
        ];
        $json = json_encode($jsondata);
        $change = new course_completed('rule_change', $json);
        $output = $change->render_additional_data();

        $this->assertIsString($output);
        $this->assertTrue($change->has_additional_data());
    }
}
