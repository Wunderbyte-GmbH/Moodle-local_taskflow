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
final class clear_dashboard_cache_test extends advanced_testcase {
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
     * @covers \local_taskflow\external\clear_dashboard_cache
     * @covers \local_taskflow\local\dashboardcache\dashboardcache
     * @runInSeparateProcess
     */
    public function test_execute_removes_user_from_cache(): void {
        global $USER;

        // Create and log in a user.
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        // Seed the dashboard cache with this user.
        $store = new dashboardcache();
        $store->set_userid($USER->id);

        $filter = $store->get_all_users();
        $this->assertArrayHasKey('userids', $filter);
        $this->assertArrayHasKey($USER->id, $filter['userids']);

        // First call: user should be removed.
        $result = clear_dashboard_cache::execute($USER->id);
        $this->assertEquals('removed', $result['status']);
        $this->assertStringContainsString((string)$USER->id, $result['message']);

        // Cache should no longer contain this user.
        $filter = $store->get_all_users();
        $this->assertArrayNotHasKey($USER->id, $filter['userids'] ?? []);

        // Second call: user is already missing.
        $result = clear_dashboard_cache::execute($USER->id);
        $this->assertEquals('missing', $result['status']);
        $this->assertStringContainsString('not present', $result['message']);
    }
}
