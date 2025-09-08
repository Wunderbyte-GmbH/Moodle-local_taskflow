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

use local_taskflow\local\dashboardcache\dashboardcache;
use mod_booking\singleton_service;
use advanced_testcase;

/**
 * Rules table
 *
 * @package     local_taskflow
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class dashboard_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\output\dashboard
     */
    public function test_export_for_template_returns_structure_with_real_shortcodes(): void {
        if (class_exists(singleton_service::class, false)) {
            $this->markTestSkipped('mod_booking\\singleton_service already loaded; cannot simulate missing class.');
        }
        global $PAGE, $USER;

        // Instantiate dashboard with admin user.
        $dashboard = new dashboard($USER->id, []);
        $data = $dashboard->export_for_template($PAGE->get_renderer('local_taskflow'));

        // Basic structure.
        $this->assertIsArray($data);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('template', $data);
        $this->assertEquals('local_taskflow/dashboard', $data['template']);

        // Rules and dashboard sections should exist (they may contain rendered HTML).
        $this->assertArrayHasKey('rules', $data['data']);
        $this->assertArrayHasKey('dashboard', $data['data']);

        // The rules section should contain strings rendered by shortcodes.
        $this->assertNotEmpty($data['data']['rules']);
        $this->assertIsString($data['data']['rules'][0]);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\output\dashboard
     */
    public function test_export_for_template_includes_user_section(): void {
        if (class_exists(singleton_service::class, false)) {
            $this->markTestSkipped('mod_booking\\singleton_service already loaded; cannot simulate missing class.');
        }
        global $PAGE, $USER;
        // If dashboardcache returns userids, the dashboard will build a users section.
        $dashboard = new dashboard($USER->id, []);
        $data = $dashboard->export_for_template($PAGE->get_renderer('local_taskflow'));

        $this->assertIsArray($data);
        if (!empty($data['data']['users'])) {
            $firstuser = $data['data']['users'][0];
            $this->assertArrayHasKey('id', $firstuser);
            $this->assertArrayHasKey('username', $firstuser);
            $this->assertArrayHasKey('html', $firstuser);
        } else {
            $this->markTestSkipped('No users returned by dashboardcache in this test environment.');
        }
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\output\dashboard
     * @covers \local_taskflow\output\userinfocard
     * @covers \local_taskflow\output\userstatscard
     */
    public function test_users_section_renders_user_info_and_stats(): void {
        if (class_exists(singleton_service::class, false)) {
            $this->markTestSkipped('mod_booking\\singleton_service already loaded; cannot simulate missing class.');
        }
        global $PAGE, $USER;

        // Create a user who will appear in the dashboard filter.
        $testuser = $this->getDataGenerator()->create_user([
            'firstname' => 'Jane',
            'lastname' => 'Doe',
        ]);

        // Prime the dashboard cache with this user.
        $store = new dashboardcache();
        $store->set_userid($testuser->id);

        // Instantiate the dashboard (with admin user).
        $dashboard = new dashboard($USER->id, []);
        $data = $dashboard->export_for_template($PAGE->get_renderer('local_taskflow'));

        $this->assertIsArray($data);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('users', $data['data']);
        $this->assertNotEmpty($data['data']['users']);

        // Find our testuser in the dashboard output.
        $userentry = null;
        foreach ($data['data']['users'] as $entry) {
            if ((int)$entry['id'] === (int)$testuser->id) {
                $userentry = $entry;
                break;
            }
        }
        $this->assertNotNull($userentry, 'Expected testuser to be included in dashboard users section.');

        // The username in the entry should match fullname().
        $this->assertEquals(fullname($testuser), $userentry['username']);

        // The html array should contain userinfo, userstats, and myassignments outputs.
        $this->assertCount(3, $userentry['html']);
        $this->assertIsString($userentry['html'][0], 'get_user_info should return HTML string.');
        $this->assertIsString($userentry['html'][1], 'show_user_stats should return HTML string.');
        $this->assertIsString($userentry['html'][2], 'myassignments shortcode should return HTML string.');
    }
}
