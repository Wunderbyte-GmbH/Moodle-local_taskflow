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

namespace local_taskflow\eventhandlers;

use advanced_testcase;
use cache_helper;
use local_taskflow\local\external_adapter\external_api_factory;

/**
 * Test unit class of local_taskflow.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class trigger_events_external_data_test extends advanced_testcase {
    /** @var string|null Stores the external user data. */
    protected ?string $externaldata = null;

    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        \local_taskflow\local\units\unit_relations::reset_instances();
        $this->externaldata = file_get_contents(__DIR__ . '/../mock/mock_user_data_hierarchy.json');
        $this->set_config_values();
        $this->set_rules();
    }

    /**
     * Setup the test environment.
     */
    protected function set_config_values(): void {
        global $DB;
        $settingvalues = [
            'translator_first_name' => "name->firstname",
            'translator_last_name' => "name->lastname",
            'translator_email' => "mail",
            'translator_units' => "ou",
            'translator_assignment' => "",
            'testing' => "Testing",
            'noinheritance_option' => "allaboveinheritance",
        ];
        foreach ($settingvalues as $key => $value) {
            set_config($key, $value, 'local_taskflow');
        }
        cache_helper::invalidate_by_event('config', ['local_taskflow']);
    }

    /**
     * Setup the test environment.
     */
    protected function set_rules(): void {
        global $DB;
        $rules = json_decode(file_get_contents(__DIR__ . '/../mock/rules/taskflow_rule.json'));

        $unitrecord = (object) [
            'name' => 'IT Department',
            'criteria' => null,
            'timecreated' => time(),
            'timemodified' => time(),
            'usermodified' => time(),
        ];
        $unitid = $DB->insert_record('local_taskflow_units', $unitrecord);

        foreach ($rules as $rule) {
            $rule->unitid = $unitid;
            $DB->insert_record('local_taskflow_rules', $rule);
        }
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\external_adapter\adapters\external_api_user_data
     * @covers \local_taskflow\local\external_adapter\external_api_factory
     * @covers \local_taskflow\local\eventhandlers\base_event_handler
     * @covers \local_taskflow\local\eventhandlers\unit_member_updated
     * @covers \local_taskflow\local\eventhandlers\unit_relation_updated
     * @covers \local_taskflow\observer
     * @covers \local_taskflow\event\unit_member_updated
     * @covers \local_taskflow\event\unit_relation_updated
     */
    public function test_external_data_is_loaded(): void {
        global $DB;
        $apidatamanager = external_api_factory::create($this->externaldata);
        $externaldata = $apidatamanager->get_external_data();
        $this->assertNotEmpty($externaldata, 'External user data should not be empty.');
        $apidatamanager->process_incoming_data();
        $moodleusers = $DB->get_records('user');
        $this->assertCount(8, $moodleusers);
        $units = $DB->get_records('local_taskflow_units');
        $this->assertCount(7, $units);
        $unitrelations = $DB->get_records('local_taskflow_unit_rel');
        $this->assertCount(6, $unitrelations);
        $unitmemebers = $DB->get_records('local_taskflow_unit_members');
        $this->assertCount(10, $unitmemebers);
    }
}
