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
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\units;

use advanced_testcase;
use local_taskflow\local\external_adapter\external_api_base;
use local_taskflow\local\units\organisational_units_factory;


/**
 * Class unit_member
 *
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class cohorts_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        \local_taskflow\local\units\unit_relations::reset_instances();
        set_config(
            'organisational_unit_option',
            'cohort',
            'local_taskflow'
        );
    }

    /**
     * Tear down the test environment.
     *
     * @return void
     *
     */
    protected function tearDown(): void {
        parent::tearDown();
        external_api_base::teardown();
        \local_taskflow\local\units\unit_relations::reset_instances();
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\units\organisational_units\cohorts
     * @covers \local_taskflow\local\units\organisational_units_factory
     */
    public function test_construct(): void {
        global $DB, $USER, $CFG;
        require_once($CFG->dirroot . '/cohort/lib.php');

        $cohort = (object)[
            'name' => 'Test Cohort',
            'contextid' => \context_system::instance()->id,
            'idnumber' => '',
            'description' => 'Test description',
            'descriptionformat' => FORMAT_HTML,
            'component' => '',
        ];
        $cohort->id = cohort_add_cohort($cohort);

        $unitsinstance = organisational_units_factory::instance();
        $result = $unitsinstance->get_units();

        $this->assertCount(1, $result);
        $this->assertArrayHasKey($cohort->id, $result);
    }
}
