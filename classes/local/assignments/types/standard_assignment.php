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
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\local\assignments\types;

use local_taskflow\event\assignment_status_changed;
use local_taskflow\local\assignments\assignments_interface;
use local_taskflow\local\assignments\status\assignment_status;
use local_taskflow\local\history\history;
use local_taskflow\local\rules\rules;
use stdClass;

/**
 * Class unit
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class standard_assignment implements assignments_interface {
    /** @var array The instances of the class. */
    private static $instances = [];

    /** @var int $id The unique ID of the unit. */
    private $id;

    /** @var string $targets The name of the unit. */
    private $targets;

    /** @var string $messages The name of the unit. */
    private $messages;

    /** @var string $userid The name of the unit. */
    private $userid;

    /** @var string $ruleid The name of the unit. */
    private $ruleid;

    /** @var int $active The state of the assignment. */
    private $active;

    /** @var int $status The status of the assignment. */
    private $status;

    /** @var int $timemodified The time of last modification. */
    private $timemodified;

    /** @var string Event name for user updated. */
    private const TABLE = 'local_taskflow_assignment';

    /**
     * Private constructor to prevent direct instantiation.
     *
     * @param stdClass $data The record from the database.
     */
    private function __construct(stdClass $data) {
        $this->id = $data->id;
        $this->targets = $data->targets;
        $this->messages = $data->messages;
        $this->userid = $data->userid;
        $this->ruleid = $data->ruleid;
        $this->active = (int)$data->active;
        $this->timemodified = $data->timemodified;
        $this->status = $data->status;
    }

    /**
     * Get the instance of the class for a specific ID.
     * @param int $id
     * @return standard_assignment
     */
    public static function instance($id) {
        global $DB;
        if (!isset(self::$instances[$id])) {
            $data = $DB->get_record(self::TABLE, [ 'id' => $id]);
            self::$instances[$id] = new self($data);
        }
        return self::$instances[$id];
    }

    /**
     * Factory for the organisational units
     * @param stdClass $assignment
     * @return int
     */
    public static function update_or_create_assignment($assignment) {
        if (!empty($assignment->id)) {
            $existing = self::get_assignment_record_by_assignmentid($assignment->id);
        } else {
            $existing = self::get_assignment_by_userid_ruleid($assignment);
        }

        if (!$existing) {
            $existing = self::create_assignment($assignment);
        } else {
            $existing = self::update_assignment($existing, $assignment);
        }
        if (!isset(self::$instances[$existing->id])) {
            self::instance($existing->id);
        }
        return self::$instances[$existing->id];
    }


    /**
     * Update the current unit.
     * @param int $unitid
     * @param int $userid
     * @return bool
     */
    public static function delete_assignments($unitid, $userid) {
        global $DB;
        return $DB->delete_records(
            self::TABLE,
            [
                'userid' => $userid,
                'unitid' => $unitid,
            ]
        );
    }

    /**
     * Get the assigment by userid & ruleid.
     * @param stdClass $assignment
     * @return mixed
     */
    private static function get_assignment_by_userid_ruleid($assignment) {
        global $DB;
        return $DB->get_record(self::TABLE, [
            'userid' => $assignment->userid,
            'ruleid' => $assignment->ruleid,
        ]);
    }

    /**
     * Get the assignment directly by id.
     * @param int $assignmentid
     * @return mixed
     */
    public static function get_assignment_record_by_assignmentid($assignmentid) {
        global $DB;
        return $DB->get_record(self::TABLE, [
            'id' => $assignmentid,
        ]);
    }

    /**
     * Create a new unit and return its instance.
     * @param stdClass $assignment
     * @return standard_assignment
     */
    private static function create_assignment($assignment) {
        global $DB, $USER;
        $id = $DB->insert_record(self::TABLE, $assignment);
        $assignment->id = $id;
        self::$instances[$id] = new self($assignment);
        $instance = self::$instances[$id];

        history::log(
            $id,
            $assignment->userid,
            history::TYPE_RULE_CHANGE,
            [
                'action' => 'created',
            ],
            $USER->id
        );

        return $instance;
    }

    /**
     * Update the current unit.
     * @param stdClass $existing
     * @param stdClass $assignment
     * @return stdClass
     */
    private static function update_assignment($existing, $assignment) {
        global $DB, $USER;
        self::check_if_status_changed($existing, $assignment->status);
        $existing->targets = $assignment->targets;
        $existing->messages = $assignment->messages;
        $existing->active = $assignment->active;
        $existing->duedate = self::set_due_date($assignment->ruleid);
        $existing->usermodified = $assignment->usermodified;
        $existing->timemodified = $assignment->timemodified;
        $existing->status = $assignment->status;
        $DB->update_record(self::TABLE, $existing);
        $id = $existing->id;
        $assignment->id = $id;
        self::$instances[$id] = new self($assignment);
        $instance = self::$instances[$id];

        history::log(
            $id,
            $assignment->userid,
            history::TYPE_RULE_CHANGE,
            [
                'action' => 'updated',
                'data' => [
                    'status' => $assignment->status ?? 0,
                ],
            ],
            $USER->id
        );

        return $instance;
    }

    /**
     * Update the current unit.
     * @param stdClass $record
     * @param int $newstatus
     * @return void
     */
    private static function check_if_status_changed($record, $newstatus) {
        if (
            $record->status != $newstatus &&
            $newstatus != assignment_status::STATUS_COMPLETED
        ) {
            $event = assignment_status_changed::create([
                'objectid' => $record->id,
                'context'  => \context_system::instance(),
                'other'    => [
                    'assignmentid' => $record->id,
                ],
            ]);
            \local_taskflow\observer::call_event_handler($event);
        }
    }

    /**
     * Get the assigneddate of the rule.
     * @param int $ruleid
     * @return int
     */
    private static function set_due_date($ruleid) {
        $rule = rules::instance($ruleid);
        if (empty($rule)) {
            return 0;
        }
        $rulesjson = json_decode($rule->get_rulesjson());
        if (!isset($rulesjson->rulejson->rule)) {
            return 0;
        }
        $ruleduedate = $rulesjson->rulejson->rule;
        switch ($ruleduedate->duedatetype ?? '') {
            case 'fixeddate':
                return (int) $ruleduedate->fixeddate;
            case 'duration':
                return time() + (int) $ruleduedate->duration;
            default:
                return 0;
        }
    }

    /**
     * Get the assigneddate of the rule.
     * @param int $userid
     * @return array
     */
    public static function get_all_active_user_assignments($userid) {
        global $DB;
        return $DB->get_records(
            self::TABLE,
            [
                'userid' => $userid,
                'active' => '1',
            ]
        );
    }

    /**
     * Get the assigneddate of the rule.
     * @param int $userid
     * @param array $unitids
     * @return array
     */
    public static function get_all_invalid_unit_user_assignments($userid, $unitids) {
        global $DB;
        [$insql, $inparams] = $DB->get_in_or_equal($unitids, SQL_PARAMS_NAMED);
        $params = array_merge(['userid' => $userid], $inparams);

        $sql = "
            SELECT * FROM {" . self::TABLE . "}
            WHERE userid = :userid AND active = 1 AND unitid $insql
        ";
        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Set active state of assignment.
     * @param int|null $activestate
     *
     * @return int
     *
     */
    public function set_active_state(?int $activestate = null) {
        if ($activestate === null) {
            $this->active = $this->active > 0 ? 0 : 1;
        } else {
            $this->active = $activestate;
        }
        $this->timemodified = time();
        return $this->active;
    }
}
