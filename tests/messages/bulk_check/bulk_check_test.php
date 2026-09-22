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
use core\lock\lock_config;
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\history\history;
use local_taskflow\local\messages\bulk_check\bulk_check;
use local_taskflow\local\messages\bulk_check\bulk_check_config;
use local_taskflow\local\messages\messages_factory;
use local_taskflow\table\bulk_check_table;
use local_taskflow\task\bulk_check_reminder;
use local_taskflow\task\release_bulk_check;
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
    private int $messageid = 0;

    /** @var int */
    private int $ruleid = 0;

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
        // The configuration is keyed by message id, so the message has to exist first.
        $this->create_message_and_rule();
        set_config('bulkcheckenabled', 1, 'local_taskflow');
        // The window and the delay are site wide, only the limit is per message.
        set_config('bulkcheckperiod', $period, 'local_taskflow');
        set_config('bulkcheckdelay', $delay, 'local_taskflow');
        bulk_check_config::set_settings($this->messageid, true, $limit);
    }

    /**
     * Creates the message record and the rule that references it.
     *
     * @return void
     */
    private function create_message_and_rule(): void {
        global $DB;

        if (!empty($this->messageid)) {
            return;
        }

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
    private function schedule_for_users(int $count, int $ruleid = 0): array {
        global $DB;

        $ruleid = $ruleid ?: $this->ruleid;
        $offset = $DB->count_records('local_taskflow_assignment');

        $users = [];
        for ($i = 0; $i < $count; $i++) {
            $user = $this->getDataGenerator()->create_user([
                'email' => 'burst' . ($offset + $i) . '@example.com',
            ]);
            $DB->insert_record('local_taskflow_assignment', (object)[
                'userid' => $user->id,
                'ruleid' => $ruleid,
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
            $instance = messages_factory::instance((object)['messageid' => $this->messageid], $user->id, $ruleid);
            $instance->schedule_message((object)[]);
        }

        return $users;
    }

    /**
     * Creates a second rule that sends the very same message.
     *
     * One message record can be referenced from any number of rules, and the parked list
     * groups them back together, so the tests need more than one of them.
     *
     * @return int
     */
    private function create_second_rule(): int {
        global $DB;

        return (int) $DB->insert_record('local_taskflow_rules', (object)[
            'unitid' => 1,
            'rulename' => 'Second burst rule',
            'rulejson' => json_encode((object)[
                'rulejson' => (object)[
                    'rule' => (object)[
                        'actions' => [
                            (object)[
                                'messages' => [
                                    (object)['messagetype' => 'standard', 'messageid' => $this->messageid],
                                ],
                                'targets' => [],
                            ],
                        ],
                    ],
                ],
            ]),
            'eventname' => 'manualtest',
            'isactive' => 1,
            'usermodified' => 0,
            'timecreated' => $this->base,
            'timemodified' => $this->base,
        ]);
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
     * @covers \local_taskflow\local\messages\bulk_check\bulk_check::applies
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
     * @covers \local_taskflow\local\messages\bulk_check\bulk_check::record_scheduled
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
     * @covers \local_taskflow\local\messages\bulk_check\bulk_check::check
     * @covers \local_taskflow\task\send_taskflow_message
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
     * @covers \local_taskflow\local\messages\bulk_check\bulk_check::check
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
     * @covers \local_taskflow\local\messages\bulk_check\bulk_check::release
     * @covers \local_taskflow\task\release_bulk_check
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
     * @covers \local_taskflow\local\messages\bulk_check\bulk_check::check
     * @covers \local_taskflow\local\messages\bulk_check\bulk_check_config::is_enabled
     */
    public function test_switching_the_feature_off_releases_a_queued_burst(): void {
        $sink = $this->redirectEmails();

        $this->enable_bulk_check(2);
        $this->create_message_and_rule();
        $this->schedule_for_users(60);

        // The burst is over the limit and would be blocked, but the site switches off.
        set_config('bulkcheckenabled', 0, 'local_taskflow');

        time_mock::set_mock_time($this->base + 900);
        $this->run_all_adhoc_tasks();

        // Every one of them goes out. Switching the checker off has to bypass the check
        // outright, not fall back to the default limit and weigh the burst against that.
        $this->assertCount(60, $sink->get_messages());
        $this->assertEquals([bulk_check::STATUS_SENT => 60], $this->count_by_status());
        $sink->close();
    }

    /**
     * Dismissing a parked burst gives up on it: never sent, and out of the count.
     * @covers \local_taskflow\local\messages\bulk_check\bulk_check::dismiss
     */
    public function test_dismiss_gives_up_on_a_parked_burst(): void {
        $messagesink = $this->redirectMessages();

        $this->enable_bulk_check(2);
        $this->create_message_and_rule();
        $this->schedule_for_users(4);

        time_mock::set_mock_time($this->base + 900);
        $this->run_all_adhoc_tasks();
        $messagesink->close();

        $emailsink = $this->redirectEmails();
        $this->assertEquals(4, bulk_check::dismiss($this->messageid, $this->ruleid));
        $this->run_all_adhoc_tasks();

        // Nothing was queued again, nothing went out, and the rows are gone for good.
        $this->assertCount(0, $emailsink->get_messages());
        $this->assertEmpty($this->count_by_status());
        $this->assertEmpty(bulk_check::get_parked_bursts());
        $emailsink->close();
    }

    /**
     * The reminder nags while something is parked and stays quiet once it is not.
     * @covers \local_taskflow\task\bulk_check_reminder
     */
    public function test_reminder_nags_until_the_burst_is_dealt_with(): void {
        $messagesink = $this->redirectMessages();

        $this->enable_bulk_check(2);
        $this->create_message_and_rule();
        $this->schedule_for_users(4);

        time_mock::set_mock_time($this->base + 900);
        $this->run_all_adhoc_tasks();
        $messagesink->close();

        // Every run of the task reminds again while the burst sits there.
        $task = new bulk_check_reminder();

        $firstsink = $this->redirectMessages();
        $task->execute();
        $this->assertCount(1, $this->filter_alerts($firstsink->get_messages()));
        $firstsink->close();

        $secondsink = $this->redirectMessages();
        $task->execute();
        $this->assertCount(1, $this->filter_alerts($secondsink->get_messages()));
        $secondsink->close();

        // Once the burst is dismissed the reminder has nothing left to say.
        bulk_check::dismiss($this->messageid, $this->ruleid);

        $thirdsink = $this->redirectMessages();
        $task->execute();
        $this->assertCount(0, $this->filter_alerts($thirdsink->get_messages()));
        $thirdsink->close();
    }

    /**
     * The reminder says nothing at all while the feature is switched off.
     * @covers \local_taskflow\task\bulk_check_reminder
     */
    public function test_reminder_is_silent_while_the_feature_is_off(): void {
        $messagesink = $this->redirectMessages();

        $this->enable_bulk_check(2);
        $this->create_message_and_rule();
        $this->schedule_for_users(4);

        time_mock::set_mock_time($this->base + 900);
        $this->run_all_adhoc_tasks();
        $messagesink->close();

        set_config('bulkcheckenabled', 0, 'local_taskflow');

        $sink = $this->redirectMessages();
        (new bulk_check_reminder())->execute();
        $this->assertCount(0, $this->filter_alerts($sink->get_messages()));
        $sink->close();
    }

    /**
     * Releasing a whole message covers every rule it was blocked under, and the released
     * sends must not be counted against their own limit on the way back out.
     * @covers \local_taskflow\local\messages\bulk_check\bulk_check::release_message
     * @covers \local_taskflow\local\messages\bulk_check\bulk_check::queue_released_batch
     * @covers \local_taskflow\local\messages\bulk_check\bulk_check::get_parked_messages
     * @covers \local_taskflow\task\release_bulk_check
     */
    public function test_release_message_covers_every_rule_and_does_not_reblock(): void {
        $messagesink = $this->redirectMessages();
        // The limit counts per message and rule, so two sends per rule have to beat one.
        $this->enable_bulk_check(1);

        // The same message, blocked under two different rules.
        $this->schedule_for_users(2);
        $this->schedule_for_users(2, $this->create_second_rule());

        time_mock::set_mock_time($this->base + 900);
        $this->run_all_adhoc_tasks();
        $messagesink->close();

        $this->assertEquals([bulk_check::STATUS_BLOCKED => 4], $this->count_by_status());

        $parked = bulk_check::get_parked_messages();
        $this->assertCount(1, $parked);
        $burst = reset($parked);
        $this->assertEquals(4, (int)$burst->parked);
        $this->assertEquals(2, (int)$burst->rules);

        // One release covers both rules.
        $emailsink = $this->redirectEmails();
        $this->assertEquals(4, bulk_check::release_message($this->messageid));
        $this->assertEquals([bulk_check::STATUS_RELEASING => 4], $this->count_by_status());

        // A second click has nothing left to do and must not queue the burst twice.
        $this->assertEquals(0, bulk_check::release_message($this->messageid));

        $this->run_all_adhoc_tasks();

        $this->assertCount(4, $emailsink->get_messages());
        $this->assertEquals([bulk_check::STATUS_SENT => 4], $this->count_by_status());
        $this->assertEmpty(bulk_check::get_parked_messages());
        $emailsink->close();
    }

    /**
     * Dismissing a whole message covers every rule it was blocked under.
     * @covers \local_taskflow\local\messages\bulk_check\bulk_check::dismiss_message
     */
    public function test_dismiss_message_covers_every_rule(): void {
        $messagesink = $this->redirectMessages();
        $this->enable_bulk_check(1);

        // The same message, blocked under two different rules.
        $this->schedule_for_users(2);
        $this->schedule_for_users(2, $this->create_second_rule());

        time_mock::set_mock_time($this->base + 900);
        $this->run_all_adhoc_tasks();
        $messagesink->close();

        $emailsink = $this->redirectEmails();
        $this->assertEquals(4, bulk_check::dismiss_message($this->messageid));
        $this->run_all_adhoc_tasks();

        $this->assertCount(0, $emailsink->get_messages());
        $this->assertEmpty($this->count_by_status());
        $this->assertEmpty(bulk_check::get_parked_messages());
        $emailsink->close();
    }

    /**
     * The parked list carries the users of a burst, and says which rule blocked each.
     * @covers \local_taskflow\local\messages\bulk_check\bulk_check::get_parked_rows
     */
    public function test_parked_rows_name_the_users_and_their_rule(): void {
        $messagesink = $this->redirectMessages();
        $this->enable_bulk_check(2);
        $this->schedule_for_users(4);

        time_mock::set_mock_time($this->base + 900);
        $this->run_all_adhoc_tasks();
        $messagesink->close();

        $rows = bulk_check::get_parked_rows($this->messageid, 51);
        $this->assertCount(4, $rows);

        $row = reset($rows);
        $this->assertNotEmpty($row->firstname);
        $this->assertEquals($this->ruleid, (int)$row->ruleid);
        $this->assertEquals('Burst rule', $row->rulename);

        // The sample is capped, but the burst total is not.
        $this->assertCount(2, bulk_check::get_parked_rows($this->messageid, 2));
    }

    /**
     * The configuration survives a round trip and the cache does not go stale.
     * @covers \local_taskflow\local\messages\bulk_check\bulk_check_config
     */
    public function test_configuration_round_trip(): void {
        $this->create_message_and_rule();
        set_config('bulkcheckenabled', 1, 'local_taskflow');

        $message = (object)['id' => $this->messageid];

        // Nothing stored yet, so the message is not bulk checked.
        $this->assertFalse(bulk_check_config::is_bulk_checked($message));

        bulk_check_config::set_settings($this->messageid, true, 7);
        $this->assertTrue(bulk_check_config::is_bulk_checked($message));
        $this->assertEquals(7, bulk_check_config::get_limit($message));

        // The window and the delay come from the site settings, the same for every message.
        set_config('bulkcheckperiod', 2 * HOURSECS, 'local_taskflow');
        set_config('bulkcheckdelay', 300, 'local_taskflow');
        $this->assertEquals(2 * HOURSECS, bulk_check_config::get_period($message));
        $this->assertEquals(300, bulk_check_config::get_delay($message));

        // Writing again has to be visible immediately, not after the cache expires.
        bulk_check_config::set_settings($this->messageid, true, 9);
        $this->assertEquals(9, bulk_check_config::get_limit($message));

        // Unticking leaves the number in place but stops the checking.
        bulk_check_config::set_settings($this->messageid, false, 9);
        $this->assertFalse(bulk_check_config::is_bulk_checked($message));
        $this->assertEquals(9, (int)bulk_check_config::get_record($this->messageid)->limitcount);

        // The master switch overrides every per message row.
        bulk_check_config::set_settings($this->messageid, true, 9);
        set_config('bulkcheckenabled', 0, 'local_taskflow');
        $this->assertFalse(bulk_check_config::is_bulk_checked($message));

        // Deleting the message takes its configuration with it.
        set_config('bulkcheckenabled', 1, 'local_taskflow');
        bulk_check_config::delete_settings($this->messageid);
        $this->assertNull(bulk_check_config::get_record($this->messageid));
        $this->assertFalse(bulk_check_config::is_bulk_checked($message));
    }

    /**
     * Only message types the checker can ever act on are offered the setting.
     * @covers \local_taskflow\local\messages\bulk_check\bulk_check_config::is_checkable_class
     */
    public function test_only_checkable_message_types_are_offered(): void {
        $this->assertTrue(bulk_check_config::is_checkable_class('standard'));
        $this->assertTrue(bulk_check_config::is_checkable_class('onevent'));
        $this->assertFalse(bulk_check_config::is_checkable_class('chat'));
        $this->assertFalse(bulk_check_config::is_checkable_class('request'));
        $this->assertFalse(bulk_check_config::is_checkable_class(null));
    }

    /**
     * Rescheduling the same send drops the earlier row so it stops counting.
     * @covers \local_taskflow\local\messages\bulk_check\bulk_check::record_scheduled
     * @covers \local_taskflow\local\messages\bulk_check\bulk_check::drop_pending
     */
    public function test_rescheduling_drops_the_earlier_row(): void {
        global $DB;

        $this->enable_bulk_check(5);
        $this->create_message_and_rule();
        $users = $this->schedule_for_users(1);

        $instance = messages_factory::instance((object)['messageid' => $this->messageid], $users[0]->id, $this->ruleid);
        $instance->schedule_message((object)[]);

        $this->assertEquals([bulk_check::STATUS_PENDING => 1], $this->count_by_status());

        // Only the live row is left, so a limit of one must let it through.
        $this->enable_bulk_check(1);
        $sink = $this->redirectEmails();
        time_mock::set_mock_time($this->base + 900);
        $this->run_all_adhoc_tasks();

        $this->assertCount(1, $sink->get_messages());
        $this->assertEquals([bulk_check::STATUS_SENT => 1], $this->count_by_status());
        $this->assertCount(1, $DB->get_records('local_taskflow_sent_messages'));
        $sink->close();
    }

    /**
     * Wiping the sent messages of an assignment drops its pending sends, tasks included.
     * @covers \local_taskflow\local\messages\bulk_check\bulk_check::drop_pending
     */
    public function test_wiping_sent_messages_drops_pending_rows(): void {
        global $DB;

        $this->enable_bulk_check(5);
        $this->create_message_and_rule();
        $users = $this->schedule_for_users(2);
        $classname = '\\local_taskflow\\task\\send_taskflow_message';
        $this->assertEquals(2, $DB->count_records('task_adhoc', ['classname' => $classname]));

        \local_taskflow\local\messages\messages_facade::removed_send_messages_of_user($users[0]->id, $this->ruleid);

        // The row is gone, and so is its task: nothing can slip past the check unrecorded.
        $this->assertEquals([bulk_check::STATUS_PENDING => 1], $this->count_by_status());
        $this->assertEquals(1, $DB->count_records('task_adhoc', ['classname' => $classname]));
    }

    /**
     * Blocks four sends under two rules and hands back their parked row ids.
     *
     * @return array
     */
    private function block_four_sends(): array {
        $messagesink = $this->redirectMessages();
        // The limit counts per message and rule, so two sends per rule have to beat one.
        $this->enable_bulk_check(1);
        $this->schedule_for_users(2);
        $this->schedule_for_users(2, $this->create_second_rule());

        time_mock::set_mock_time($this->base + 900);
        $this->run_all_adhoc_tasks();
        $messagesink->close();

        $this->assertEquals([bulk_check::STATUS_BLOCKED => 4], $this->count_by_status());

        return array_keys(bulk_check::get_parked_rows($this->messageid, 51));
    }

    /**
     * Releasing a selection sends those mails and leaves the rest of the burst parked.
     * @covers \local_taskflow\local\messages\bulk_check\bulk_check::release_rows
     * @covers \local_taskflow\local\messages\bulk_check\bulk_check::count_parked
     */
    public function test_release_rows_only_sends_the_selection(): void {
        $ids = $this->block_four_sends();
        $picked = array_slice($ids, 0, 2);

        $emailsink = $this->redirectEmails();
        $this->assertEquals(2, bulk_check::release_rows($picked));
        $this->assertEquals(
            [bulk_check::STATUS_RELEASING => 2, bulk_check::STATUS_BLOCKED => 2],
            $this->count_by_status()
        );

        // The very same selection has nothing left to do the second time round.
        $this->assertEquals(0, bulk_check::release_rows($picked));

        $this->run_all_adhoc_tasks();

        $this->assertCount(2, $emailsink->get_messages());
        $this->assertEquals(
            [bulk_check::STATUS_SENT => 2, bulk_check::STATUS_BLOCKED => 2],
            $this->count_by_status()
        );
        $emailsink->close();

        // The two that were not picked are still waiting for a decision.
        $this->assertEquals(2, bulk_check::count_parked());
        $this->assertEquals(2, bulk_check::count_parked($this->messageid));
        $parked = bulk_check::get_parked_messages();
        $this->assertEquals(2, (int) reset($parked)->parked);
    }

    /**
     * Dismissing a selection gives up on those mails and leaves the rest of the burst parked.
     * @covers \local_taskflow\local\messages\bulk_check\bulk_check::dismiss_rows
     */
    public function test_dismiss_rows_only_gives_up_on_the_selection(): void {
        $ids = $this->block_four_sends();
        $picked = array_slice($ids, 0, 3);

        $emailsink = $this->redirectEmails();
        $this->assertEquals(3, bulk_check::dismiss_rows($picked));
        $this->assertEquals(0, bulk_check::dismiss_rows($picked));

        $this->run_all_adhoc_tasks();

        $this->assertCount(0, $emailsink->get_messages());
        $this->assertEquals([bulk_check::STATUS_BLOCKED => 1], $this->count_by_status());
        $emailsink->close();
        $this->assertEquals(1, bulk_check::count_parked($this->messageid));
    }

    /**
     * Two selections released before cron runs send each of their mails exactly once.
     * @covers \local_taskflow\local\messages\bulk_check\bulk_check::release_rows
     */
    public function test_two_selections_released_before_cron_send_each_mail_once(): void {
        $ids = $this->block_four_sends();

        $emailsink = $this->redirectEmails();
        $this->assertEquals(2, bulk_check::release_rows(array_slice($ids, 0, 2)));
        $this->assertEquals(2, bulk_check::release_rows(array_slice($ids, 2, 2)));

        $this->run_all_adhoc_tasks();

        $this->assertCount(4, $emailsink->get_messages());
        $this->assertEquals([bulk_check::STATUS_SENT => 4], $this->count_by_status());
        $emailsink->close();
    }

    /**
     * Ids that name no parked row are ignored, whatever they name instead.
     * @covers \local_taskflow\local\messages\bulk_check\bulk_check::release_rows
     * @covers \local_taskflow\local\messages\bulk_check\bulk_check::dismiss_rows
     */
    public function test_rows_that_are_not_parked_are_left_alone(): void {
        global $DB;

        $ids = $this->block_four_sends();
        $this->assertEquals(1, bulk_check::dismiss_rows([$ids[0]]));

        $gone = (int) $DB->get_field_sql('SELECT MAX(id) FROM {' . bulk_check::TABLENAME . '}') + 100;

        // A dismissed row, a row that never existed, an empty list and rubbish.
        $this->assertEquals(0, bulk_check::release_rows([$ids[0], $gone]));
        $this->assertEquals(0, bulk_check::release_rows([]));
        $this->assertEquals(0, bulk_check::release_rows([0, -1, 'x']));
        $this->assertEquals(0, bulk_check::dismiss_rows([$ids[0], $gone]));

        $this->assertEquals([bulk_check::STATUS_BLOCKED => 3], $this->count_by_status());

        // The two that are still parked release as normal, the dismissed one does not.
        $this->assertEquals(2, bulk_check::release_rows([$ids[0], $ids[1], $ids[2]]));
    }

    /**
     * The list of mails carries one row per parked send, with everything it is filtered on.
     * @covers \local_taskflow\local\messages\bulk_check\bulk_check::get_parked_mails_sql
     */
    public function test_parked_mails_sql_lists_one_row_per_mail(): void {
        global $DB;

        $this->block_four_sends();

        [$fields, $from, $where, $params] = bulk_check::get_parked_mails_sql();
        $rows = $DB->get_records_sql("SELECT $fields FROM $from WHERE $where", $params);
        $this->assertCount(4, $rows);

        $row = reset($rows);
        $expected = [
            'id', 'messageid', 'ruleid', 'userid', 'scheduledtime', 'timemodified',
            'messagename', 'rulename', 'firstname', 'lastname', 'email', 'recipient',
        ];
        foreach ($expected as $field) {
            $this->assertObjectHasProperty($field, $row);
        }
        $this->assertEquals(self::MESSAGENAME, $row->messagename);
        $this->assertNotEmpty($row->rulename);

        // Scoping to another message empties the list, scoping to this one does not.
        [$fields, $from, $where, $params] = bulk_check::get_parked_mails_sql($this->messageid);
        $this->assertCount(4, $DB->get_records_sql("SELECT $fields FROM $from WHERE $where", $params));

        [$fields, $from, $where, $params] = bulk_check::get_parked_mails_sql($this->messageid + 999);
        $this->assertEmpty($DB->get_records_sql("SELECT $fields FROM $from WHERE $where", $params));
    }

    /**
     * The buttons of the list act on what is ticked, and on nothing else.
     * @covers \local_taskflow\table\bulk_check_table
     */
    public function test_table_actions_work_on_the_ticked_rows(): void {
        $this->setAdminUser();
        $ids = $this->block_four_sends();
        $table = new bulk_check_table('bulkcheckdummy');

        // Nothing ticked, nothing happens - the ids are never guessed from the row id.
        $result = $table->action_releaseselected(-1, json_encode(['id' => -1]));
        $this->assertEquals(1, $result['success']);
        $this->assertEquals([bulk_check::STATUS_BLOCKED => 4], $this->count_by_status());

        // A payload that is not a list of ids is ignored rather than fatal.
        $table->action_releaseselected(-1, json_encode(['checkedids' => 'nonsense']));
        $this->assertEquals([bulk_check::STATUS_BLOCKED => 4], $this->count_by_status());

        // The ids arrive as strings, exactly as the checkboxes hand them over.
        $picked = array_map('strval', array_slice($ids, 0, 2));
        $table->action_releaseselected(-1, json_encode(['checkedids' => $picked]));
        $this->assertEquals(
            [bulk_check::STATUS_RELEASING => 2, bulk_check::STATUS_BLOCKED => 2],
            $this->count_by_status()
        );

        $table->action_dismissselected(-1, json_encode(['checkedids' => array_slice($ids, 2, 1)]));
        $this->assertEquals(
            [bulk_check::STATUS_RELEASING => 2, bulk_check::STATUS_BLOCKED => 1],
            $this->count_by_status()
        );

        // The buttons that act on everything take their scope from the data, not the list.
        $table->action_dismissall(-1, json_encode(['messageid' => $this->messageid]));
        $this->assertEquals([bulk_check::STATUS_RELEASING => 2], $this->count_by_status());
    }

    /**
     * A release task stands down while another worker drains the same message, and comes back.
     *
     * The task is run on its own rather than through the whole queue: it hands its work to a
     * fresh task a minute later, and the mocked clock of these tests sits in the past, so the
     * queue runner would find that fresh task due at once and go round for ever.
     *
     * @covers \local_taskflow\task\release_bulk_check
     */
    public function test_release_task_stands_down_while_the_message_is_locked(): void {
        global $CFG, $DB;

        // The database lock factories hand the same lock to the same session twice, and the
        // whole test runs in one session, so holding the lock here would hold nothing back.
        // File locks go by the open file, which is what makes the stand down observable.
        $CFG->lock_factory = '\\core\\lock\\file_lock_factory';

        $ids = $this->block_four_sends();
        $this->assertEquals(4, bulk_check::release_rows($ids));

        $classname = '\\' . release_bulk_check::class;
        $queued = $DB->count_records('task_adhoc', ['classname' => $classname]);
        $this->assertEquals(1, $queued);

        $task = new release_bulk_check();
        $task->set_custom_data(['messageid' => $this->messageid]);

        $lock = lock_config::get_lock_factory('local_taskflow_bulk_check')
            ->get_lock('release' . $this->messageid, 0);
        $this->assertNotFalse($lock);

        try {
            $task->execute();

            // Nothing was queued for sending, and the work was not dropped either: it was
            // handed to a fresh task that comes back once the other worker is done.
            $this->assertEquals([bulk_check::STATUS_RELEASING => 4], $this->count_by_status());
            $this->assertEquals($queued + 1, $DB->count_records('task_adhoc', ['classname' => $classname]));
        } finally {
            // A lock that is not released fails the test run itself, assertions or not.
            $lock->release();
        }

        // With the message free again the very same task gets through.
        $emailsink = $this->redirectEmails();
        $task->execute();
        $this->assertEquals([bulk_check::STATUS_RELEASED => 4], $this->count_by_status());

        $this->run_all_adhoc_tasks();

        $this->assertCount(4, $emailsink->get_messages());
        $this->assertEquals([bulk_check::STATUS_SENT => 4], $this->count_by_status());
        $emailsink->close();
    }

    /**
     * Dismissing writes the decision to the history and takes the rows out of the table.
     * @covers \local_taskflow\local\messages\bulk_check\bulk_check::dismiss_rows
     * @covers \local_taskflow\local\messages\bulk_check\bulk_check::dismiss_message
     */
    public function test_dismissing_is_recorded_and_deletes_the_rows(): void {
        global $DB;

        $this->setAdminUser();
        $ids = $this->block_four_sends();

        $this->assertEquals(2, bulk_check::dismiss_rows(array_slice($ids, 0, 2)));
        $this->assertEquals([bulk_check::STATUS_BLOCKED => 2], $this->count_by_status());

        // What is left of the two is a history entry on their assignment, naming the message.
        $entries = $DB->get_records('local_taskflow_history', ['type' => history::TYPE_BULK_DISMISSED]);
        $this->assertCount(2, $entries);
        $entry = reset($entries);
        $this->assertNotEmpty($entry->assignmentid);
        $this->assertEquals(self::MESSAGENAME, json_decode($entry->data)->data);

        $this->assertEquals(2, bulk_check::dismiss_message($this->messageid));
        $this->assertEmpty($this->count_by_status());
        $this->assertEquals(4, $DB->count_records('local_taskflow_history', ['type' => history::TYPE_BULK_DISMISSED]));
    }

    /**
     * Releasing is recorded in the history the same way.
     * @covers \local_taskflow\local\messages\bulk_check\bulk_check::release_rows
     * @covers \local_taskflow\local\messages\bulk_check\bulk_check::release_message
     */
    public function test_releasing_is_recorded_in_the_history(): void {
        global $DB;

        $this->setAdminUser();
        $ids = $this->block_four_sends();

        $this->assertEquals(1, bulk_check::release_rows([$ids[0]]));
        $this->assertEquals(3, bulk_check::release_message($this->messageid));
        $this->assertEquals(4, $DB->count_records('local_taskflow_history', ['type' => history::TYPE_BULK_RELEASED]));

        // Nothing to decide twice: a second release records nothing.
        $this->assertEquals(0, bulk_check::release_message($this->messageid));
        $this->assertEquals(4, $DB->count_records('local_taskflow_history', ['type' => history::TYPE_BULK_RELEASED]));
    }

    /**
     * Sent rows are kept while they can still count, and are gone once they cannot.
     * @covers \local_taskflow\local\messages\bulk_check\bulk_check::cleanup
     */
    public function test_cleanup_removes_sent_rows_once_their_window_has_closed(): void {
        $sink = $this->redirectEmails();
        $this->enable_bulk_check(5);
        $this->schedule_for_users(3);
        time_mock::set_mock_time($this->base + 900);
        $this->run_all_adhoc_tasks();
        $sink->close();
        $this->assertEquals([bulk_check::STATUS_SENT => 3], $this->count_by_status());

        // Inside the period the rows are still counting, so nothing may go.
        $this->assertEquals(0, bulk_check::cleanup()['sent']);
        $this->assertEquals([bulk_check::STATUS_SENT => 3], $this->count_by_status());

        // A full period past their sending time they can never be counted again.
        time_mock::set_mock_time($this->base + 900 + HOURSECS + 1);
        $this->assertEquals(3, bulk_check::cleanup()['sent']);
        $this->assertEmpty($this->count_by_status());
    }

    /**
     * A pending row whose task died is removed once it is clearly not just late.
     * @covers \local_taskflow\local\messages\bulk_check\bulk_check::cleanup
     */
    public function test_cleanup_removes_pending_rows_whose_task_is_gone(): void {
        global $DB;

        $this->enable_bulk_check(5);
        $this->schedule_for_users(2);
        $this->assertEquals([bulk_check::STATUS_PENDING => 2], $this->count_by_status());

        $rows = $DB->get_records(bulk_check::TABLENAME, ['messageid' => $this->messageid], 'id ASC');
        $orphan = reset($rows);
        $DB->delete_records('task_adhoc', ['id' => $orphan->taskid]);

        // Too early: for all the cleanup knows this is a send that is merely late.
        $this->assertEquals(0, bulk_check::cleanup()['orphaned']);

        time_mock::set_mock_time($this->base + 900 + HOURSECS + 1);
        $this->assertEquals(1, bulk_check::cleanup()['orphaned']);
        $this->assertFalse($DB->record_exists(bulk_check::TABLENAME, ['id' => $orphan->id]));
        $this->assertEquals([bulk_check::STATUS_PENDING => 1], $this->count_by_status());
    }

    /**
     * A release whose task was lost gets a new one instead of sitting there forever.
     * @covers \local_taskflow\local\messages\bulk_check\bulk_check::cleanup
     */
    public function test_cleanup_rescues_a_release_that_lost_its_task(): void {
        global $DB;

        $this->block_four_sends();
        $this->assertEquals(4, bulk_check::release_message($this->messageid));
        $classname = '\\local_taskflow\\task\\release_bulk_check';
        $this->assertEquals(1, $DB->count_records('task_adhoc', ['classname' => $classname]));

        // The task is lost. The rows stay releasing and nothing would ever pick them up.
        $DB->delete_records('task_adhoc', ['classname' => $classname]);
        $this->assertEquals([bulk_check::STATUS_RELEASING => 4], $this->count_by_status());

        // Not stale yet: a release that is simply waiting for cron is left alone.
        $this->assertEquals(0, bulk_check::cleanup()['rescued']);

        time_mock::set_mock_time($this->base + 900 + bulk_check::STALERELEASE + 1);
        $this->assertEquals(1, bulk_check::cleanup()['rescued']);
        $this->assertEquals(1, $DB->count_records('task_adhoc', ['classname' => $classname]));

        // Once there is a task again, another run does not queue a second one.
        $this->assertEquals(0, bulk_check::cleanup()['rescued']);

        $sink = $this->redirectEmails();
        $this->run_all_adhoc_tasks();
        $this->assertCount(4, $sink->get_messages());
        $this->assertEquals([bulk_check::STATUS_SENT => 4], $this->count_by_status());
        $sink->close();
    }
}
