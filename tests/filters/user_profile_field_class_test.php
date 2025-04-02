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

use local_taskflow\local\filters\types\user_profile_field;
use stdClass;
use cache_helper;
use advanced_testcase;
use local_taskflow\local\external_adapter\external_api_factory;
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
final class user_profile_field_class_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        \local_taskflow\local\units\unit_relations::reset_instances();
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\filters\types\user_profile_field
     */
    public function test_construct(): void {
        global $DB;
        $datas = (object)[
            (object)[
                'userprofilefiled' => 'user_profile_field',
                'key' => 'role',
                'value' => '32503680000',
                'operator' => 'equals',
            ],
            (object)[
                'userprofilefiled' => 'user_profile_field',
                'key' => 'since',
                'value' => '32503680000',
                'operator' => 'bigger',
            ],
            (object)[
                'userprofilefiled' => 'user_profile_field',
                'key' => 'sincinvalid',
                'value' => 'invalid',
                'operator' => 'bigger',
            ],
            (object)[
                'userprofilefiled' => 'user_profile_field',
                'key' => 'since',
                'value' => '3250368003969',
                'operator' => 'bigger',
            ],
        ];
        $rule = [];
        foreach ($datas as $data) {
            $fieldinstance = new user_profile_field($data);
            $validation = $fieldinstance->is_valid($rule, 1);
            $this->assertFalse($validation);
        }

        $field = (object)[
            'shortname' => 'user_profile_field',
            'name' => 'Test Field',
            'datatype' => 'textarea',
            'categoryid' => 1,
        ];
        if (!$DB->record_exists('user_info_field', ['shortname' => $field->shortname])) {
            $field->id = $DB->insert_record('user_info_field', $field);
        } else {
            $field = $DB->get_record('user_info_field', ['shortname' => $field->shortname]);
        }

        // Create user.
        $user = $this->getDataGenerator()->create_user();

        $profilevalue = [
            [
                'name' => 'Testing User',
                'role' => 'member',
                'since' => '32503680000',
            ],
        ];

        $record = (object)[
            'userid' => $user->id,
            'fieldid' => $field->id,
            'data' => json_encode($profilevalue),
        ];
        $DB->insert_record('user_info_data', $record);

        foreach ($datas as $data) {
            $fieldinstance = new user_profile_field($data);
            $validation = $fieldinstance->is_valid($rule, $user->id);
            $this->assertFalse($validation);
        }
    }
}
