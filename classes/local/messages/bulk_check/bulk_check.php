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
 * Bulk send checker.
 *
 * @package local_taskflow
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\local\messages\bulk_check;

use core\lock\lock_config;
use core\message\message;
use core\task\manager;
use core_user;
use local_taskflow\local\history\history;
use local_taskflow\task\send_taskflow_message;
use local_taskflow\taskflow_stringmanager;
use stdClass;

/**
 * Detects and parks bulk sends of a message.
 *
 * A message that is bulk checked is not sent straight away. Its scheduling is postponed by
 * a configurable delay, and a row is written here for every send that is queued. When the
 * postponed task finally runs it counts the rows of the same message and rule around its
 * own sending time. If that count is over the configured limit the send is not carried out,
 * the row is parked as blocked and the configured users are informed once per burst.
 *
 * The counting window is anchored on the sending time, not on the time the row was written.
 * A reminder that is scheduled today for a date in a week would otherwise never be counted.
 *
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bulk_check {
    /** @var string */
    public const TABLENAME = 'local_taskflow_bulk_check';

    /** @var int The send is queued and has not been decided yet. */
    public const STATUS_PENDING = 0;

    /** @var int The message was sent. */
    public const STATUS_SENT = 1;

    /** @var int The send was blocked and waits for a manual release. */
    public const STATUS_BLOCKED = 2;

    /** @var int The send was released manually and requeued. */
    public const STATUS_RELEASED = 3;

    /** @var int The send was replaced by a newer one and will never happen. */
    public const STATUS_SUPERSEDED = 4;

    /** @var int Verdict: the task may send. */
    public const SEND = 0;

    /** @var int Verdict: the task must not send. */
    public const BLOCKED = 1;

    /**
     * Whether the given message has to pass the bulk check.
     *
     * @param stdClass $message The local_taskflow_messages record.
     * @return bool
     */
    public static function applies(stdClass $message): bool {
        return bulk_check_config::is_bulk_checked($message);
    }

    /**
     * Records that a send of a bulk checked message was queued.
     *
     * Any earlier pending row of the same message, rule and user is superseded first. Its
     * task was either replaced by the newly queued one or is gone, so counting it would
     * inflate the window with a send that never happens.
     *
     * @param int $messageid
     * @param int $ruleid
     * @param int $userid
     * @param int $taskid The task_adhoc record this row belongs to.
     * @param int $scheduledtime When the message is due to be sent.
     * @return int The id of the inserted record.
     */
    public static function record_scheduled(
        int $messageid,
        int $ruleid,
        int $userid,
        int $taskid,
        int $scheduledtime
    ): int {
        global $DB;
        $now = time();

        $DB->set_field_select(
            self::TABLENAME,
            'status',
            self::STATUS_SUPERSEDED,
            'messageid = :messageid AND ruleid = :ruleid AND userid = :userid AND status = :status',
            [
                'messageid' => $messageid,
                'ruleid' => $ruleid,
                'userid' => $userid,
                'status' => self::STATUS_PENDING,
            ]
        );

        return $DB->insert_record(self::TABLENAME, (object) [
            'messageid' => $messageid,
            'ruleid' => $ruleid,
            'userid' => $userid,
            'taskid' => $taskid,
            'status' => self::STATUS_PENDING,
            'notified' => 0,
            'scheduledtime' => $scheduledtime,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Decides whether the running task is allowed to send.
     *
     * @param stdClass $message The local_taskflow_messages record.
     * @param int $ruleid
     * @param int $userid
     * @param int|null $taskid The id of the running adhoc task.
     * @param stdClass|null $assignment The assignment, only used for the history entry.
     * @return int Either self::SEND or self::BLOCKED.
     */
    public static function check(
        stdClass $message,
        int $ruleid,
        int $userid,
        ?int $taskid,
        ?stdClass $assignment = null
    ): int {
        global $DB;

        $row = self::get_row_by_task($taskid);
        if (empty($row)) {
            // Nothing was recorded for this task, so there is nothing to check against.
            return self::SEND;
        }

        if ((int) $row->status === self::STATUS_RELEASED) {
            return self::SEND;
        }

        if ((int) $row->status === self::STATUS_BLOCKED) {
            // The verdict was already taken, do not take it a second time.
            return self::BLOCKED;
        }

        $count = self::count_window($row, bulk_check_config::get_period($message));
        if ($count <= bulk_check_config::get_limit($message)) {
            return self::SEND;
        }

        $DB->update_record(self::TABLENAME, (object) [
            'id' => $row->id,
            'status' => self::STATUS_BLOCKED,
            'timemodified' => time(),
        ]);

        if (!empty($assignment->id)) {
            history::log(
                $assignment->id,
                $userid,
                history::TYPE_LIMIT_REACHED,
                [
                    'action' => 'bulk_check_blocked',
                    'data' => $message->name ?? '',
                    'count' => $count,
                ]
            );
        }

        self::notify_burst($row, $message, $count);

        return self::BLOCKED;
    }

    /**
     * Marks the row of the running task as sent.
     *
     * This runs after the message actually went out, so that a failure in between leaves
     * the row pending and the retry of the task can claim it again.
     *
     * @param int|null $taskid The id of the running adhoc task.
     * @return void
     */
    public static function mark_sent(?int $taskid): void {
        global $DB;
        $row = self::get_row_by_task($taskid);
        if (empty($row) || (int) $row->status === self::STATUS_SENT) {
            return;
        }
        $DB->update_record(self::TABLENAME, (object) [
            'id' => $row->id,
            'status' => self::STATUS_SENT,
            'timemodified' => time(),
        ]);
    }

    /**
     * Releases every blocked send of a message and rule and queues it again.
     *
     * @param int $messageid
     * @param int $ruleid
     * @return int The number of released sends.
     */
    public static function release(int $messageid, int $ruleid): int {
        global $DB;

        $rows = $DB->get_records(self::TABLENAME, [
            'messageid' => $messageid,
            'ruleid' => $ruleid,
            'status' => self::STATUS_BLOCKED,
        ]);

        $released = 0;
        foreach ($rows as $row) {
            $task = new send_taskflow_message();
            $task->set_custom_data([
                'userid' => $row->userid,
                'messageid' => $row->messageid,
                'ruleid' => $row->ruleid,
                'manualchanged' => false,
            ]);
            $task->set_next_run_time(time());
            $taskid = manager::queue_adhoc_task($task);
            if (empty($taskid)) {
                continue;
            }
            $DB->update_record(self::TABLENAME, (object) [
                'id' => $row->id,
                'status' => self::STATUS_RELEASED,
                'taskid' => $taskid,
                'timemodified' => time(),
            ]);
            $released++;
        }
        return $released;
    }

    /**
     * Supersedes all pending rows of a user and rule.
     *
     * Called whenever the sent messages of an assignment are wiped, so that sends which
     * will never happen stop counting towards the window.
     *
     * @param int $userid
     * @param int $ruleid
     * @return void
     */
    public static function supersede_pending(int $userid, int $ruleid): void {
        global $DB;
        $DB->set_field_select(
            self::TABLENAME,
            'status',
            self::STATUS_SUPERSEDED,
            'userid = :userid AND ruleid = :ruleid AND status = :status',
            [
                'userid' => $userid,
                'ruleid' => $ruleid,
                'status' => self::STATUS_PENDING,
            ]
        );
    }

    /**
     * Counts the sends of the same message and rule around the sending time of the row.
     *
     * Blocked rows keep counting, which is what makes the verdict stable while cron works
     * through the burst. Superseded rows never happen and released ones were approved by
     * hand, so neither of those two is counted.
     *
     * @param stdClass $row
     * @param int $period
     * @return int
     */
    private static function count_window(stdClass $row, int $period): int {
        global $DB;
        $sql = "SELECT COUNT(id)
                  FROM {" . self::TABLENAME . "}
                 WHERE messageid = :messageid
                   AND ruleid = :ruleid
                   AND scheduledtime >= :windowstart
                   AND scheduledtime <= :windowend
                   AND status NOT IN (:superseded, :released)";
        return (int) $DB->count_records_sql($sql, self::window_params($row, $period) + [
            'superseded' => self::STATUS_SUPERSEDED,
            'released' => self::STATUS_RELEASED,
        ]);
    }

    /**
     * The counting window of a row.
     *
     * The window is centred on the sending time of the row and is exactly one period wide,
     * so that a limit of fifty per hour really means fifty per hour. Centring it rather
     * than only looking backwards is what lets every row of one burst see every other row,
     * which in turn makes them all reach the same verdict no matter in which order cron
     * works through them.
     *
     * @param stdClass $row
     * @param int $period
     * @return array
     */
    private static function window_params(stdClass $row, int $period): array {
        $half = intdiv($period, 2);
        return [
            'messageid' => $row->messageid,
            'ruleid' => $row->ruleid,
            'windowstart' => $row->scheduledtime - $half,
            'windowend' => $row->scheduledtime + $half,
        ];
    }

    /**
     * Informs the configured users that a burst was blocked, once per burst.
     *
     * Several cron workers can run tasks of the same burst at the same time, so only the
     * one that gets the lock sends the notification. The others still block, they just
     * stay quiet about it.
     *
     * @param stdClass $row
     * @param stdClass $message
     * @param int $count
     * @return void
     */
    private static function notify_burst(stdClass $row, stdClass $message, int $count): void {
        global $DB;

        $factory = lock_config::get_lock_factory('local_taskflow_bulk_check');
        $lock = $factory->get_lock('m' . $row->messageid . 'r' . $row->ruleid, 0);
        if (!$lock) {
            return;
        }

        try {
            if (self::burst_was_notified($row, bulk_check_config::get_period($message))) {
                return;
            }
            $DB->set_field(self::TABLENAME, 'notified', 1, ['id' => $row->id]);

            $a = (object) [
                'message' => $message->name ?? '',
                'count' => $count,
                'limit' => bulk_check_config::get_limit($message),
                'period' => format_time(bulk_check_config::get_period($message)),
                'ruleid' => $row->ruleid,
            ];

            foreach (bulk_check_config::get_notify_userids() as $userid) {
                $userto = core_user::get_user($userid);
                if (empty($userto)) {
                    continue;
                }
                $msg = new message();
                $msg->component = 'local_taskflow';
                $msg->name = 'bulkchecknotification';
                $msg->userfrom = core_user::get_noreply_user();
                $msg->userto = $userto;
                $msg->subject = taskflow_stringmanager::get_string('bulkcheckblockedsubject', $a, $userto->lang);
                $msg->fullmessage = taskflow_stringmanager::get_string('bulkcheckblockedbody', $a, $userto->lang);
                $msg->fullmessageformat = FORMAT_HTML;
                $msg->fullmessagehtml = $msg->fullmessage;
                $msg->smallmessage = $msg->subject;
                $msg->notification = 1;
                message_send($msg);
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Whether any row of the same burst already raised the notification.
     *
     * @param stdClass $row
     * @param int $period
     * @return bool
     */
    private static function burst_was_notified(stdClass $row, int $period): bool {
        global $DB;
        $sql = "SELECT COUNT(id)
                  FROM {" . self::TABLENAME . "}
                 WHERE messageid = :messageid
                   AND ruleid = :ruleid
                   AND scheduledtime >= :windowstart
                   AND scheduledtime <= :windowend
                   AND notified = 1";
        return $DB->count_records_sql($sql, self::window_params($row, $period)) > 0;
    }

    /**
     * Returns the row that belongs to the given adhoc task.
     *
     * @param int|null $taskid
     * @return stdClass|null
     */
    private static function get_row_by_task(?int $taskid): ?stdClass {
        global $DB;
        if (empty($taskid)) {
            return null;
        }
        $rows = $DB->get_records(self::TABLENAME, ['taskid' => $taskid], 'id DESC', '*', 0, 1);
        if (empty($rows)) {
            return null;
        }
        return reset($rows);
    }
}
