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
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\rule_management;

use advanced_testcase;
use cache_helper;
use local_taskflow\local\external_adapter\external_api_user_data;
use local_taskflow\local\units\unit;

/**
 * Class unit_member
 *
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class no_inheritage_unit_with_criteria_exists extends advanced_testcase {
    /** @var string|null Stores the external user data. */
    protected ?string $externaldata = null;

    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->externaldata = file_get_contents(__DIR__ . '/../mock/mock_update_user_data_rule_inheritage.json');
        $this->set_config_values();
        $this->create_test_ou();
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
            'testing' => "Testing",
            'noinheritage_option' => "noinheritage",
        ];
        foreach ($settingvalues as $key => $value) {
            set_config($key, $value, 'local_taskflow');
        }
        cache_helper::invalidate_by_event('config', ['local_taskflow']);
    }

    /**
     * Creates a test user and assigns a custom profile field value.
     */
    private function create_test_ou(): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/user/lib.php');
        $ous = [
            [
                'name' => 'IT Department',
                'criteria' => 'rule1, rule2, rule3',
                'timecreated' => '12345678',
                'timemodified' => '12345678',
                'usermodified' => '0',
            ],
            [
                'name' => 'Sales',
                'criteria' => 'rule2',
                'timecreated' => '12345678',
                'timemodified' => '12345678',
                'usermodified' => '0',
            ],
            [
                'name' => 'HR',
                'criteria' => 'rule1, rule4',
                'timecreated' => '12345678',
                'timemodified' => '12345678',
                'usermodified' => '0',
            ],
        ];
        foreach ($ous as $ou) {
            $DB->insert_record(
                'local_taskflow_units',
                (object) $ou
            );
        }
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\units\unit::create
     */
    public function test_no_inheritage_db_units(): void {
        global $DB;
        $apidatamanager = new external_api_user_data($this->externaldata);
        $externaldata = $apidatamanager->get_external_data();
        $this->assertNotEmpty($externaldata, 'External user data should not be empty.');
    }
}
