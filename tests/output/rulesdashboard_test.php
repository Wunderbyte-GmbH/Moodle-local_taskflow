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
use local_taskflow\local\external_adapter\external_api_base;
use renderer_base;

/**
 * Rules table
 *
 * @package     local_taskflow
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rulesdashboard_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Tear down the test environment.
     *
     * @return void
     *
     */
    protected function tearDown(): void {
        parent::tearDown();
        external_api_base::teardown();
        \local_taskflow\local\units\unit_relations::reset_instances();
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\output\rulesdashboard
     */
    public function test_export_for_template_contains_table_and_url(): void {
        $input = ['heading' => 'Dashboard Title'];
        $dashboard = new rulesdashboard($input);

        $dummyrenderer = $this->createMock(renderer_base::class);
        $data = $dashboard->export_for_template($dummyrenderer);

        // Check that our stubbed HTML was used.
        $this->assertArrayHasKey('table', $data);
        $this->assertStringContainsString('norecordsfound', $data['table']);

        // Check that the export adds the expected URL.
        $this->assertArrayHasKey('url', $data);
        $this->assertStringContainsString('/local/taskflow/editrule.php?id=0', $data['url']);
    }
}
