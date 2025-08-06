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
use local_taskflow\local\actions\types\enroll;
use mod_booking\booking_option_settings;
use mod_booking\singleton_service;
use stdClass;

/**
 * Test unit class of local_taskflow.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class enroll_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\actions\types\enroll
     * @covers \local_taskflow\local\assignment_process\assignment_preprocessor
     */
    public function test_enroll_competency(): void {
        global $DB;
        // Create user.
        $user = $this->getDataGenerator()->create_user();

        // Create a test competency.
        $competency = (object)[
            'shortname' => 'Testing',
            'description' => '',
            'descriptionformat' => FORMAT_HTML,
            'competencyframeworkid' => 1,
            'sortorder' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
            'usermodified' => $user->id,
        ];

        $competencyid = $DB->insert_record('competency', $competency);

        $target = (object)[
            'targettype' => 'competency',
            'targetid' => $competencyid,
        ];

        $action = new enroll($target, $user->id);
        $this->assertTrue($action->is_active());
        $this->assertTrue($action->execute());
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\actions\types\enroll
     * @covers \local_taskflow\local\assignment_process\assignment_preprocessor
     *
     */
    public function test_enroll_course(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();

        // Add manual enrolment method.
        $enrolplugin = enrol_get_plugin('manual');
        $enrolid = $DB->insert_record('enrol', (object)[
            'enrol' => 'manual',
            'status' => 0,
            'courseid' => $course->id,
        ]);
        $instance = $DB->get_record('enrol', ['id' => $enrolid]);

        $target = (object)[
            'targettype' => 'course',
            'targetid' => $course->id,
        ];

        $action = new enroll($target, $user->id);

        $this->assertTrue($action->is_active());
        $action->manualinstance = $instance;
        $this->assertTrue($action->execute());

        $context = \context_course::instance($course->id);
        $this->assertTrue(is_enrolled($context, $user));
    }
}
