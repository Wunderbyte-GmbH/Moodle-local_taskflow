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
use local_taskflow\local\messages\bulk_check\bulk_check_config;
use local_taskflow\local\messages\messages_factory;
use local_taskflow\task\bulk_check_reminder;
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

        // Nothing was queued again and nothing went out.
        $this->assertCount(0, $emailsink->get_messages());
        $this->assertEquals([bulk_check::STATUS_DISMISSED => 4], $this->count_by_status());
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
        $this->assertEquals([bulk_check::STATUS_DISMISSED => 4], $this->count_by_status());
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
     * Rescheduling the same send supersedes the earlier row so it stops counting.
     * @covers \local_taskflow\local\messages\bulk_check\bulk_check::record_scheduled
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
     * @covers \local_taskflow\local\messages\bulk_check\bulk_check::supersede_pending
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
