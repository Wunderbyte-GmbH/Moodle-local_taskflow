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
use local_taskflow\local\actions\targets\types\competency;
use stdClass;

/**
 * Test unit class of local_taskflow.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class competency_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\actions\targets\types\competency
     */
    public function test_instance_returns_valid_instance() {
        global $DB;

        // Create dummy competency record.
        $record = new stdClass();
        $record->shortname = 'Test Competency';
        $record->idnumber = 'TEST1';
        $record->description = 'Unit test competency';
        $record->descriptionformat = FORMAT_HTML;
        $record->competencyframeworkid = 1;
        $record->sortorder = 0;
        $record->parentid = 0;
        $record->timecreated = time();
        $record->timemodified = time();

        $id = $DB->insert_record('competency', $record);

        $instance = competency::instance($id);

        $this->assertInstanceOf(competency::class, $instance);
        // Test Singleton behavior.
        $sameinstance = competency::instance($id);
        $this->assertSame($instance, $sameinstance);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\actions\targets\types\competency
     */
    public function test_instance_returns_false_if_not_found() {
        $nonexistentid = 999999; // Assuming this ID doesn't exist.
        $this->assertFalse(competency::instance($nonexistentid));
    }
}
