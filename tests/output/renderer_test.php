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
use templatable;

/**
 * Rules table
 *
 * @package     local_taskflow
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class renderer_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        \local_taskflow\local\units\unit_relations::reset_instances();
        set_config(
            'organisational_unit_option',
            'cohort',
            'local_taskflow'
        );
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\output\renderer
     */
    public function test_render_rulesdashboard(): void {
        $renderer = $this->getMockBuilder(renderer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['render_from_template'])
            ->getMock();

        $fakedata = ['some' => 'value'];

        $templatablemock = $this->createMock(templatable::class);
        $templatablemock->expects($this->once())
            ->method('export_for_template')
            ->with($renderer)
            ->willReturn($fakedata);

        $renderer->expects($this->once())
            ->method('render_from_template')
            ->with('local_taskflow/dashboards/dashboard_rules', $fakedata)
            ->willReturn('rendered_rules');

        $this->assertEquals('rendered_rules', $renderer->render_rulesdashboard($templatablemock));
    }

    /**
     * Test render_assignmentsdashboard returns rendered template output.
     * @covers \local_taskflow\output\renderer
     */
    public function test_render_assignmentsdashboard(): void {
        $renderer = $this->getMockBuilder(renderer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['render_from_template'])
            ->getMock();

        $fakedata = ['another' => 'thing'];

        $templatablemock = $this->createMock(templatable::class);
        $templatablemock->expects($this->once())
            ->method('export_for_template')
            ->with($renderer)
            ->willReturn($fakedata);

        $renderer->expects($this->once())
            ->method('render_from_template')
            ->with('local_taskflow/dashboards/dashboard_assignments', $fakedata)
            ->willReturn('rendered_assignments');

        $this->assertEquals('rendered_assignments', $renderer->render_assignmentsdashboard($templatablemock));
    }

    /**
     * Test render_assignmentsdashboard returns rendered template output.
     * @covers \local_taskflow\output\renderer
     */
    public function test_render_userassignment(): void {
        $renderer = $this->getMockBuilder(renderer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['render_from_template'])
            ->getMock();

        $fakedata = ['user' => 'data'];

        $templatablemock = $this->createMock(templatable::class);
        $templatablemock->expects($this->once())
            ->method('export_for_template')
            ->with($renderer)
            ->willReturn($fakedata);

        $renderer->expects($this->once())
            ->method('render_from_template')
            ->with('local_taskflow/userassignment', $fakedata)
            ->willReturn('rendered_userassignment');

        $this->assertEquals('rendered_userassignment', $renderer->render_userassignment($templatablemock));
    }

    /**
     * Test render_assignmentsdashboard returns rendered template output.
     * @covers \local_taskflow\output\renderer
     */
    public function test_render_editassignment(): void {
        $renderer = $this->getMockBuilder(renderer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['render_from_template'])
            ->getMock();

        $fakedata = ['edit' => 'value'];

        $templatablemock = $this->createMock(templatable::class);
        $templatablemock->expects($this->once())
            ->method('export_for_template')
            ->with($renderer)
            ->willReturn($fakedata);

        $renderer->expects($this->once())
            ->method('render_from_template')
            ->with('local_taskflow/editassignment', $fakedata)
            ->willReturn('rendered_editassignment');

        $this->assertEquals('rendered_editassignment', $renderer->render_editassignment($templatablemock));
    }

    /**
     * Test render_assignmentsdashboard returns rendered template output.
     * @covers \local_taskflow\output\renderer::render_history
     */
    public function test_render_history(): void {
        $renderer = $this->getMockBuilder(renderer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['render_from_template'])
            ->getMock();

        $fakedata = ['history' => 'stuff'];

        $templatablemock = $this->createMock(templatable::class);
        $templatablemock->expects($this->once())
            ->method('export_for_template')
            ->with($renderer)
            ->willReturn($fakedata);

        $renderer->expects($this->once())
            ->method('render_from_template')
            ->with('local_taskflow/history', $fakedata)
            ->willReturn('rendered_history');

        $this->assertEquals('rendered_history', $renderer->render_history($templatablemock));
    }
}
