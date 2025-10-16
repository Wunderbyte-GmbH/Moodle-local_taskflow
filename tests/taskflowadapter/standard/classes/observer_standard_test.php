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
use tool_mocktesttime\time_mock;
use core\event\user_info_field_deleted;
use taskflowadapter_standard\observer;

/**
 * Test unit class of local_taskflow.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class observer_standard_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        $this->resetAfterTest(true);
        \local_taskflow\local\units\unit_relations::reset_instances();
    }

    /**
     * Test getting all members of a unit.
     * @covers \taskflowadapter_standard\observer
     * @covers \taskflowadapter_tuines\observer
     */
    public function test_user_info_field_deleted_unsets_config(): void {
        global $DB;

        $this->resetAfterTest(true);

        // Create a custom profile field.
        $data = (object)[
            'shortname' => 'mycustomfield',
            'name' => 'My Custom Field',
            'datatype' => 'text',
        ];
        $fieldid = $DB->insert_record('user_info_field', $data);

        // Set plugin config for this field.
        set_config('mycustomfield', 'somevalue', 'taskflowadapter_standard');
        $this->assertSame('somevalue', get_config('taskflowadapter_standard', 'mycustomfield'));

        // Build the event manually.
        $event = user_info_field_deleted::create([
            'context'  => \context_system::instance(),
            'objectid' => $fieldid,
            'other' => (array)$data,
        ]);
        $event->trigger();

        // Call the observer.
        observer::user_info_field_deleted($event);

        // Assert that config was removed.
        $this->assertIsString(get_config('taskflowadapter_standard', 'mycustomfield'));
    }
}
