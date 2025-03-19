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

namespace local_taskflow\external_data;

use advanced_testcase;
use cache_helper;
use local_taskflow\local\external_adapter\external_api_user_data;

/**
 * Test unit class of local_taskflow.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class receive_external_api_user_data_test extends advanced_testcase {
    /** @var string|null Stores the external user data. */
    protected ?string $externaldata = null;

    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->externaldata = file_get_contents(__DIR__ . '/../mock/mock_user_data.json');
        $this->set_config_values();
    }

    /**
     * Setup the test environment.
     */
    protected function set_config_values(): void {
        global $DB;
        $settingvalues = [
            'translator_first_name' => "name->firstname",
            'translator_second_name' => "name->secondname",
            'translator_email' => "mail",
            'translator_units' => "ou",
            'translator_assignment' => "",
            'testing' => "Testing",
        ];
        foreach ($settingvalues as $key => $value) {
            set_config($key, $value, 'local_taskflow');
        }
        cache_helper::invalidate_by_event('config', ['local_taskflow']);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\external_adapter\external_api_user_data::__construct
     * @covers \local_taskflow\local\external_adapter\external_api_user_data::get_external_data
     * @covers \local_taskflow\local\external_adapter\external_api_user_data::process_incoming_data
     * @covers \local_taskflow\local\external_adapter\external_api_base::translate_incoming_data
     * @covers \local_taskflow\local\external_adapter\external_api_base::local_taskflow_get_label_settings
     * @covers \local_taskflow\local\units\unit::__construct
     * @covers \local_taskflow\local\units\unit::create_unit
     * @covers \local_taskflow\local\units\unit::get_unit_by_name
     * @covers \local_taskflow\local\units\unit::create
     * @covers \local_taskflow\local\personas\moodle_user::update_or_create
     * @covers \local_taskflow\local\personas\moodle_user::create_new_user
     * @covers \local_taskflow\local\personas\moodle_user::generate_unique_username
     * @covers \local_taskflow\local\personas\moodle_user::generate_random_password
     * @covers \local_taskflow\local\personas\unit_member::update_or_create
     * @covers \local_taskflow\local\personas\unit_member::get_unit_member
     * @covers \local_taskflow\local\personas\unit_member::update
     * @covers \local_taskflow\local\personas\unit_member::create
     */
    public function test_external_data_is_loaded(): void {
        global $DB;
        $apidatamanager = new external_api_user_data($this->externaldata);
        $externaldata = $apidatamanager->get_external_data();
        $this->assertNotEmpty($externaldata, 'External user data should not be empty.');
        $apidatamanager->process_incoming_data();

        $moodleusers = $DB->get_records('user');
        $this->assertCount(8, $moodleusers);
        $units = $DB->get_records('local_taskflow_units');
        $this->assertCount(6, $units);
        $unitrelations = $DB->get_records('local_taskflow_unit_relations');
        $this->assertCount(0, $unitrelations);
        $unitmemebers = $DB->get_records('local_taskflow_unit_members');
        $this->assertCount(9, $unitmemebers);
    }
}
