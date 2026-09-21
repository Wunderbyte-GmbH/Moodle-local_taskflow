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
use html_writer;
use moodle_url;
use local_taskflow\local\history\history;
use local_taskflow\task\release_bulk_check;
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

    /** @var int The parked send was given up on by hand and will never go out. */
    public const STATUS_DISMISSED = 5;

    /** @var int The send was let through by hand and waits for the release task. */
    public const STATUS_RELEASING = 6;

    /** @var int How many ids of a hand picked selection are dealt with in one statement. */
    public const IDCHUNK = 500;

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

        if (!self::applies($message)) {
            // The checker was switched off, or this message was unticked, after the send
            // was queued. Nothing may hold it back now: falling through to the check would
            // measure the burst against the default limit instead of letting it out, which
            // is the opposite of what switching the checker off is for.
            return self::SEND;
        }

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
     * @return int The number of sends handed over for release.
     */
    public static function release(int $messageid, int $ruleid): int {
        return self::start_release($messageid, $ruleid);
    }

    /**
     * Releases every blocked send of a message, across all of its rules.
     *
     * @param int $messageid
     * @return int The number of sends handed over for release.
     */
    public static function release_message(int $messageid): int {
        return self::start_release($messageid, null);
    }

    /**
     * Hands a parked burst over to the release task.
     *
     * Queueing one send task per row here would mean thousands of statements inside a web
     * request, and a timeout half way through would leave the burst split between two
     * states with no way to tell which rows had been dealt with. Instead the rows are moved
     * to releasing in a single statement and the queueing itself happens in the background.
     * That also makes a second click harmless: it finds nothing blocked and queues nothing.
     *
     * @param int $messageid
     * @param int|null $ruleid Null releases every rule of the message.
     * @return int
     */
    private static function start_release(int $messageid, ?int $ruleid): int {
        $where = 'messageid = :messageid';
        $params = ['messageid' => $messageid];
        if ($ruleid !== null) {
            $where .= ' AND ruleid = :ruleid';
            $params['ruleid'] = $ruleid;
        }

        $count = self::move_blocked($where, $params, self::STATUS_RELEASING);
        if (empty($count)) {
            return 0;
        }

        self::queue_release_task($messageid);

        return $count;
    }

    /**
     * Moves every blocked row the condition matches into another status, in one statement.
     *
     * The blocked state is added here rather than by the callers, so that the one rule that
     * matters - nothing but a parked row is ever touched by hand - lives in a single place.
     *
     * @param string $where Without the status, which is added here.
     * @param array $params
     * @param int $status
     * @return int The number of rows that were moved.
     */
    private static function move_blocked(string $where, array $params, int $status): int {
        global $DB;

        $where = '(' . $where . ') AND status = :blocked';
        $params['blocked'] = self::STATUS_BLOCKED;

        $count = $DB->count_records_select(self::TABLENAME, $where, $params);
        if (empty($count)) {
            return 0;
        }

        $DB->execute(
            "UPDATE {" . self::TABLENAME . "}
                SET status = :newstatus, timemodified = :now
              WHERE " . $where,
            $params + ['newstatus' => $status, 'now' => time()]
        );

        return $count;
    }

    /**
     * Hands one message over to the background task that queues its sends.
     *
     * @param int $messageid
     * @return void
     */
    private static function queue_release_task(int $messageid): void {
        $task = new release_bulk_check();
        $task->set_custom_data(['messageid' => $messageid]);
        $task->set_next_run_time(time());
        manager::queue_adhoc_task($task);
    }

    /**
     * Turns whatever the checkboxes sent into a clean list of row ids.
     *
     * The ids come off the client as strings and are never trusted for anything but their
     * numeric value: everything they can name is checked against the blocked state before
     * it is touched.
     *
     * @param array $ids
     * @return array
     */
    private static function clean_ids(array $ids): array {
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    /**
     * Releases the parked sends that were picked out of the list by hand.
     *
     * The ids come from the checkboxes, so they can name rows of more than one message and
     * rows another admin has already dealt with. Only rows that are still blocked are moved,
     * and because the release task works a whole message at a time it is queued once per
     * message the selection touches, not once per row.
     *
     * @param array $ids Ids of local_taskflow_bulk_check rows.
     * @return int The number of sends handed over for release.
     */
    public static function release_rows(array $ids): int {
        $ids = self::clean_ids($ids);
        if (empty($ids)) {
            return 0;
        }

        $count = 0;
        foreach (array_chunk($ids, self::IDCHUNK) as $chunk) {
            $count += self::release_chunk($chunk);
        }
        return $count;
    }

    /**
     * Releases one chunk of hand picked rows.
     *
     * @param array $ids
     * @return int
     */
    private static function release_chunk(array $ids): int {
        global $DB;

        [$insql, $inparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'bcid');

        // The messages have to be read before the flip: afterwards these very rows are no
        // longer blocked and the query would come back empty.
        $messageids = $DB->get_fieldset_sql(
            "SELECT DISTINCT messageid
               FROM {" . self::TABLENAME . "}
              WHERE id $insql
                AND status = :blocked",
            $inparams + ['blocked' => self::STATUS_BLOCKED]
        );
        if (empty($messageids)) {
            return 0;
        }

        $count = self::move_blocked("id $insql", $inparams, self::STATUS_RELEASING);
        if (empty($count)) {
            return 0;
        }

        foreach ($messageids as $messageid) {
            self::queue_release_task((int) $messageid);
        }

        return $count;
    }

    /**
     * Gives up on the parked sends that were picked out of the list by hand.
     *
     * @param array $ids Ids of local_taskflow_bulk_check rows.
     * @return int The number of dismissed sends.
     */
    public static function dismiss_rows(array $ids): int {
        global $DB;

        $ids = self::clean_ids($ids);
        if (empty($ids)) {
            return 0;
        }

        $count = 0;
        foreach (array_chunk($ids, self::IDCHUNK) as $chunk) {
            [$insql, $inparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'bcid');
            $count += self::move_blocked("id $insql", $inparams, self::STATUS_DISMISSED);
        }
        return $count;
    }

    /**
     * Releases every parked send there is, one message at a time.
     *
     * @return int The number of sends handed over for release.
     */
    public static function release_all(): int {
        $count = 0;
        foreach (self::get_parked_messages() as $parked) {
            $count += self::start_release((int) $parked->messageid, null);
        }
        return $count;
    }

    /**
     * Gives up on every parked send there is.
     *
     * @return int The number of dismissed sends.
     */
    public static function dismiss_all(): int {
        $count = 0;
        foreach (self::get_parked_messages() as $parked) {
            $count += self::do_dismiss((int) $parked->messageid, null);
        }
        return $count;
    }

    /**
     * Queues the send tasks of everything that is waiting to be released.
     *
     * Called from the release task, in batches, so that a burst of any size gets through
     * without holding a single request open.
     *
     * @param int $messageid
     * @param int $batchsize
     * @return int The number of sends queued in this batch.
     */
    public static function queue_released_batch(int $messageid, int $batchsize = 500): int {
        global $DB;

        $rows = $DB->get_records(
            self::TABLENAME,
            ['messageid' => $messageid, 'status' => self::STATUS_RELEASING],
            'id ASC',
            '*',
            0,
            $batchsize
        );

        $queued = 0;
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
            $queued++;
        }
        return $queued;
    }

    /**
     * Gives up on every parked send of a message and rule.
     *
     * The messages are never sent and the burst stops turning up in the reminder. This is
     * the other way out of the parked state: release when the burst should still go out,
     * dismiss when it should not.
     *
     * @param int $messageid
     * @param int $ruleid
     * @return int The number of dismissed sends.
     */
    public static function dismiss(int $messageid, int $ruleid): int {
        return self::do_dismiss($messageid, $ruleid);
    }

    /**
     * Gives up on every parked send of a message, across all of its rules.
     *
     * @param int $messageid
     * @return int The number of dismissed sends.
     */
    public static function dismiss_message(int $messageid): int {
        return self::do_dismiss($messageid, null);
    }

    /**
     * Marks parked sends as dismissed in one statement.
     *
     * @param int $messageid
     * @param int|null $ruleid Null dismisses every rule of the message.
     * @return int
     */
    private static function do_dismiss(int $messageid, ?int $ruleid): int {
        $where = 'messageid = :messageid';
        $params = ['messageid' => $messageid];
        if ($ruleid !== null) {
            $where .= ' AND ruleid = :ruleid';
            $params['ruleid'] = $ruleid;
        }

        return self::move_blocked($where, $params, self::STATUS_DISMISSED);
    }

    /**
     * Every burst that is still parked, newest first.
     *
     * One entry per message and rule, carrying the name of the message, how many sends are
     * waiting and when the oldest of them was blocked.
     *
     * @return array
     */
    public static function get_parked_bursts(): array {
        global $DB;
        // The first column keys the result, so it has to be unique per group: one message
        // record can be referenced from several rules, and those must not collapse.
        $sql = "SELECT MIN(b.id) AS id, b.messageid, b.ruleid, m.name AS messagename,
                       COUNT(b.id) AS parked, MIN(b.timemodified) AS oldest
                  FROM {" . self::TABLENAME . "} b
             LEFT JOIN {local_taskflow_messages} m ON m.id = b.messageid
                 WHERE b.status = :blocked
              GROUP BY b.messageid, b.ruleid, m.name
              ORDER BY MIN(b.timemodified) ASC";
        return $DB->get_records_sql($sql, ['blocked' => self::STATUS_BLOCKED]);
    }

    /**
     * Returns the sql of the parked list, grouped by message.
     *
     * Grouping by messageid alone makes messageid unique per row, so unlike the two column
     * grouping above it can key the result itself. Do not replace it with MIN(b.id): the
     * message id is also what the release and dismiss buttons act on.
     *
     * The message is joined loosely because deleting a message leaves its parked rows
     * behind, and those have to stay visible so that they can be disposed of.
     *
     * @return array [$fields, $from, $where, $params]
     */
    public static function get_parked_messages_sql(): array {
        $from = "(SELECT b.messageid AS id,
                         b.messageid,
                         MAX(m.name) AS messagename,
                         COUNT(b.id) AS parked,
                         COUNT(DISTINCT b.ruleid) AS rules,
                         MIN(b.timemodified) AS oldest
                    FROM {" . self::TABLENAME . "} b
               LEFT JOIN {local_taskflow_messages} m ON m.id = b.messageid
                   WHERE b.status = :blocked
                GROUP BY b.messageid) parkedmessages";

        return ['*', $from, '1=1', ['blocked' => self::STATUS_BLOCKED]];
    }

    /**
     * Every message that still has parked sends, oldest first.
     *
     * @return array
     */
    public static function get_parked_messages(): array {
        global $DB;
        [$fields, $from, $where, $params] = self::get_parked_messages_sql();
        return $DB->get_records_sql(
            "SELECT $fields FROM $from WHERE $where ORDER BY oldest ASC",
            $params
        );
    }

    /**
     * How many mails are parked, either altogether or for one message.
     *
     * @param int $messageid Zero counts every message.
     * @return int
     */
    public static function count_parked(int $messageid = 0): int {
        global $DB;
        $conditions = ['status' => self::STATUS_BLOCKED];
        if (!empty($messageid)) {
            $conditions['messageid'] = $messageid;
        }
        return $DB->count_records(self::TABLENAME, $conditions);
    }

    /**
     * Returns the sql of the parked list, one row per mail that is waiting.
     *
     * The id of the row is the id of the parked send, because that is what the checkboxes
     * of the list hand back when a selection is sent or dismissed.
     *
     * Everything the list filters, searches and sorts on is selected inside the subquery, so
     * that the library can use the column names on their own in its own where clauses.
     *
     * The message and the rule are joined loosely and coalesced: deleting either leaves the
     * parked rows behind, and those have to stay visible so that they can be disposed of.
     *
     * @param int $messageid Limits the list to one message, zero takes all of them.
     * @return array [$fields, $from, $where, $params]
     */
    public static function get_parked_mails_sql(int $messageid = 0): array {
        global $DB;

        $params = ['blocked' => self::STATUS_BLOCKED];
        $scope = '';
        if (!empty($messageid)) {
            $scope = ' AND b.messageid = :messageid';
            $params['messageid'] = $messageid;
        }

        $recipient = $DB->sql_concat('u.lastname', "' '", 'u.firstname');

        // Every name field of the site, or fullname() complains about the ones it misses.
        $namefields = [];
        foreach (\core_user\fields::get_name_fields() as $namefield) {
            $namefields[] = 'u.' . $namefield;
        }
        $namefields = implode(",\n                         ", $namefields);

        $from = "(SELECT b.id,
                         b.messageid,
                         b.ruleid,
                         b.userid,
                         b.scheduledtime,
                         b.timemodified,
                         COALESCE(m.name, '') AS messagename,
                         COALESCE(r.rulename, '') AS rulename,
                         $namefields,
                         u.email,
                         $recipient AS recipient
                    FROM {" . self::TABLENAME . "} b
                    JOIN {user} u ON u.id = b.userid
               LEFT JOIN {local_taskflow_messages} m ON m.id = b.messageid
               LEFT JOIN {local_taskflow_rules} r ON r.id = b.ruleid
                   WHERE b.status = :blocked" . $scope . ") parkedmails";

        return ['*', $from, '1=1', $params];
    }

    /**
     * The parked sends of one message, for the list inside its section.
     *
     * Ordered by rule so that the section can group them, and limited because a burst can
     * hold thousands of users and only a sample of them is ever rendered.
     *
     * @param int $messageid
     * @param int $limit
     * @return array
     */
    public static function get_parked_rows(int $messageid, int $limit = 51): array {
        global $DB;
        $sql = "SELECT b.id, b.userid, b.ruleid, b.scheduledtime, b.timemodified,
                       u.firstname, u.lastname, u.email,
                       r.rulename
                  FROM {" . self::TABLENAME . "} b
                  JOIN {user} u ON u.id = b.userid
             LEFT JOIN {local_taskflow_rules} r ON r.id = b.ruleid
                 WHERE b.messageid = :messageid
                   AND b.status = :blocked
              ORDER BY r.rulename ASC, u.lastname ASC, u.firstname ASC, b.id ASC";
        return $DB->get_records_sql(
            $sql,
            ['messageid' => $messageid, 'blocked' => self::STATUS_BLOCKED],
            0,
            $limit
        );
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
     * through the burst. Superseded rows never happen; released, releasing and dismissed
     * ones were decided by hand, so none of those four is counted.
     *
     * Leaving releasing and released in the count would break the release itself: the sends
     * that were just let through would be counted against their own limit and blocked all
     * over again, so the admin would get a success message and no mail would ever go out.
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
                   AND status NOT IN (:superseded, :released, :releasing, :dismissed)";
        return (int) $DB->count_records_sql($sql, self::window_params($row, $period) + [
            'superseded' => self::STATUS_SUPERSEDED,
            'released' => self::STATUS_RELEASED,
            'releasing' => self::STATUS_RELEASING,
            'dismissed' => self::STATUS_DISMISSED,
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

            // The list is reached straight from the alert, already narrowed to the message
            // the burst belongs to, so that the decision can be taken there and then.
            $url = new moodle_url('/local/taskflow/bulkcheck.php', ['messageid' => $message->id]);

            $a = (object) [
                'message' => $message->name ?? '',
                'count' => $count,
                'limit' => bulk_check_config::get_limit($message),
                'period' => format_time(bulk_check_config::get_period($message)),
                // The id is of no use to whoever reads this, but it stays in the data so
                // that a language pack which still names it keeps working.
                'ruleid' => $row->ruleid,
                'rule' => self::get_rule_name((int) $row->ruleid),
                'link' => $url->out(false),
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

                // The plain part carries the bare address, the html part a real link.
                $a->link = $url->out(false);
                $msg->fullmessage = taskflow_stringmanager::get_string('bulkcheckblockedbody', $a, $userto->lang);
                $a->link = html_writer::link($url, $url->out(false));
                $msg->fullmessagehtml = taskflow_stringmanager::get_string('bulkcheckblockedbody', $a, $userto->lang);

                $msg->fullmessageformat = FORMAT_HTML;
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
     * The name of a rule, or a placeholder when the rule is gone.
     *
     * A rule can be deleted while its sends are still parked, and the alert has to say
     * something useful even then.
     *
     * @param int $ruleid
     * @return string
     */
    private static function get_rule_name(int $ruleid): string {
        global $DB;
        $name = $DB->get_field('local_taskflow_rules', 'rulename', ['id' => $ruleid]);
        if (empty($name)) {
            return taskflow_stringmanager::get_string('bulkcheckdeletedrule', $ruleid);
        }
        return format_string($name);
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
