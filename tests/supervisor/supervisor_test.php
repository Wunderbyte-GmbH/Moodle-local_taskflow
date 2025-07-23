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

namespace local_taskflow\supervisor;

use advanced_testcase;

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
final class supervisor_test extends advanced_testcase {

    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);

        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingenerator->create_custom_profile_fields([
            'supervisor',
            'units',
        ]);
        $plugingenerator->set_config_values();
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\supervisor\supervisor
     */
    public function test_get_supervisor_for_user_returns_correct_supervisor(): void {
        global $DB;

        // Create supervisor and user.
        $supervisor = $this->getDataGenerator()->create_user(['firstname' => 'Super', 'lastname' => 'Visor']);
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Regular', 'lastname' => 'User']);

        $fieldid = $DB->get_field('user_info_field', 'id', ['shortname' => 'supervisor']);
        if (!$DB->record_exists('user_info_data', ['userid' => $user->id, 'fieldid' => $fieldid])) {
            $data = (object)[
                'userid' => $user->id,
                'fieldid' => $fieldid,
                'data' => (string)$supervisor->id,
            ];
            $DB->insert_record('user_info_data', $data);
        }

        // Call the method under test.
        $result = \local_taskflow\local\supervisor\supervisor::get_supervisor_for_user($user->id);

        // Assertions.
        $this->assertNotEmpty($result);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\supervisor\supervisor
     */
    public function test_create_customfield_inserts_data(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $supervisor = $this->getDataGenerator()->create_user();
        $fieldid = $DB->get_field('user_info_field', 'id', ['shortname' => 'supervisor']);
        $dbrecord = $DB->get_record('user_info_data', ['userid' => $user->id, 'fieldid' => $fieldid]);
        if ($dbrecord) {
            $DB->delete_records('user_info_data', ['id' => $dbrecord->id]);
        }

        $sut = new \local_taskflow\local\supervisor\supervisor((string)$supervisor->id, $user->id);
        $sut->create_customfield($fieldid);
        $record = $DB->get_record('user_info_data', ['userid' => $user->id, 'fieldid' => $fieldid]);
        $this->assertNotEmpty($record);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\supervisor\supervisor
     */
    public function test_does_exist_returns_record_if_exists(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $supervisor = $this->getDataGenerator()->create_user();
        $fieldid = $DB->get_field('user_info_field', 'id', ['shortname' => 'supervisor']);

        // Pre-insert the record.
        $record = (object)[
            'userid' => $user->id,
            'fieldid' => $fieldid,
            'data' => $supervisor->id,
        ];
        $dbrecord = $DB->get_record('user_info_data', ['userid' => $user->id, 'fieldid' => $fieldid]);
        if (!$DB->record_exists('user_info_data', ['userid' => $user->id, 'fieldid' => $fieldid])) {
            $id = $DB->insert_record('user_info_data', $record);
        } else {
            $record->id = $dbrecord->id;
            $id = $dbrecord->id;
            $record = $DB->update_record('user_info_data', $record);
        }

        $sut = new \local_taskflow\local\supervisor\supervisor((string)$supervisor->id, $user->id);
        $exists = $sut->does_exist($fieldid);

        $this->assertNotEmpty($exists);
        $this->assertEquals($id, $exists->id);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\supervisor\supervisor
     */
    public function test_update_customfield_updates_data(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $oldsup = $this->getDataGenerator()->create_user();
        $newsup = $this->getDataGenerator()->create_user();
        $fieldid = $DB->get_field('user_info_field', 'id', ['shortname' => 'supervisor']);
        $record = (object)[
            'userid' => $user->id,
            'fieldid' => $fieldid,
            'data' => (string)$oldsup->id,
        ];
        $dbrecord = $DB->get_record('user_info_data', ['userid' => $user->id, 'fieldid' => $fieldid]);
        if ($dbrecord) {
            $DB->delete_records('user_info_data', ['id' => $dbrecord->id]);
        }
        $id = $DB->insert_record('user_info_data', $record);

        $sut = new \local_taskflow\local\supervisor\supervisor((string)$newsup->id, $user->id);
        $sut->update_customfield($id);
        $updated = $DB->get_record('user_info_data', ['id' => $id]);
        $this->assertEquals($newsup->id, $updated->data);
    }
}
