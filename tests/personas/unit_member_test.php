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

namespace local_taskflow\local\personas;

use advanced_testcase;

/**
 * Class unit_member
 *
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class unit_member_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\personas\unit_member::create
     * @covers \local_taskflow\local\personas\unit_member::instance
     * @covers \local_taskflow\local\personas\unit_member::__construct
     * @covers \local_taskflow\local\personas\unit_member::get_id
     * @covers \local_taskflow\local\personas\unit_member::get_unitid
     * @covers \local_taskflow\local\personas\unit_member::get_userid
     */
    public function test_construct(): void {
        $unitid = 2;
        $userid = 3;

        $unitmember = unit_member::create($userid, $unitid);
        $unitmemberinstance = unit_member::instance($unitmember->get_id());

        $this->assertEquals($unitmemberinstance->get_unitid(), 2);
        $this->assertEquals($unitmemberinstance->get_userid(), 3);
    }
}
