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

namespace local_taskflow\local;

use cache_helper;
use context_system;
use local_taskflow\event\request_created;
use local_taskflow\event\request_treated;
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\assignments\assignment;
use local_taskflow\local\assignments\types\standard_assignment;
use local_taskflow\local\history\history;
use local_taskflow\local\requests\request_types\requests_manager;
use local_taskflow\local\requests\request_types\types\allowselfextension;
use local_taskflow\local\requests\request_types\types\allowselfnotrelevant;
use local_taskflow\local\requests\request_types\types\allowuploadevidence;
use local_taskflow\local\rules\rules;
use stdClass;

/**
 * Class requests
 *
 * @package    local_taskflow
 * @copyright  2025 Georg Maißer <georg.maißer@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class requests {
    /** @var string Table name */
    protected static $table = 'local_taskflow_requests';

    /** @var int treated status untreated */
    public const TREATED_STATUS_UNTREATED = 0;

    /** @var int treated status declined */
    public const TREATED_STATUS_DECLINED = 1;

    /** @var int treated status confirmed */
    public const TREATED_STATUS_CONFIRMED = 2;

    /**
     * Create a new request entry.
     *
     * @param int $requesttype
     * @param int $userid
     * @param int $assignmentid
     * @param int $status
     * @param int $usermodified
     * @param string $comment
     * @param array $otherdata
     *
     * @return int New record ID
     * @throws \dml_exception
     */
    public static function create(
        int $requesttype,
        int $userid,
        int $assignmentid,
        int $status = 0,
        int $usermodified = 0,
        string $comment = "",
        array $otherdata = []
    ): int {
        global $DB, $USER;

        if (!has_capability('local/taskflow:createrequests', context_system::instance())) {
            return 0;
        };

        $record = new \stdClass();
        $record->request = $requesttype;
        $record->assignmentid = $assignmentid;
        $record->userid = $userid;
        $record->status = $status;
        $record->usermodified = $usermodified ?: $USER->id;
        $record->timecreated = time();
        $record->timemodified = time();
        $record->comment = $comment;
        $record->forhr = self::get_request_receiver($assignmentid, $requesttype);

        if (!empty($otherdata)) {
            $record->json = json_encode($otherdata);
        }

        $id = $DB->insert_record(self::$table, $record);

        $event = request_created::create([
            'objectid' => $id,
            'context'  => \context_system::instance(),
            'userid'   => $userid,
            'other'    => [
                'usermodified' => $record->usermodified,
                'status' => $status,
                'assignmentid' => $assignmentid,
                'comment' => $comment,
            ],
        ]);
        $event->trigger();

        cache_helper::purge_by_event('changesinrequestslist');

        return $id;
    }

    /**
     * Get a request by ID.
     *
     * @param int $assignmentid
     * @param string $requesttype
     * @return int
     */
    private static function get_request_receiver(int $assignmentid, string $requesttype): int {
        $assignment = standard_assignment::instance($assignmentid);
        $rule = rules::instance($assignment->get_ruleid());
        $rulejson = json_decode($rule->get_rulesjson());
        $requestmanager = new requests_manager();
        $requesttypeids = $requestmanager->get_request_types_with_ids();
        $rulekey = 'receiver_' . $requesttypeids[$requesttype];
        $receiver = 0;
        if (
            isset($rulejson->rulejson->rule->actions[0]->requests->$rulekey) &&
            is_number($rulejson->rulejson->rule->actions[0]->requests->$rulekey)
        ) {
            $receiver = $rulejson->rulejson->rule->actions[0]->requests->$rulekey;
        }
        return $receiver;
    }

    /**
     * Get a request by ID.
     *
     * @param int $id
     * @return \stdClass|null
     * @throws \dml_exception
     */
    public static function get(int $id): ?\stdClass {
        global $DB;

        return $DB->get_record(self::$table, ['id' => $id], '*', IGNORE_MISSING);
    }

    /**
     * Update an existing request.
     *
     * @param int $id
     * @param array $data Fields to update
     * @param int|null $usermodified
     * @return bool True on success
     * @throws \dml_exception
     */
    public static function update(int $id, array $data, ?int $usermodified = null): bool {
        global $DB, $USER;

        if (!$DB->record_exists(self::$table, ['id' => $id])) {
            return false;
        }

        $record = $DB->get_record(self::$table, ['id' => $id]);
        foreach ($data as $field => $value) {
            if (property_exists($record, $field)) {
                $record->$field = $value;
            }
        }

        $record->usermodified = $usermodified ?? $USER->id;
        $record->timemodified = time();

        return $DB->update_record(self::$table, $record);
    }

    /**
     * Delete a request entry.
     *
     * @param int $id
     * @return bool
     * @throws \dml_exception
     */
    public static function delete(int $id): bool {
        global $DB;

        if (!$DB->record_exists(self::$table, ['id' => $id])) {
            return false;
        }
        return $DB->delete_records(self::$table, ['id' => $id]);
    }

    /**
     * Get all requests for a user.
     *
     * @param int $userid
     * @param int|null $status
     * @return array of \stdClass records
     * @throws \dml_exception
     */
    public static function get_by_user(int $userid, ?int $status = null): array {
        global $DB;

        $params = ['userid' => $userid];
        $where = 'userid = :userid';

        if ($status !== null) {
            $where .= ' AND status = :status';
            $params['status'] = $status;
        }

        return $DB->get_records_select(self::$table, $where, $params, 'timecreated DESC');
    }

    /**
     * Helper function to resolve the given status as a string.
     *
     * @param int $status
     *
     * @return string
     *
     */
    public static function resolve_status(int $status): string {
        switch ($status) {
            case (allowselfnotrelevant::ID):
                return get_string('notrelevantformedisplayname', 'local_taskflow');
            case (allowselfextension::ID):
                return get_string('requestprolongation', 'local_taskflow');
            case (allowuploadevidence::ID):
                return get_string('requestevidence', 'local_taskflow');
            default:
                return get_string('statusunknown', 'local_taskflow');
        }
    }

    /**
     * Helper function to resolve the given treatedstatus as a string.
     *
     * @param int $treatedstatus
     *
     * @return string
     *
     */
    public static function resolve_treated(int $treatedstatus): string {

        switch ($treatedstatus) {
            case (self::TREATED_STATUS_UNTREATED):
                return get_string('open', 'local_taskflow');
            case (self::TREATED_STATUS_CONFIRMED):
                return get_string('confirmed', 'local_taskflow');
            case (self::TREATED_STATUS_DECLINED):
                return get_string('declined', 'local_taskflow');
            default:
                return get_string('statusunknown', 'local_taskflow');
        }
    }

    /**
     * Trigger confirmation of request.
     *
     * @param int $id
     * @param int $assignmentid
     * @param int $userid
     * @param int $status
     *
     * @return bool
     *
     */
    public function treat_request(int $id, int $assignmentid, int $userid, int $status): bool {

        $requestconfirmed = $this->update_request_treated($id, $assignmentid, $userid, $status);
        if (!$requestconfirmed) {
            return false;
        }

        if ($status === self::TREATED_STATUS_CONFIRMED) {
            // Only if request is confirmed, take action for assignment.
            $assignment = new assignment($assignmentid);
            assignment_status_facade::change_status($assignment, assignment_status_facade::get_status_identifier('notrelevant'));
            standard_assignment::update_or_create_assignment((object) $assignment, history::TYPE_STATUS_CHANGED);
        }

        return true;
    }

    /**
     * Confirm a request.
     *
     * @param int $id
     * @param int $assignmentid
     * @param int $userid
     * @param int $status
     *
     * @return bool
     *
     */
    public function update_request_treated(int $id, int $assignmentid, int $userid, int $status) {
        global $USER, $DB;

        $record = [
            'id' => $id,
            'treated' => $status,
        ];
        $success = $DB->update_record('local_taskflow_requests', $record);
        if (!$success) {
            return false;
        }

        $event = request_treated::create([
            'objectid' => $id,
            'context'  => \context_system::instance(),
            'userid'   => $userid,
            'other'    => [
                'usermodified' => $USER->id,
                'status' => $status,
                'assignmentid' => $assignmentid,
            ],
        ]);
        $event->trigger();

        $historytype = $status === self::TREATED_STATUS_CONFIRMED
            ? history::TYPE_REQUEST_CONFIRMED : history::TYPE_REQUEST_DECLINED;
        history::log(
            $assignmentid,
            $userid,
            $historytype,
            [
                'action' => 'created',
                'data' => (object)[
                    'requestid' => $id,
                ],
            ],
            $USER->id,
        );
        cache_helper::purge_by_event('changesinrequestslist');
        return true;
    }
    /**
     * Get request by user and assignment.
     *
     * @param int $userid
     * @param int $assignmentid
     *
     * @return int
     *
     */
    public function get_id_by_user_and_assignment(int $userid, int $assignmentid){
        global $DB;
        $record = $DB->get_record(self::$table, [
            'userid' => $userid,
            'assignmentid' => $assignmentid,
        ], '*', IGNORE_MISSING);
        return $record->id;
    }
}
