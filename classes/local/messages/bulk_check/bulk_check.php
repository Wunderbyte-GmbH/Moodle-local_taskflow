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

use cache_helper;
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
 * A row only lives while its send is undecided or parked. Once the mail went out the row is
 * deleted, and the send keeps counting through local_taskflow_sent_messages instead, which
 * records every sent message anyway. Keeping a copy here would double every send.
 *
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bulk_check {
    /** @var string */
    public const TABLENAME = 'local_taskflow_bulk_check';

    /** @var int The send is queued and has not been decided yet. */
    public const STATUS_PENDING = 0;

    /** @var int The send was blocked and waits for a manual release. */
    public const STATUS_BLOCKED = 2;

    /** @var int The send was released manually and requeued. */
    public const STATUS_RELEASED = 3;

    // The values 1, 4 and 5 once meant sent, superseded and dismissed. None is a state any
    // more: a send that will never happen has no business in this table and is deleted on
    // the spot, and a send that did happen is counted from the sent messages instead. The
    // numbers stay unused rather than being given a new meaning.

    /** @var int The send was let through by hand and waits for the release task. */
    public const STATUS_RELEASING = 6;

    /** @var int How many ids of a hand picked selection are dealt with in one statement. */
    public const IDCHUNK = 500;

    /** @var int How many rows one batch of the cleanup deletes. */
    public const CLEANUPBATCH = 1000;

    /** @var int How long a releasing row may wait before the cleanup gives it its task back. */
    public const STALERELEASE = 15 * MINSECS;

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
     * Any earlier pending row of the same message, rule and user is dropped first. Its
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

        self::drop_pending($userid, $ruleid, $messageid);

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
     * Takes the row of the running task out of the table, because the message went out.
     *
     * From here on the send is counted through local_taskflow_sent_messages, which the
     * sending wrote a moment ago, so the row has nothing left to say. This runs after the
     * message actually went out, so that a failure in between leaves the row pending and
     * the retry of the task can claim it again.
     *
     * @param int|null $taskid The id of the running adhoc task.
     * @return void
     */
    public static function mark_sent(?int $taskid): void {
        global $DB;
        $row = self::get_row_by_task($taskid);
        if (empty($row)) {
            return;
        }
        $DB->delete_records(self::TABLENAME, ['id' => $row->id]);
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

        $rows = self::select_blocked($where, $params);
        if (empty($rows)) {
            return 0;
        }
        self::log_decision($rows, history::TYPE_BULK_RELEASED);

        $count = self::move_blocked($where, $params, self::STATUS_RELEASING);
        if (empty($count)) {
            return 0;
        }

        self::queue_release_task($messageid);

        return $count;
    }

    /**
     * The blocked rows a condition matches, with what a history entry needs to know.
     *
     * @param string $where Without the status, which is added here.
     * @param array $params
     * @return array
     */
    private static function select_blocked(string $where, array $params): array {
        global $DB;
        return $DB->get_records_select(
            self::TABLENAME,
            '(' . $where . ') AND status = :blocked',
            $params + ['blocked' => self::STATUS_BLOCKED],
            'id ASC',
            'id, messageid, ruleid, userid'
        );
    }

    /**
     * Deletes rows that are still blocked, in chunks.
     *
     * The status is checked again in the statement itself: a row that another admin has
     * released in the meantime is on its way out and must not be taken away from them.
     *
     * @param array $ids
     * @return int The number of rows that were deleted.
     */
    private static function delete_blocked(array $ids): int {
        global $DB;

        $count = 0;
        foreach (array_chunk($ids, self::IDCHUNK) as $chunk) {
            [$insql, $inparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'bcid');
            $select = "id $insql AND status = :blocked";
            $params = $inparams + ['blocked' => self::STATUS_BLOCKED];
            $count += $DB->count_records_select(self::TABLENAME, $select, $params);
            $DB->delete_records_select(self::TABLENAME, $select, $params);
        }
        return $count;
    }

    /**
     * Writes one history entry per row for a decision taken by hand.
     *
     * Releasing and dismissing are the two points where a person overrides the checker.
     * A dismissed row is deleted, so this entry is the only record left that the mail was
     * given up on, and the one that answers why somebody never got it.
     *
     * The entries are inserted in one go and the cache is dropped once. history::log()
     * purges it on every call, and a burst can hold thousands of rows.
     *
     * @param array $rows As returned by select_blocked().
     * @param string $type One of the history::TYPE_BULK_* constants.
     * @return void
     */
    private static function log_decision(array $rows, string $type): void {
        global $DB, $USER;

        if (empty($rows)) {
            return;
        }

        $messagenames = self::get_message_names(array_unique(array_column($rows, 'messageid')));
        $assignmentids = self::get_assignment_ids($rows);

        $now = time();
        $records = [];
        foreach ($rows as $row) {
            $assignmentid = $assignmentids[$row->userid . '_' . $row->ruleid] ?? 0;
            if (empty($assignmentid)) {
                // The history hangs off the assignment, and this one is gone already.
                continue;
            }
            $records[] = (object) [
                'assignmentid' => $assignmentid,
                'userid' => $row->userid,
                'type' => $type,
                'data' => json_encode([
                    'action' => $type,
                    'data' => $messagenames[$row->messageid] ?? '',
                ]),
                'timecreated' => $now,
                'createdby' => (int) ($USER->id ?? 0),
                'annotation' => '',
            ];
        }

        if (!empty($records)) {
            $DB->insert_records('local_taskflow_history', $records);
            cache_helper::purge_by_event('changesinhistorylist');
        }
    }

    /**
     * The names of the given messages, keyed by id.
     *
     * @param array $messageids
     * @return array
     */
    private static function get_message_names(array $messageids): array {
        global $DB;
        $names = [];
        foreach ($DB->get_records_list('local_taskflow_messages', 'id', $messageids, '', 'id, name') as $message) {
            $names[$message->id] = $message->name;
        }
        return $names;
    }

    /**
     * The current assignment of every user and rule among the rows, keyed "userid_ruleid".
     *
     * A user can have been assigned to the same rule more than once, and the newest one is
     * the one that matters, the same way standard_assignment looks it up.
     *
     * @param array $rows
     * @return array
     */
    private static function get_assignment_ids(array $rows): array {
        global $DB;

        $ruleids = array_unique(array_column($rows, 'ruleid'));
        [$insql, $inparams] = $DB->get_in_or_equal($ruleids, SQL_PARAMS_NAMED, 'rid');
        $assignments = $DB->get_records_select(
            'local_taskflow_assignment',
            "ruleid $insql",
            $inparams,
            'id ASC',
            'id, userid, ruleid'
        );

        $ids = [];
        foreach ($assignments as $assignment) {
            // Ascending order, so the last one written wins: the newest assignment.
            $ids[$assignment->userid . '_' . $assignment->ruleid] = (int) $assignment->id;
        }
        return $ids;
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

        // The rows have to be read before the flip: afterwards they are no longer blocked
        // and the query would come back empty.
        $rows = self::select_blocked("id $insql", $inparams);
        if (empty($rows)) {
            return 0;
        }
        self::log_decision($rows, history::TYPE_BULK_RELEASED);

        $count = self::move_blocked("id $insql", $inparams, self::STATUS_RELEASING);
        if (empty($count)) {
            return 0;
        }

        foreach (array_unique(array_column($rows, 'messageid')) as $messageid) {
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
            $count += self::dismiss_selected(self::select_blocked("id $insql", $inparams));
        }
        return $count;
    }

    /**
     * Records that the rows were given up on, then deletes them.
     *
     * A dismissed send will never happen, so the row is not kept in some final state: it
     * cannot influence a verdict any more and would only make the table grow. The history
     * entry written first is what remains of the decision.
     *
     * @param array $rows As returned by select_blocked().
     * @return int The number of dismissed sends.
     */
    private static function dismiss_selected(array $rows): int {
        if (empty($rows)) {
            return 0;
        }
        self::log_decision($rows, history::TYPE_BULK_DISMISSED);
        return self::delete_blocked(array_keys($rows));
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
     * dismiss when it should not. The rows are deleted, see dismiss_selected().
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
     * Records and deletes the parked sends of a message.
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

        return self::dismiss_selected(self::select_blocked($where, $params));
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
     * Deletes the pending rows of a user and rule, and the send tasks that go with them.
     *
     * Called when a send is scheduled again and whenever the sent messages of an
     * assignment are wiped. Those sends will never happen, so nothing is kept: a row that
     * cannot influence a verdict any more has no business in this table. The task goes
     * with it, because a task that still ran would find no row and be let through
     * unchecked.
     *
     * @param int $userid
     * @param int $ruleid
     * @param int|null $messageid Null covers every message of the rule.
     * @return void
     */
    public static function drop_pending(int $userid, int $ruleid, ?int $messageid = null): void {
        global $DB;

        $conditions = ['userid' => $userid, 'ruleid' => $ruleid, 'status' => self::STATUS_PENDING];
        if ($messageid !== null) {
            $conditions['messageid'] = $messageid;
        }
        $rows = $DB->get_records(self::TABLENAME, $conditions, '', 'id, taskid');
        if (empty($rows)) {
            return;
        }

        $taskids = array_filter(array_map(fn($row) => (int) $row->taskid, $rows));
        if (!empty($taskids)) {
            [$insql, $inparams] = $DB->get_in_or_equal($taskids, SQL_PARAMS_NAMED, 'tid');
            $DB->delete_records_select(
                'task_adhoc',
                "id $insql AND classname = :classname",
                $inparams + ['classname' => '\\' . send_taskflow_message::class]
            );
        }
        $DB->delete_records_list(self::TABLENAME, 'id', array_keys($rows));
    }

    /**
     * Removes what can no longer matter and rescues what got stuck.
     *
     * A pending row whose task is gone will never be sent, so it goes. A releasing row
     * whose release task is gone is the opposite case, mail that was meant to go out, and
     * gets its task back instead. Sent rows need no tidying: they are deleted the moment
     * the mail is out.
     *
     * Deleting happens in batches: a burst can leave thousands of orphans behind and one
     * unbounded statement would hold the table locked for the duration.
     *
     * @param int $maxbatches How many batches one run gets through.
     * @return array Counts keyed by orphaned and rescued.
     */
    public static function cleanup(int $maxbatches = 20): array {
        global $DB;

        $now = time();
        $horizon = $now - bulk_check_config::get_global_period();
        $result = ['orphaned' => 0, 'rescued' => 0];

        // Pending rows whose task is gone. The horizon keeps a row that was written a moment
        // ago out of it, and rules out counting against a send that is merely late.
        for ($batch = 0; $batch < $maxbatches; $batch++) {
            $rows = $DB->get_records_sql(
                "SELECT b.id
                   FROM {" . self::TABLENAME . "} b
              LEFT JOIN {task_adhoc} t ON t.id = b.taskid
                  WHERE b.status = :pending
                    AND t.id IS NULL
                    AND b.scheduledtime < :horizon
               ORDER BY b.id ASC",
                ['pending' => self::STATUS_PENDING, 'horizon' => $horizon],
                0,
                self::CLEANUPBATCH
            );
            if (empty($rows)) {
                break;
            }
            $DB->delete_records_list(self::TABLENAME, 'id', array_keys($rows));
            $result['orphaned'] += count($rows);
            if (count($rows) < self::CLEANUPBATCH) {
                break;
            }
        }

        // Releasing rows that nobody is working on any more.
        $messageids = $DB->get_fieldset_select(
            self::TABLENAME,
            'DISTINCT messageid',
            'status = :releasing AND timemodified < :stale',
            ['releasing' => self::STATUS_RELEASING, 'stale' => $now - self::STALERELEASE]
        );
        foreach ($messageids as $messageid) {
            if (self::release_task_queued((int) $messageid)) {
                continue;
            }
            self::queue_release_task((int) $messageid);
            $result['rescued']++;
        }

        return $result;
    }

    /**
     * Whether a release task is waiting for the given message.
     *
     * @param int $messageid
     * @return bool
     */
    private static function release_task_queued(int $messageid): bool {
        global $DB;
        $sql = "SELECT COUNT(id)
                  FROM {task_adhoc}
                 WHERE classname = :classname
                   AND " . $DB->sql_compare_text('customdata') . " = :customdata";
        return $DB->count_records_sql($sql, [
            'classname' => '\\' . release_bulk_check::class,
            'customdata' => json_encode(['messageid' => $messageid]),
        ]) > 0;
    }

    /**
     * Counts the sends of the same message and rule around the sending time of the row.
     *
     * Two sources add up. The rows here are the sends still ahead: pending ones, and blocked
     * ones, which keep counting and so make the verdict stable while cron works through the
     * burst. The sent messages are the sends already behind, so that a slow flood is caught
     * as well as a burst. Sends that will never happen are in neither.
     *
     * Released and releasing rows were decided by hand and are not counted. Leaving them in
     * would break the release itself: the sends that were just let through would be counted
     * against their own limit and blocked all over again, so the admin would get a success
     * message and no mail would ever go out. Once such a send is out it counts like any
     * other sent message, which is right: the mails did go out.
     *
     * @param stdClass $row
     * @param int $period
     * @return int
     */
    private static function count_window(stdClass $row, int $period): int {
        global $DB;
        $params = self::window_params($row, $period);

        $queued = "SELECT COUNT(id)
                     FROM {" . self::TABLENAME . "}
                    WHERE messageid = :messageid
                      AND ruleid = :ruleid
                      AND scheduledtime >= :windowstart
                      AND scheduledtime <= :windowend
                      AND status NOT IN (:released, :releasing)";
        $count = (int) $DB->count_records_sql($queued, $params + [
            'released' => self::STATUS_RELEASED,
            'releasing' => self::STATUS_RELEASING,
        ]);

        $sent = "SELECT COUNT(id)
                   FROM {local_taskflow_sent_messages}
                  WHERE messageid = :messageid
                    AND ruleid = :ruleid
                    AND timesent >= :windowstart
                    AND timesent <= :windowend";
        $count += (int) $DB->count_records_sql($sent, $params);

        return $count;
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
