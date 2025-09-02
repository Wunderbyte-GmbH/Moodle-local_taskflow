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
final class search_users_test extends advanced_testcase {
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
     * @covers \local_taskflow\external\search_users
     * @covers \local_taskflow\local\supervisor\supervisor
     * @runInSeparateProcess
     */
    public function test_execute_returns_expected_list(): void {
        $this->setAdminUser();
        // Create some users via generator.
        $user1 = $this->getDataGenerator()->create_user(['firstname' => 'Alice', 'lastname' => 'Wonder']);
        $user2 = $this->getDataGenerator()->create_user(['firstname' => 'Bob', 'lastname' => 'Builder']);

        $result = search_users::execute('Alice');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('list', $result);
        $this->assertArrayHasKey('warnings', $result);

        $this->assertIsArray($result['list']);
        $this->assertIsString($result['warnings']);

        // Expect at least Alice to be present in the result set.
        $found = false;
        foreach ($result['list'] as $record) {
            if ($record->id === $user1->id) {
                $found = true;
                $this->assertEquals('Alice', $record->firstname);
                $this->assertEquals('Wonder', $record->lastname);
            }
        }
        $this->assertTrue($found, 'Expected user Alice to be returned from search_users::execute().');
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\external\search_users
     * @runInSeparateProcess
     */
    public function test_execute_returns_definition_matches_execute_returns(): void {
        $this->setAdminUser();
        $definition = search_users::execute_returns();
        $keys = array_keys($definition->keys);

        $this->assertEqualsCanonicalizing(['list', 'warnings'], $keys);
    }
}
