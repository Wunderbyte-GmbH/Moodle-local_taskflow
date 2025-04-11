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
use local_taskflow\local\repositories\external_api_repository;
use stdClass;

/**
 * Test unit class of local_taskflow.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class receive_external_update_user_data_test extends advanced_testcase {
    /** @var string|null Stores the external user data. */
    protected ?string $externaldata = null;

    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        \local_taskflow\local\units\unit_relations::reset_instances();
        $this->externaldata = file_get_contents(__DIR__ . '/../mock/mock_update_user_data.json');
        $this->set_config_values();
        $this->create_test_user();
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
            'testing' => "Testing",
        ];
        foreach ($settingvalues as $key => $value) {
            set_config($key, $value, 'local_taskflow');
        }
        cache_helper::invalidate_by_event('config', ['local_taskflow']);
    }

    /**
     * Creates a test user and assigns a custom profile field value.
     */
    private function create_test_user(): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/user/lib.php');
        $users = [
            [
                'firstname' => 'Alice',
                'lastname' => 'Example',
                'username' => 'alice.example',
                'mail' => 'alice@example.com',
                'ou' => '[{"unit":"IT Department","role":"member","since":"2020-05-12"},
                {"unit":"Sales","role":"member","since":"2020-05-12"},
                { "unit": "Finance", "role": "vorgesetzter", "since": "2018-03-01", "parent":"Management"}]',
            ],
            [
                'firstname' => 'Bob',
                'lastname' => 'Tester',
                'username' => 'bob.tester',
                'mail' => 'bob@example.com',
                'ou' => '[{"unit":"IT Department","role":"member","since":"2020-05-12"},
                {"unit":"Sales","role":"member","since":"2020-05-12"}]',
            ],
        ];
        foreach ($users as $dbuser) {
            $user = new stdClass();
            $user->auth = 'manual';
            $user->confirmed = 1;
            $user->mnethostid = 1;
            $user->username = $dbuser['username'];
            $user->email = $dbuser['mail'];
            $user->firstname = $dbuser['firstname'];
            $user->lastname = $dbuser['lastname'];
            $user->password = 'Test@1234';
            $user->id = user_create_user($user);
            $this->update_user_custom_profile_field($user->id, 'unit_info', $dbuser['ou']);
        }
    }

    /**
     * Updates a custom profile field for a user.
     * @param int $userid The user ID.
     * @param string $fieldname The custom profile field shortname.
     * @param string $value The custom profile field shortname.
     */
    private function update_user_custom_profile_field(int $userid, string $fieldname, string $value): void {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/user/profile/lib.php');

        $field = $DB->get_record('user_info_field', ['shortname' => $fieldname], 'id');
        if (!$field) {
            throw new \moodle_exception("Custom profile field '$fieldname' not found.");
        }

        $existing = $DB->get_record('user_info_data', ['userid' => $userid, 'fieldid' => $field->id]);
        if ($existing) {
            $existing->data = $value;
            $DB->update_record('user_info_data', $existing);
        } else {
            $data = new stdClass();
            $data->userid = $userid;
            $data->fieldid = $field->id;
            $data->data = $value;
            $DB->insert_record('user_info_data', $data);
            $storedvalue = $DB->get_field('user_info_data', 'data', ['userid' => $userid]);
        }
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\external_adapter\adapters\external_api_user_data
     * @covers \local_taskflow\local\personas\moodle_user
     */
    public function test_external_data_is_loaded(): void {
        global $DB;
        $apidatamanager = external_api_repository::create($this->externaldata);
        $externaldata = $apidatamanager->get_external_data();
        $this->assertNotEmpty($externaldata, 'External user data should not be empty.');
        $apidatamanager->process_incoming_data();
        $moodleusers = $DB->get_records('user');
        $this->assertCount(4, $moodleusers);
        $units = $DB->get_records('local_taskflow_units');
        $this->assertCount(2, $units);
        $unitrelations = $DB->get_records('local_taskflow_unit_rel');
        $this->assertCount(0, $unitrelations);
        $unitmemebers = $DB->get_records('local_taskflow_unit_members');
        $this->assertCount(4, $unitmemebers);
    }
}
