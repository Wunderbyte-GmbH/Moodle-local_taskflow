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
use context_system;

/**
 * Test unit class of local_taskflow.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class singleton_service_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Reset the private static singleton instance between tests.
     */
    protected function reset_singleton(): void {
        $ref = new \ReflectionClass(singleton_service::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null);
    }

    /**
     * Test getting all members of a unit.
     * @covers \local_taskflow\singleton_service
     */
    public function test_assignmentsdashboard_renders_output(): void {
        $a = singleton_service::get_instance();
        $b = singleton_service::get_instance();
        $this->assertSame(\spl_object_id($a), \spl_object_id($b), 'Expected same singleton instance');
    }

    /**
     * Test getting all members of a unit.
     * @covers \local_taskflow\singleton_service
     */
    public function test_get_instance_of_user_caches_user_object(): void {
        $user = $this->getDataGenerator()->create_user();
        $u1 = singleton_service::get_instance_of_user($user->id, false);
        $u2 = singleton_service::get_instance_of_user($user->id, false);

        $this->assertSame(\spl_object_id($u1), \spl_object_id($u2), 'Expected cached same stdClass instance');
        $this->assertObjectNotHasProperty('profile', $u1, 'Profile should not be loaded when not requested');
    }

    /**
     * Test getting all members of a unit.
     * @covers \local_taskflow\singleton_service
     */
    public function test_requesting_profile_fields_first_time_sets_and_keeps_them(): void {
        $user = $this->getDataGenerator()->create_user();

        $first = singleton_service::get_instance_of_user($user->id, true);
        $this->assertTrue(property_exists($first, 'profile'));
        $this->assertIsArray($first->profile);

        // Subsequent call without requesting should still have profile on the same instance.
        $second = singleton_service::get_instance_of_user($user->id, false);
        $this->assertSame(\spl_object_id($first), \spl_object_id($second));
        $this->assertTrue(property_exists($second, 'profile'));
        $this->assertIsArray($second->profile);
    }
}
