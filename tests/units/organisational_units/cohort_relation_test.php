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

use cache_helper;
use advanced_testcase;
use local_taskflow\local\external_adapter\external_api_repository;

/**
 * Test unit class of local_taskflow.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class cohort_relation_test extends advanced_testcase {
    /** @var string|null Stores the external user data. */
    protected ?string $externaldata = null;

    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        \local_taskflow\local\units\unit_relations::reset_instances();
        $this->externaldata = file_get_contents(__DIR__ . '/../../mock/mock_user_cohort_data_hierarchy.json');
        $this->set_config_values();
    }

    /**
     * Setup the test environment.
     */
    protected function set_config_values(): void {
        global $DB;
        $settingvalues = [
            'translator_user_firstname' => "name->firstname",
            'translator_user_lastname' => "name->lastname",
            'translator_user_email' => "mail",
            'translator_user_units' => "ou",
            'organisational_unit_option' => "cohort",
        ];
        foreach ($settingvalues as $key => $value) {
            set_config($key, $value, 'local_taskflow');
        }
        cache_helper::invalidate_by_event('config', ['local_taskflow']);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\units\organisational_units\cohort
     * @covers \local_taskflow\local\units\organisational_unit_factory
     */
    public function test_construct(): void {
        global $DB;
        $apidatamanager = external_api_repository::create($this->externaldata);
        $externaldata = $apidatamanager->get_external_data();
        $this->assertNotEmpty($externaldata, 'External user data should not be empty.');
        $apidatamanager->process_incoming_data();
        $moodleusers = $DB->get_records('user');
        $this->assertCount(8, $moodleusers);
        $units = $DB->get_records('cohort');
        $this->assertCount(7, $units);
        $unitrelations = $DB->get_records('local_taskflow_unit_rel');
        $this->assertCount(6, $unitrelations);
        $unitmemebers = $DB->get_records('local_taskflow_unit_members');
        $this->assertCount(10, $unitmemebers);
    }

    /**
     * Setup the test environment.
     * @return int
     */
    protected function set_db_data(): int {
        global $DB;
        $record = (object) [
            'contextid' => 33,
            'name' => "Testing",
            'idnumber' => 32,
            'description' => "ououou",
            'descriptionformat' => 1,
            'visible' => 1,
            'component' => 'testing',
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        return $DB->insert_record('cohort', $record);
    }
}
