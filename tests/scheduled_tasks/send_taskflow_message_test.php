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

namespace local_taskflow\task;

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
final class send_taskflow_message_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        set_config('personal_admin_mail_field', 'persAdmin@test.at', 'local_taskflow');
        $this->resetAfterTest(true);
        \local_taskflow\local\units\unit_relations::reset_instances();
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\task\send_taskflow_message
     * @covers \local_taskflow\local\messages\types\standard
     */
    public function test_execute_class_does_not_exist(): void {
        global $DB;

        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user(['id' => 12345]);

        // Insert dummy rule with ID 67890.
        $ruleid = $DB->insert_record('local_taskflow_rules', (object)[
            'id' => 67890,
            'rulename' => 'Dummy Rule',
            'rulejson' => json_encode(['rule' => ['actions' => []]]),
            'usermodified' => $user->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $assignmentid = $DB->insert_record('local_taskflow_assignment', (object)[
            'userid' => $user->id,
            'messages' => '{}',
            'ruleid' => $ruleid,
            'unitid' => 0,
            'assigneddate' => time(),
            'duedate' => time() + 3600,
            'active' => 1,
            'status' => 0,
            'targets' => '[]',
            'usermodified' => $user->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $sendingsettings = [
            'recipientrole' => ['assignee', 'supervisor', 'specificuser', 'personaladmin'],
            'userid' => '1',
            'carboncopyrole' => ['assignee', 'supervisor', 'ccspecificuser', 'personaladmin'],
            'ccuserid' => '2',
            'senddirection' => 'before',
            'sendstart' => 'end',
            'senddays' => '0',
        ];

        $message = [
            'heading' => 'Testing',
            'body' => 'Some important stuff <firstname> <lastname> <targets> <due_date> <status>',
        ];

        $messageid = $DB->insert_record('local_taskflow_messages', (object)[
            'class' => 'standard',
            'message' => json_encode($message),
            'priority' => 10,
            'sending_settings' => json_encode($sendingsettings),
            'usermodified' => 0,
        ]);

        $task = new send_taskflow_message();
        $task->set_custom_data([
            'userid' => $user->id,
            'messageid' => $messageid,
            'ruleid' => $ruleid,
        ]);

        $task->execute();
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\task\send_taskflow_message
     * @covers \local_taskflow\local\messages\messages_factory
     * @covers \local_taskflow\local\messages\types\standard
     */
    public function test_execute_sends_message_when_not_already_sent(): void {
        global $DB;

        $this->resetAfterTest(true);

        $messageid = $DB->insert_record('local_taskflow_messages', (object)[
            'class' => 'standard',
        ]);

        $userid = 12345;
        $ruleid = 67890;

        $DB->insert_record('local_taskflow_sent_messages', (object)[
            'userid' => $userid,
            'messageid' => $messageid,
            'ruleid' => $ruleid,
            'timesent' => time(),
        ]);

        $task = new send_taskflow_message();
        $task->set_custom_data([
            'userid' => $userid,
            'messageid' => $messageid,
            'ruleid' => $ruleid,
        ]);

        $task->execute();
    }
}
