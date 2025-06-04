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
use local_taskflow\local\actions\targets\types\bookingoption;
use stdClass;

/**
 * Test unit class of local_taskflow.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class bookingoption_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\actions\targets\types\bookingoption
     * @covers \local_taskflow\local\actions\targets\targets_base
     */
    public function test_instance_returns_valid_instance(): void {
        global $DB;

        // Skip if the table doesn't exist.
        if (!$DB->get_manager()->table_exists('booking_options')) {
            $this->markTestSkipped('Table booking_options does not exist in this environment.');
        }

        // Insert a test record.
        $record = new stdClass();
        $record->text = 'Test Booking Option';
        $record->courseid = 1;
        $record->description = 'Testing description';
        $record->bookingid = 1;
        $record->institution = '';
        $record->address = '';
        $record->pollurl = '';
        $record->howlong = '';
        $record->location = '';
        $record->daystonotify = 0;
        $record->bookingopeningtime = 0;
        $record->bookingclosingtime = 0;
        $record->timemodified = time();

        $id = $DB->insert_record('booking_options', $record);

        $instance = bookingoption::instance($id);

        $this->assertInstanceOf(bookingoption::class, $instance);
        // Singleton behavior.
        $sameinstance = bookingoption::instance($id);
        $this->assertSame($instance, $sameinstance);
        $this->assertSame($record->text, $instance->get_name());
        $this->assertSame($id, $instance->get_id());
    }
}
