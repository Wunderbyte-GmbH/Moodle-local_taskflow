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

namespace local_taskflow\messages\bulk_check;

use advanced_testcase;
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\messages\bulk_check\bulk_check;
use local_taskflow\local\messages\messages_factory;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/user/profile/lib.php');

/**
 * Tests of the bulk send checker.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_taskflow\local\messages\bulk_check\bulk_check
 * @covers \local_taskflow\local\messages\bulk_check\bulk_check_config
 */
final class bulk_check_test extends advanced_testcase {
    /** @var string Name of the message used throughout the tests. */
    private const MESSAGENAME = 'Burst reminder';

    /** @var int */
    private int $base;

    /** @var int */
    private int $messageid;

    /** @var int */
    private int $ruleid;

    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        time_mock::init();
        $this->base = strtotime('2026-09-18 08:00:00');
        time_mock::set_mock_time($this->base);
        $this->resetAfterTest(true);
        \local_taskflow\local\units\unit_relations::reset_instances();
    }

    /**
     * Switches the checker on for the message used in these tests.
     *
     * @param int $limit
     * @param int $period
     * @param int $delay
     * @return void
     */
    private function enable_bulk_check(int $limit, int $period = HOURSECS, int $delay = 900): void {
        set_config('bulkcheckenabled', 1, 'local_taskflow');
        set_config('bulkcheckconfig', json_encode([
            self::MESSAGENAME => [
                'limit' => $limit,
                'period' => $period,
                'delay' => $delay,
            ],
        ]), 'local_taskflow');
    }

    /**
     * Creates the message record and the rule that references it.
     *
     * @return void
     */
    private function create_message_and_rule(): void {
        global $DB;

        $this->messageid = $DB->insert_record('local_taskflow_messages', (object)[
            'name' => self::MESSAGENAME,
            'class' => 'standard',
            'message' => json_encode([
                'heading' => 'Burst reminder',
                'body' => 'Please have a look at your assignment.',
            ]),
            'priority' => 10,
            'sending_settings' => json_encode([
                'recipientrole' => ['assignee'],
                'userid' => '',
                'carboncopyrole' => [],
                'ccuserid' => '',
                'senddirection' => 'after',
                'sendstart' => 'start',
                'senddays' => 0,
                'timeunit' => 'days',
            ]),
            'usermodified' => 0,
            'timecreated' => $this->base,
            'timemodified' => $this->base,
        ]);

        $rulejson = json_encode((object)[
            'rulejson' => (object)[
                'rule' => (object)[
                    'actions' => [
                        (object)[
                            'messages' => [
                                (object)[
                                    'messagetype' => 'standard',
                                    'messageid' => $this->messageid,
                                ],
                            ],
                            'targets' => [],
                        ],
                    ],
                ],
            ],
        ]);

        $this->ruleid = $DB->insert_record('local_taskflow_rules', (object)[
            'unitid' => 1,
            'rulename' => 'Burst rule',
            'rulejson' => $rulejson,
            'eventname' => 'manualtest',
            'isactive' => 1,
            'usermodified' => 0,
            'timecreated' => $this->base,
            'timemodified' => $this->base,
        ]);
    }

    /**
     * Creates the given number of users, assigns them to the rule and schedules the message.
     *
     * @param int $count
     * @return array The created users.
     */
    private function schedule_for_users(int $count): array {
        global $DB;

        $users = [];
        for ($i = 0; $i < $count; $i++) {
            $user = $this->getDataGenerator()->create_user([
                'email' => 'burst' . $i . '@example.com',
            ]);
            $DB->insert_record('local_taskflow_assignment', (object)[
                'userid' => $user->id,
                'ruleid' => $this->ruleid,
                'unitid' => 1,
                'messages' => '{}',
                'assigneddate' => $this->base,
                'duedate' => $this->base + DAYSECS,
                'active' => 1,
                'status' => assignment_status_facade::get_status_identifier('assigned'),
                'targets' => '[]',
                'usermodified' => $user->id,
                'timecreated' => $this->base,
                'timemodified' => $this->base,
            ]);
            $users[] = $user;
        }

        foreach ($users as $user) {
            $instance = messages_factory::instance((object)['messageid' => $this->messageid], $user->id, $this->ruleid);
            $instance->schedule_message((object)[]);
        }

        return $users;
    }

    /**
     * Returns the bulk check rows of the test message keyed by status.
     *
     * @return array
     */
    private function count_by_status(): array {
        global $DB;
        $counts = [];
        foreach ($DB->get_records(bulk_check::TABLENAME, ['messageid' => $this->messageid]) as $row) {
            $counts[(int)$row->status] = ($counts[(int)$row->status] ?? 0) + 1;
        }
        return $counts;
    }

    /**
     * Filters the captured notifications down to the bulk check alerts.
     *
     * @param array $messages
     * @return array
     */
    private function filter_alerts(array $messages): array {
        return array_values(array_filter($messages, function ($msg) {
            return ($msg->eventtype ?? '') === 'bulkchecknotification';
        }));
    }

    /**
     * A message that is not bulk checked is scheduled and sent exactly as before.
     */
    public function test_message_without_bulk_check_is_untouched(): void {
        global $DB;

        $sink = $this->redirectEmails();
        $this->create_message_and_rule();
        $this->schedule_for_users(1);

        $tasks = array_values($DB->get_records('task_adhoc'));
        $this->assertCount(1, $tasks);
        $this->assertEquals($this->base, (int)$tasks[0]->nextruntime);
        $this->assertCount(0, $DB->get_records(bulk_check::TABLENAME));

        $this->run_all_adhoc_tasks();
        $this->assertCount(1, $sink->get_messages());
        $sink->close();
    }

    /**
     * A bulk checked message is postponed by the delay and its send is recorded.
     */
    public function test_delay_is_added_and_send_is_recorded(): void {
        global $DB;

        $this->enable_bulk_check(5);
        $this->create_message_and_rule();
        $users = $this->schedule_for_users(1);

        $tasks = array_values($DB->get_records('task_adhoc'));
        $this->assertCount(1, $tasks);
        $this->assertEquals($this->base + 900, (int)$tasks[0]->nextruntime);

        $rows = array_values($DB->get_records(bulk_check::TABLENAME));
        $this->assertCount(1, $rows);
        $this->assertEquals(bulk_check::STATUS_PENDING, (int)$rows[0]->status);
        $this->assertEquals($users[0]->id, (int)$rows[0]->userid);
        $this->assertEquals($this->ruleid, (int)$rows[0]->ruleid);
        $this->assertEquals($tasks[0]->id, (int)$rows[0]->taskid);
        $this->assertEquals($this->base + 900, (int)$rows[0]->scheduledtime);
    }

    /**
     * A burst that stays below the limit is sent normally.
     */
    public function test_burst_below_limit_is_sent(): void {
        $sink = $this->redirectEmails();

        $this->enable_bulk_check(5);
        $this->create_message_and_rule();
        $this->schedule_for_users(3);

        time_mock::set_mock_time($this->base + 900);
        $this->run_all_adhoc_tasks();

        $this->assertCount(3, $sink->get_messages());
        $this->assertEquals([bulk_check::STATUS_SENT => 3], $this->count_by_status());
        $sink->close();
    }

    /**
     * A burst over the limit sends nothing at all and informs the admins once.
     */
    public function test_burst_over_limit_blocks_every_send(): void {
        $emailsink = $this->redirectEmails();
        $messagesink = $this->redirectMessages();

        $this->enable_bulk_check(2);
        $this->create_message_and_rule();
        $this->schedule_for_users(4);

        time_mock::set_mock_time($this->base + 900);
        $this->run_all_adhoc_tasks();

        // Not a single message of the burst went out.
        $this->assertCount(0, $emailsink->get_messages());
        $this->assertEquals([bulk_check::STATUS_BLOCKED => 4], $this->count_by_status());

        // The admins were told about it exactly once.
        $this->assertCount(1, $this->filter_alerts($messagesink->get_messages()));

        $emailsink->close();
        $messagesink->close();
    }

    /**
     * Releasing a blocked burst requeues it and the messages then go out.
     */
    public function test_release_requeues_blocked_sends(): void {
        $messagesink = $this->redirectMessages();

        $this->enable_bulk_check(2);
        $this->create_message_and_rule();
        $this->schedule_for_users(4);

        time_mock::set_mock_time($this->base + 900);
        $this->run_all_adhoc_tasks();
        $messagesink->close();

        $emailsink = $this->redirectEmails();
        $this->assertEquals(4, bulk_check::release($this->messageid, $this->ruleid));
        $this->run_all_adhoc_tasks();

        $this->assertCount(4, $emailsink->get_messages());
        $this->assertEquals([bulk_check::STATUS_SENT => 4], $this->count_by_status());
        $emailsink->close();
    }

    /**
     * Switching the feature off lets an already queued burst go out on its normal schedule.
     */
    public function test_switching_the_feature_off_releases_a_queued_burst(): void {
        $sink = $this->redirectEmails();

        $this->enable_bulk_check(2);
        $this->create_message_and_rule();
        $this->schedule_for_users(4);

        // The burst is queued and would be blocked, but the site switches the checker off.
        set_config('bulkcheckenabled', 0, 'local_taskflow');

        time_mock::set_mock_time($this->base + 900);
        $this->run_all_adhoc_tasks();

        $this->assertCount(4, $sink->get_messages());
        $this->assertEquals([bulk_check::STATUS_PENDING => 4], $this->count_by_status());
        $sink->close();
    }

    /**
     * Rescheduling the same send supersedes the earlier row so it stops counting.
     */
    public function test_rescheduling_supersedes_the_earlier_row(): void {
        global $DB;

        $this->enable_bulk_check(5);
        $this->create_message_and_rule();
        $users = $this->schedule_for_users(1);

        $instance = messages_factory::instance((object)['messageid' => $this->messageid], $users[0]->id, $this->ruleid);
        $instance->schedule_message((object)[]);

        $this->assertEquals(
            [bulk_check::STATUS_SUPERSEDED => 1, bulk_check::STATUS_PENDING => 1],
            $this->count_by_status()
        );

        // The superseded row must not push the live one over a limit of one.
        $this->enable_bulk_check(1);
        $sink = $this->redirectEmails();
        time_mock::set_mock_time($this->base + 900);
        $this->run_all_adhoc_tasks();

        $this->assertCount(1, $sink->get_messages());
        $this->assertEquals(
            [bulk_check::STATUS_SUPERSEDED => 1, bulk_check::STATUS_SENT => 1],
            $this->count_by_status()
        );
        $this->assertCount(1, $DB->get_records('local_taskflow_sent_messages'));
        $sink->close();
    }

    /**
     * Wiping the sent messages of an assignment also stops its pending sends from counting.
     */
    public function test_wiping_sent_messages_supersedes_pending_rows(): void {
        $this->enable_bulk_check(5);
        $this->create_message_and_rule();
        $users = $this->schedule_for_users(2);

        \local_taskflow\local\messages\messages_facade::removed_send_messages_of_user($users[0]->id, $this->ruleid);

        $this->assertEquals(
            [bulk_check::STATUS_SUPERSEDED => 1, bulk_check::STATUS_PENDING => 1],
            $this->count_by_status()
        );
    }
}
