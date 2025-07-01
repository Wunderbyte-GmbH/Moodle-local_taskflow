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

namespace local_taskflow\supervisor;

use advanced_testcase;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/user/profile/lib.php');

/**
 * Test unit class of local_taskflow.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class supervisor_test extends advanced_testcase {
    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\supervisor\supervisor
     */
    public function test_get_supervisor_for_user_returns_correct_supervisor(): void {
        global $DB;

        $this->resetAfterTest(true);

        // Create supervisor and user.
        $supervisor = $this->getDataGenerator()->create_user(['firstname' => 'Super', 'lastname' => 'Visor']);
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Regular', 'lastname' => 'User']);

        // Create profile field for supervisor reference.
        $field = (object)[
            'shortname' => 'supervisor_id',
            'name' => 'Supervisor ID',
            'datatype' => 'text',
        ];
        $fieldid = $DB->insert_record('user_info_field', $field);

        // Store the supervisor ID as user profile data.
        $data = (object)[
            'userid' => $user->id,
            'fieldid' => $fieldid,
            'data' => (string)$supervisor->id,
        ];
        $DB->insert_record('user_info_data', $data);

        // Set the config so that get_supervisor_for_user knows which field to use.
        set_config('supervisor_field', 'supervisor_id', 'local_taskflow');

        // Call the method under test.
        $result = \local_taskflow\local\supervisor\supervisor::get_supervisor_for_user($user->id);

        // Assertions.
        $this->assertNotEmpty($result);
        $this->assertEquals($supervisor->id, $result->id);
        $this->assertEquals('Super', $result->firstname);
        $this->assertEquals('Visor', $result->lastname);
    }
}
