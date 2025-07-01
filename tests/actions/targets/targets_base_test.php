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
use local_taskflow\local\actions\targets\targets_base;
use local_taskflow\local\actions\targets\targets_factory;

/**
 * Test unit class of local_taskflow.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class targets_base_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Simple concrete class for testing.
     */
    private function get_mock_target(int $id, string $name): targets_base {
        return new class ($id, $name) extends targets_base {
            /**
             * Simple concrete class for testing.
             * @param int $id
             * @param string $name
             */
            public function __construct(int $id, string $name) {
                $this->id = $id;
                $this->name = $name;
            }
        };
    }

    /**
     * testing
     * @covers \local_taskflow\local\actions\targets\targets_factory
     */
    public function test_getters(): void {
        $this->resetAfterTest(true);

        $mock = $this->get_mock_target(42, 'Test Target');

        $this->assertSame(42, $mock->get_id());
        $this->assertSame('Test Target', $mock->get_name());
    }
}
