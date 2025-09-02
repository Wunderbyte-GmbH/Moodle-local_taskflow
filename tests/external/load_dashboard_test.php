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

namespace local_taskflow\external;

use advanced_testcase;
use cache_helper;
use context_system;
use local_taskflow\local\dashboardcache\dashboardcache;
use local_taskflow\local\external_adapter\external_api_repository;

/**
 * Test unit class of local_taskflow.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class load_dashboard_test extends advanced_testcase {
    /** @var string|null Stores the external user data. */
    protected ?string $externaldata = null;

    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\external\load_dashboard
     * @covers \local_taskflow\output\dashboard
     * @runInSeparateProcess
     */
    public function test_execute_removes_user_from_cache(): void {
        global $PAGE;

        // Ensure the page has system context (like in execute()).
        $PAGE->set_context(context_system::instance());
        $this->setAdminUser();
        $result = load_dashboard::execute();

        // Basic structure.
        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('template', $result);
        $this->assertArrayHasKey('js', $result);

        // Data should be valid JSON.
        $decoded = json_decode($result['data'], true);
        $this->assertIsArray($decoded);

        // Template should be local_taskflow/dashboard.
        $this->assertEquals('local_taskflow/dashboard', $result['template']);
        $this->assertEquals('local_taskflow/dashboard', $decoded['template']);

        // JS footer code may be empty string or contain AMD requires.
        $this->assertIsString($result['js']);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\external\load_dashboard
     * @runInSeparateProcess
     */
    public function test_execute_returns_definition_matches_execute_returns(): void {
        $this->setAdminUser();
        $definition = load_dashboard::execute_returns();
        $keys = array_keys($definition->keys);
        $this->assertEqualsCanonicalizing(['data', 'template', 'js'], $keys);
    }
}
