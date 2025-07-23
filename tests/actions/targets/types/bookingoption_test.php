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
use mod_booking\booking_option_settings;
use mod_booking\singleton_service;

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
     * @covers \local_taskflow\local\assignment_process\assignment_preprocessor
     */
    public function test_instance_returns_valid_instance(): void {
        global $DB;

        // Setup test data.
        $course = $this->getDataGenerator()->create_course([]);

        // Create users.
        $admin = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $bookingoption = (object)[
            'text' => 'Test Booking Option',
            'description' => 'This is a test booking option.',
            'courseid' => $course->id,
            'bookingid' => $booking1->id,
        ];

        $bookingoption->id = $DB->insert_record('booking_options', $bookingoption);
        $settings = singleton_service::get_instance_of_booking_option_settings($bookingoption->id);
        $this->assertInstanceOf(booking_option_settings::class, $settings);
    }
}
