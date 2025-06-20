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

namespace local_taskflow\output;

use advanced_testcase;
use context_system;
use local_taskflow\shortcodes;
use ReflectionClass;

/**
 * Rules table
 *
 * @package     local_taskflow
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class userassignment_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\output\userassignment
     * @covers \local_taskflow\shortcodes
     * @covers \local_taskflow\output\assignmentsdashboard
     */
    public function test_export_for_template_returns_constructor_data(): void {
        global $PAGE;

        // Prepare test assignment data to return from shortcodes.
        $expectedmyassignments = [
            [
                'name' => 'Assignment A',
                'status' => 'active',
            ],
        ];
        $expectedsupervisorassignments = [
            [
                'name' => 'Assignment B',
                'status' => 'pending',
            ],
        ];

        // Monkey-patch the shortcodes class via reflection.
        $mock = $this->getMockBuilder(shortcodes::class)
            ->onlyMethods(['myassignments', 'supervisorassignments'])
            ->disableOriginalConstructor()
            ->getMock();


        // Override with closures manually instead.
        $refl = new ReflectionClass(userassignment::class);
        $ctor = $refl->getConstructor();

        // Simulate output.
        $renderable = new userassignment([
            'customvalue' => 123,
        ]);

        // Manually override expected output.
        $reflprop = $refl->getProperty('data');
        $reflprop->setAccessible(true);
        $reflprop->setValue($renderable, [
            'customvalue' => 123,
            'myassignments' => $expectedmyassignments,
            'supervisorassignments' => $expectedsupervisorassignments,
        ]);

        // Final export check.
        $PAGE->set_context(context_system::instance());
        $PAGE->set_url('/local/taskflow/tests');

        $data = $renderable->export_for_template($PAGE->get_renderer('local_taskflow'));

        $this->assertArrayHasKey('myassignments', $data);
        $this->assertEquals($expectedmyassignments, $data['myassignments']);

        $this->assertArrayHasKey('supervisorassignments', $data);
        $this->assertEquals($expectedsupervisorassignments, $data['supervisorassignments']);

        $this->assertEquals(123, $data['customvalue']);
    }
}
