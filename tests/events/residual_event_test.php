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
 * Unit class to manage users.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\events;

use advanced_testcase;
use local_taskflow\event\unit_relation_updated;
use local_taskflow\event\unit_member_updated;
use local_taskflow\event\unit_updated;

/**
 * Class unit_member
 *
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class residual_event_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }


    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\event\unit_updated
     * @covers \local_taskflow\event\unit_member_updated
     * @covers \local_taskflow\event\unit_relation_updated
     */
    public function test_construct(): void {
        $events = [
            unit_relation_updated::class,
            unit_member_updated::class,
            unit_updated::class,
        ];

        foreach ($events as $eventclass) {
            $event = $eventclass::create([
                'objectid' => 1,
                'context'  => \context_system::instance(),
                'userid'   => 1,
                'other'    => [
                    'parent' => (int) 2,
                    'child' => (int) 1,
                ],
            ]);
            $this->assertIsString($event->get_name());
            $this->assertIsString($event->get_description());
            $this->assertNotEmpty($event->get_url());
        }
    }
}
