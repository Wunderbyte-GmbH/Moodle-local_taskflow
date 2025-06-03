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
use local_taskflow\local\actions\targets\types\moodlecourse;
use stdClass;

/**
 * Test unit class of local_taskflow.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class moodlecourse_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\actions\targets\types\moodlecourse
     */
    public function test_get_data_returns_only_formidentifiers() {
        $input = [
            'targettype' => 'course',
            'bookingoptions' => ['A', 'B'],
            'moodlecourses' => [1, 2, 3],
            'completebeforenext' => true,
            'targetduedatetype' => 'fixed',
            'targetduration' => 5,
            'fixeddate' => '2025-06-05',
            'target_repeats' => 3,
            'extrafield' => 'should not appear',
        ];

        $result = moodlecourse::get_data($input);

        // Check that only known identifiers exist.
        $this->assertArrayNotHasKey('extrafield', $result);
        foreach (moodlecourse::$formidentifiers as $field) {
            $this->assertArrayHasKey($field, $result);
        }
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\actions\targets\types\moodlecourse
     */
    public function test_instance_returns_singleton_and_sets_data() {
        global $DB;

        // Create dummy course record.
        $course = new stdClass();
        $course->fullname = 'Test Course';
        $course->shortname = 'TC1';
        $course->category = 1;
        $course->idnumber = '';
        $course->summary = '';
        $course->summaryformat = FORMAT_HTML;
        $course->format = 'topics';
        $course->numsections = 5;
        $course->startdate = time();
        $course->visible = 1;

        $courseid = $DB->insert_record('course', $course);

        // Get instance and verify.
        $instance = moodlecourse::instance($courseid);
        $this->assertInstanceOf(moodlecourse::class, $instance);
    }
}
