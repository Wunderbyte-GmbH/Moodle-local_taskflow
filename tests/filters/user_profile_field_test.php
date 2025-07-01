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

use local_taskflow\local\external_adapter\external_api_repository;
use stdClass;
use cache_helper;
use advanced_testcase;
use local_taskflow\local\rules\unit_rules;
use local_taskflow\local\units\organisational_unit_factory;

/**
 * Test unit class of local_taskflow.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class user_profile_field_test extends advanced_testcase {
    /** @var string|null Stores the external user data. */
    protected ?string $externaldata = null;

    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        \local_taskflow\local\units\unit_relations::reset_instances();
        $this->externaldata = file_get_contents(__DIR__ . '/../mock/mock_user_cohort_data_rule.json');
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
     * @covers \local_taskflow\local\rules\unit_rules
     * @covers \local_taskflow\local\filters\types\user_profile_field
     * @covers \local_taskflow\local\filters\filter_factory
     * @covers \local_taskflow\local\assignments\assignments_facade
     * @covers \local_taskflow\local\assignments\types\standard_assignment
     * @covers \local_taskflow\local\assignment_process\assignment_controller
     * @covers \local_taskflow\local\assignment_process\assignments\assignments_controller
     * @covers \local_taskflow\local\assignment_process\filters\filters_controller
     * @covers \local_taskflow\local\assignment_operators\filter_operator
     */
    public function test_construct(): void {
        global $DB;
        $apidatamanager = external_api_repository::create($this->externaldata);
        $externaldata = $apidatamanager->get_external_data();
        $this->assertNotEmpty($externaldata, 'External user data should not be empty.');
        $apidatamanager->process_incoming_data();
        $units = $DB->get_records('cohort');

        $this->add_rules(array_shift($units));

        $units = $DB->get_records('cohort');
        $unit = array_shift($units);
        $unitinstance = organisational_unit_factory::instance($unit->id);
        $unitinstance->update('Unit after update');
    }

    /**
     * Setup the test environment.
     * @param stdClass $unit
     * @return void
     */
    protected function add_rules($unit): void {
        global $DB;
        $files = [
            '/../mock/rules/taskflow_rule_template.json' => 1,
            '/../mock/rules/taskflow_rule_second_template.json' => 0,
            '/../mock/rules/taskflow_rule_third_template.json' => 1,
        ];
        foreach ($files as $filename => $isactive) {
            $rulejson = file_get_contents(__DIR__ . $filename);
            $rulejson = json_encode(json_decode($rulejson, true), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $record = (object) [
                'unitid' => $unit->id,
                'rulejson' => $rulejson,
                'isactive' => $isactive,
            ];
            $unitrules = unit_rules::create_rule($record);
            $sameunitrules = unit_rules::create_rule($record);
            $this->assertEquals($unitrules, $sameunitrules);
        }
        $this->assertTrue(3 <= $DB->get_records('local_taskflow_rules'));
        $this->assertTrue(3 <= $unitrules);
    }
}
