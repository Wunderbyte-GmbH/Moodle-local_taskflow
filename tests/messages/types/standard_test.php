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
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\messages\messages_factory;
use tool_mocktesttime\time_mock;

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
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
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
            'id' => 9999,
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
            'messageid' => $message->id,
            'userid' => $userid,
            'ruleid' => $ruleid,
        ]);

        $this->assertNotEmpty($record);
    }

    /**
     * Ensure that <opentargets> is rendered in delayed mails after one week.
     *
     * @covers \local_taskflow\local\messages\types\standard::schedule_message
     * @covers \local_taskflow\local\messages\types\standard::send_and_save_message
     * @covers \local_taskflow\local\messages\placeholders\types\opentargets
     */
    public function test_schedule_message_renders_targets_placeholder_after_one_week(): void {
        global $DB;

        $this->setAdminUser();
        $sink = $this->redirectEmails();

        $base = strtotime('2026-01-01 10:00:00');
        time_mock::set_mock_time($base);

        $user = $this->getDataGenerator()->create_user([
            'email' => 'target.assignee@example.com',
        ]);

        $coursea = $this->getDataGenerator()->create_course([
            'fullname' => 'Target course alpha',
            'shortname' => 'TCA',
        ]);
        $courseb = $this->getDataGenerator()->create_course([
            'fullname' => 'Target course beta',
            'shortname' => 'TCB',
        ]);
        $coursec = $this->getDataGenerator()->create_course([
            'fullname' => 'Target course gamma',
            'shortname' => 'TCC',
        ]);

        $messageid = $DB->insert_record('local_taskflow_messages', (object)[
            'class' => 'standard',
            'message' => json_encode([
                'heading' => 'Weekly reminder',
                'body' => 'You were assigned to: <opentargets>.',
            ]),
            'priority' => 10,
            'sending_settings' => json_encode([
                'recipientrole' => ['assignee'],
                'userid' => '',
                'carboncopyrole' => [],
                'ccuserid' => '',
                'senddirection' => 'after',
                'sendstart' => 'start',
                'senddays' => 7,
                'timeunit' => 'days',
            ]),
            'usermodified' => $user->id,
            'timecreated' => $base,
            'timemodified' => $base,
        ]);

        $targets = [
            (object)[
                'targettype' => 'moodlecourse',
                'targetid' => $coursea->id,
                "completebeforenext" => "0",
                "targetname" => "New Migration test",
                "completionstatus" => 0,
            ],
            (object)[
                'targettype' => 'moodlecourse',
                'targetid' => $courseb->id,
                "completebeforenext" => "0",
                "targetname" => "New Migration test",
                "completionstatus" => 1,
            ],
            (object)[
                'targettype' => 'moodlecourse',
                'targetid' => $coursec->id,
                "completebeforenext" => "0",
                "targetname" => "New Migration test",
                "completionstatus" => 0,
            ],
        ];

        $rulejson = json_encode((object)[
            'rulejson' => (object)[
                'rule' => (object)[
                    'actions' => [
                        (object)[
                            'messages' => [
                                (object)[
                                    'messagetype' => 'standard',
                                    'messageid' => $messageid,
                                ],
                            ],
                            'targets' => $targets,
                        ],
                    ],
                ],
            ],
        ]);

        $ruleid = $DB->insert_record('local_taskflow_rules', (object)[
            'unitid' => 1,
            'rulename' => 'Weekly targets reminder rule',
            'rulejson' => $rulejson,
            'eventname' => 'manualtest',
            'isactive' => 1,
            'usermodified' => $user->id,
            'timecreated' => $base,
            'timemodified' => $base,
        ]);

        $DB->insert_record('local_taskflow_assignment', (object)[
            'userid' => $user->id,
            'ruleid' => $ruleid,
            'unitid' => 1,
            'messages' => '{}',
            'assigneddate' => $base,
            'duedate' => $base + DAYSECS,
            'active' => 1,
            'status' => assignment_status_facade::get_status_identifier('assigned'),
            'targets' => json_encode($targets),
            'usermodified' => $user->id,
            'timecreated' => $base,
            'timemodified' => $base,
        ]);

        $messageinstance = messages_factory::instance((object)[
            'messageid' => $messageid,
        ], $user->id, $ruleid);
        $messageinstance->schedule_message((object)[]);

        $tasks = array_values($DB->get_records('task_adhoc'));
        $this->assertCount(1, $tasks);
        $this->assertEquals($base + WEEKSECS, (int)$tasks[0]->nextruntime);
        $this->assertCount(0, $sink->get_messages());

        time_mock::set_mock_time($base + WEEKSECS);
        $this->run_all_adhoc_tasks();

        $emails = $sink->get_messages();
        $this->assertCount(1, $emails);
        $email = reset($emails);
        $this->assertSame($user->email, $email->to);

        $body = quoted_printable_decode($email->body);
        $this->assertStringNotContainsString('<opentargets>', $body);
        $this->assertStringContainsString('Target course alpha', $body);
        $this->assertStringNotContainsString('Target course beta', $body);
        $this->assertStringContainsString('Target course gamma', $body);

        $this->assertCount(1, $DB->get_records('local_taskflow_sent_messages'));
        $sink->close();
    }

    /**
     * CC recipients receive a separate individual email with a [Copy] prefix in the subject.
     * Primary recipients get the subject extended with a CC names suffix.
     *
     * @covers \local_taskflow\local\messages\message_base::send_email_with_cc
     */
    public function test_cc_recipients_receive_separate_email_with_copy_prefix(): void {
        global $DB;

        $this->setAdminUser();
        $sink = $this->redirectEmails();

        $base = strtotime('2026-01-01 10:00:00');
        time_mock::set_mock_time($base);

        $primaryuser = $this->getDataGenerator()->create_user(['email' => 'primary@example.com']);
        $ccuser      = $this->getDataGenerator()->create_user(['email' => 'cc@example.com']);

        $messageid = $DB->insert_record('local_taskflow_messages', (object)[
            'class'            => 'standard',
            'message'          => json_encode(['heading' => 'Test subject', 'body' => 'Test body']),
            'priority'         => 10,
            'sending_settings' => json_encode([
                'recipientrole'  => ['assignee'],
                'userid'         => '',
                'carboncopyrole' => ['ccspecificuser'],
                'ccuserid'       => $ccuser->id,
                'senddirection'  => 'after',
                'sendstart'      => 'start',
                'senddays'       => 0,
                'timeunit'       => 'days',
            ]),
            'usermodified' => $primaryuser->id,
            'timecreated'  => $base,
            'timemodified' => $base,
        ]);

        $ruleid = $DB->insert_record('local_taskflow_rules', (object)[
            'unitid'       => 1,
            'rulename'     => 'CC test rule',
            'rulejson'     => '{}',
            'eventname'    => 'manualtest',
            'isactive'     => 1,
            'usermodified' => $primaryuser->id,
            'timecreated'  => $base,
            'timemodified' => $base,
        ]);

        $DB->insert_record('local_taskflow_assignment', (object)[
            'userid'       => $primaryuser->id,
            'ruleid'       => $ruleid,
            'unitid'       => 1,
            'messages'     => '{}',
            'assigneddate' => $base,
            'duedate'      => $base + DAYSECS,
            'active'       => 1,
            'status'       => assignment_status_facade::get_status_identifier('assigned'),
            'targets'      => '[]',
            'usermodified' => $primaryuser->id,
            'timecreated'  => $base,
            'timemodified' => $base,
        ]);

        $messageinstance = messages_factory::instance(
            (object)['messageid' => $messageid],
            $primaryuser->id,
            $ruleid
        );
        $messageinstance->send_and_save_message();

        $emails = $sink->get_messages();
        $this->assertCount(2, $emails);

        $emailsto = [];
        foreach ($emails as $email) {
            $emailsto[$email->to] = $email;
        }

        $this->assertArrayHasKey($primaryuser->email, $emailsto);
        $primaryemail = $emailsto[$primaryuser->email];
        $this->assertStringContainsString('Test subject', $primaryemail->subject);
        $this->assertStringContainsString(fullname($ccuser), $primaryemail->subject);

        $this->assertArrayHasKey($ccuser->email, $emailsto);
        $ccemail = $emailsto[$ccuser->email];
        $this->assertStringContainsString('[CC]', $ccemail->subject);
        $this->assertStringContainsString('Test subject', $ccemail->subject);
        $this->assertStringContainsString(fullname($primaryuser), $ccemail->subject);

        $sink->close();
    }

    /**
     * When there are no CC recipients configured, exactly one email is sent with the original subject.
     *
     * @covers \local_taskflow\local\messages\message_base::send_email_with_cc
     */
    public function test_empty_cc_list_sends_only_primary_email(): void {
        global $DB;

        $this->setAdminUser();
        $sink = $this->redirectEmails();

        $base = strtotime('2026-01-01 10:00:00');
        time_mock::set_mock_time($base);

        $user = $this->getDataGenerator()->create_user(['email' => 'solo@example.com']);

        $messageid = $DB->insert_record('local_taskflow_messages', (object)[
            'class'            => 'standard',
            'message'          => json_encode(['heading' => 'Solo subject', 'body' => 'Solo body']),
            'priority'         => 10,
            'sending_settings' => json_encode([
                'recipientrole'  => ['assignee'],
                'userid'         => '',
                'carboncopyrole' => [],
                'ccuserid'       => '',
                'senddirection'  => 'after',
                'sendstart'      => 'start',
                'senddays'       => 0,
                'timeunit'       => 'days',
            ]),
            'usermodified' => $user->id,
            'timecreated'  => $base,
            'timemodified' => $base,
        ]);

        $ruleid = $DB->insert_record('local_taskflow_rules', (object)[
            'unitid'       => 1,
            'rulename'     => 'No CC rule',
            'rulejson'     => '{}',
            'eventname'    => 'manualtest',
            'isactive'     => 1,
            'usermodified' => $user->id,
            'timecreated'  => $base,
            'timemodified' => $base,
        ]);

        $DB->insert_record('local_taskflow_assignment', (object)[
            'userid'       => $user->id,
            'ruleid'       => $ruleid,
            'unitid'       => 1,
            'messages'     => '{}',
            'assigneddate' => $base,
            'duedate'      => $base + DAYSECS,
            'active'       => 1,
            'status'       => assignment_status_facade::get_status_identifier('assigned'),
            'targets'      => '[]',
            'usermodified' => $user->id,
            'timecreated'  => $base,
            'timemodified' => $base,
        ]);

        $messageinstance = messages_factory::instance(
            (object)['messageid' => $messageid],
            $user->id,
            $ruleid
        );
        $messageinstance->send_and_save_message();

        $emails = $sink->get_messages();
        $this->assertCount(1, $emails);

        $email = reset($emails);
        $this->assertSame($user->email, $email->to);
        $this->assertSame('Solo subject', $email->subject);

        $sink->close();
    }
}
