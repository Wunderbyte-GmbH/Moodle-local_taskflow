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
 * Unit class to manage users.
 *
 * @package local_taskflow
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\local\history;

use cache_helper;
use stdClass;

/**
 * Class unit
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


/**
 * History classt to manage assignment history.
 */
class history {
    /**
     * TYPE_MESSAGE
     *
     * @var string
     */
    public const TYPE_MESSAGE = 'message';
    /**
     * TYPE_MANUAL_CHANGE
     *
     * @var string
     */
    public const TYPE_MANUAL_CHANGE = 'manual_change';
    /**
     * TYPE_LIMIT
     *
     * @var string
     */
    public const TYPE_LIMIT_REACHED = 'limit_reached';
    /**
     * TYPE_USER_ACTION
     *
     * @var string
     */
    public const TYPE_USER_ACTION = 'user_action';
    /**
     * TYPE_RULE_CHANGE
     *
     * @var string
     */
    public const TYPE_RULE_CHANGE = 'rule_change';

    /**
     * TYPE_RULE_CHANGE
     * @var string
     */
    public const TYPE_MAIL_SEND = 'mail_send';

    /**
     * TYPE_COMPETENCY_COMPLETED
     * @var string
     */
    public const TYPE_COMPETENCY_COMPLETED = 'competency_completed';

    /**
     * TYPE_COMPETENCY_UPLOAD
     * @var string
     */
    public const TYPE_COMPETENCY_UPLOAD = 'competency_upload';

    /**
     * TYPE_COURSE_ENROLLED
     * @var string
     */
    public const TYPE_COURSE_ENROLLED = 'course_enrolled';

    /**
     * TYPE_COURSE_COMPLETED
     * @var string
     */
    public const TYPE_COURSE_COMPLETED = 'course_completed';

    /**
     * Log a history entry.
     * @param int $assignmentid
     * @param int $userid
     * @param string $type
     * @param array $data
     * @param string $createdby
     * @return int
     */
    public static function log($assignmentid, $userid, $type, array $data, $createdby = null) {
        global $DB, $USER;

        $record = new stdClass();
        $record->assignmentid = $assignmentid;
        $record->userid = $userid;
        $record->type = $type;
        $record->data = json_encode($data);
        $record->timecreated = time();
        $record->createdby = $createdby ?? $USER->id;

        $historyid = $DB->insert_record('local_taskflow_history', $record);
        cache_helper::purge_by_event(
            'changesinhistorylist'
        );
        return $historyid;
    }

    /**
     * Get history for an assignment.
     * @param string $assignmentid
     * @param string $limit
     * @return array
     */
    public static function get_history($assignmentid, $limit = 100) {
        global $DB;

        [$select, $from, $where, $params] = self::return_sql($assignmentid, 0, '', $limit);
        $records = $DB->get_records_sql("SELECT $select FROM $from WHERE $where", $params);

        return $records;
    }

    /**
     * Fetch records from the history table based on parameters.
     * @param int $assignmentid
     * @param int $userid
     * @param string $historytype
     * @param string $limit
     * @return array
     */
    public static function return_sql($assignmentid = 0, $userid = 0, $historytype = '', $limit = 0): array {

        $select = '*';
        $from = '{local_taskflow_history}';
        $params = [];

        if (!empty($assignmentid)) {
            $where[] = 'assignmentid = :assignmentid';
            $params['assignmentid'] = $assignmentid;
        }

        if (!empty($userid)) {
            $where[] = 'userid = :userid';
            $params['userid'] = $userid;
        }

        if (!empty($historytype)) {
            $where[] = 'type = :historytype';
            $params['historytype'] = $historytype;
        }

        $where = !empty($where) ? implode(' AND ', $where) : '';

        if ($limit > 0) {
            $where .= ' LIMIT :limit';
            $params['limit'] = $limit;
        }

        return [$select, $from, $where, $params];
    }
}
