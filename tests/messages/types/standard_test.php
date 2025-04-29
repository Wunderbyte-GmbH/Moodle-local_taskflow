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

namespace local_taskflow\messages\types;

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
final class standard_test extends advanced_testcase {
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
     * @covers \local_taskflow\local\messages\types\standard
     */
    public function test_send_and_save_message_mocked(): void {
        global $DB;
        $message = (object)[
            'subject' => 'Test Subject',
            'fullmessage' => 'Test Full Message',
            'fullmessagehtml' => '<p>Test HTML Message</p>',
            'smallmessage' => 'Test Small Message',
            'messageid' => 9999,
        ];
        $userid = 12345;
        $ruleid = 67890;

        $mock = $this->getMockBuilder(\local_taskflow\local\messages\types\standard::class)
            ->setConstructorArgs([$message, $userid, $ruleid])
            ->onlyMethods(['send_message'])
            ->getMock();

        $mock->expects($this->once())
            ->method('send_message')
            ->willReturn('mocked-message-id');

        $this->preventResetByRollback();
        $mock->send_and_save_message();

        $record = $DB->get_record('local_taskflow_sent_messages', [
            'message_id' => $message->messageid,
            'user_id' => $userid,
            'rule_id' => $ruleid,
        ]);

        $this->assertNotEmpty($record);
    }
}
