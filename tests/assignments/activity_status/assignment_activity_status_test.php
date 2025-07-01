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

namespace local_taskflow\assignments\activity_status;

use advanced_testcase;
use local_taskflow\local\assignments\activity_status\assignment_activity_status;
use stdClass;
use local_taskflow\local\assignments\assignment;

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
final class assignment_activity_status_test extends advanced_testcase {
    /**assignment_activity_status_test
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        \local_taskflow\local\units\unit_relations::reset_instances();
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\assignments\activity_status\assignment_activity_status
     */
    public function test_get_all_returns_all_statuses(): void {
        $statuses = assignment_activity_status::get_all();

        $this->assertIsArray($statuses);
        $this->assertArrayHasKey(assignment_activity_status::PAUSED, $statuses);
        $this->assertArrayHasKey(assignment_activity_status::INACTIVE, $statuses);
        $this->assertArrayHasKey(assignment_activity_status::ACTIVE, $statuses);

        $this->assertEquals(get_string('activitypaused', 'local_taskflow'), $statuses[assignment_activity_status::PAUSED]);
        $this->assertEquals(get_string('activityinactive', 'local_taskflow'), $statuses[assignment_activity_status::INACTIVE]);
        $this->assertEquals(get_string('activityactive', 'local_taskflow'), $statuses[assignment_activity_status::ACTIVE]);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\assignments\activity_status\assignment_activity_status
     */
    public function test_get_label_returns_correct_label(): void {
        $labelpaused = assignment_activity_status::get_label(assignment_activity_status::PAUSED);
        $labelinactive = assignment_activity_status::get_label(assignment_activity_status::INACTIVE);
        $labelactive = assignment_activity_status::get_label(assignment_activity_status::ACTIVE);

        $this->assertEquals(get_string('activitypaused', 'local_taskflow'), $labelpaused);
        $this->assertEquals(get_string('activityinactive', 'local_taskflow'), $labelinactive);
        $this->assertEquals(get_string('activityactive', 'local_taskflow'), $labelactive);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\assignments\activity_status\assignment_activity_status
     */
    public function test_get_label_returns_fallback_for_unknown_status(): void {
        $label = assignment_activity_status::get_label(999);
        $this->assertEquals(get_string('statusunknown', 'local_taskflow'), $label);
    }
}
