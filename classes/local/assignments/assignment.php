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

namespace local_taskflow\local\assignments;

use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\external_adapter\external_api_base;
use local_taskflow\task\check_assignment_status;
use local_taskflow\plugininfo\taskflowadapter;
use local_taskflow\local\history\history;
use core\task\manager;
use cache;
use cache_helper;
use stdClass;

/**
 * Class unit
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignment {
    /** @var array<int,self> $instances Cached assignment instances keyed by assignment ID. */
    private static array $instances = [];

    /** @var \stdClass */
    private stdClass $assignment;

    /** @var int $id Unique identifier for the assignment, automatically managed by the database. */
    public $id;

    /** @var string|null $targets Contains target-related data, possibly stored as JSON or serialized string. */
    public $targets;

    /** @var string|null $messages Contains message-related data, possibly stored as JSON or serialized string. */
    public $messages;

    /** @var int|null $userid ID of the user associated with the assignment. */
    public $userid;

    /** @var int|null $ruleid ID of the rule associated with the assignment. */
    public $ruleid;

    /** @var string|null $rulejson ID of the rule json associated with the assignment. */
    public $rulejson;

    /** @var int|null $unitid ID of the unit associated with the assignment. */
    public $unitid;

    /** @var int|null $active Indicates whether the assignment is active. Typically a boolean represented as an integer (0 or 1). */
    public $active;

    /** @var int|null $assigneddate Timestamp representing when the assignment was issued. */
    public $assigneddate;

    /** @var int|null $duedate Timestamp representing when the assignment was issued. */
    public $duedate;

    /** @var int|null $usermodified ID of the user who last modified the assignment. */
    public $usermodified;

    /** @var int|null $timecreated Timestamp for when the assignment was created. */
    public $timecreated;

    /** @var int|null $timemodified Timestamp for when the assignment was last modified. */
    public $timemodified;

    /** @var int $status Current status of the assignment, used for tracking and management. */
    public $status;

    /** @var int $keepchanges Current status of the assignment, used for tracking and management. */
    public $keepchanges;

    /** @var string $select Current status of the assignment, used for tracking and management. */
    private $select;
    /** @var string $from Current status of the assignment, used for tracking and management. */
    private $from;

     /** @var int $overduecounter , used for tracking and management. */
    public $overduecounter;

     /** @var int $prolongedcounter, used for tracking and management. */
    public $prolongedcounter;

    /**
     * Constructor for the assignment class.
     *
     * @param int $assignmentid
     *
     */
    private function __construct(int $assignmentid = 0) {
        global $DB;

        $this->select = "*";

        $concat = $DB->sql_concat("u.firstname", "' '", "u.lastname");

        $this->set_from_sql();

        if ($assignmentid > 0) {
            $this->load_from_db($assignmentid);
        }
    }

    /**
     * Returns a cached instance of the assignment for the given ID.
     * When $assignmentid is 0, always returns a fresh uncached empty instance.
     * Creates and caches a new instance if one does not yet exist.
     *
     * @param int $assignmentid
     * @return self
     */
    public static function get_instance(int $assignmentid = 0): self {
        if (empty($assignmentid)) {
            return new self(0);
        }
        if (!isset(self::$instances[$assignmentid])) {
            self::$instances[$assignmentid] = new self($assignmentid);
        }
        return self::$instances[$assignmentid];
    }

    /**
     * Destroys one or all cached assignment instances.
     *
     * @param int $assignmentid Pass 0 to destroy all instances.
     * @return void
     */
    public static function destroy_instance(int $assignmentid = 0): void {
        $cache = cache::make('local_taskflow', 'assignments');
        if (empty($assignmentid)) {
            self::$instances = [];
            $cache->purge();
        } else {
            unset(self::$instances[$assignmentid]);
            $cache->delete($assignmentid);
        }
    }

    /**
     * Returns the SQL query to fetch assignments of a given user.
     * @param int $userid
     * @param int $active
     * @param array $status
     * @param array $arguments
     *
     * @return array
     *
     */
    public function return_user_assignments_sql(int $userid, int $active = 1, array $status = [], $arguments = []): array {
        global $DB;
        return $this->return_assignments_sql($arguments, $userid, $active, 0, $status);
    }

    /**
     * Returns the SQL query to fetch assignments for a given supervisor.
     * This will return all the assigments that are assigned to subordonates of the supervisor.
     * Optionally, we can filter by user ID and active status.
     * @param int $supervisorid
     * @param array $arguments
     * @return array
     */
    public function return_supervisor_assignments_sql(int $supervisorid, array $arguments = []): array {
        global $DB;
        $builder = (new assignment_query_builder())
            ->where_active($arguments['active'] ?? null)
            ->where_toclarify_supervisor(!empty($arguments['toclarify']))
            ->where_assignmentstatus($arguments['assignmentstatus'] ?? null)
            ->where_assignmentcounter($arguments['counters'] ?? null);

        [$where, $params] = $builder->get_sql();

        $this->get_sql_parameter_array($params);

        $supervisorfield = external_api_base::return_shortname_for_functionname(
            taskflowadapter::TRANSLATOR_USER_SUPERVISOR
        );
        $deputyfield = external_api_base::return_shortname_for_functionname(
            taskflowadapter::TRANSLATOR_USER_DEPUTY
        );

        $dbfamily = $DB->get_dbfamily();
        $ispostgres = ($dbfamily === 'postgres');

        // Pre-fetch field IDs once.
        $supervisorfieldid = (int)$DB->get_field('user_info_field', 'id', ['shortname' => $supervisorfield]);

        // Step 1: users whose supervisor field equals $supervisorid (single value, plain equality).
        $subordinateids = $supervisorfieldid ? $DB->get_fieldset_sql(
            "SELECT userid FROM {user_info_data}
             WHERE fieldid = :fieldid AND data = :supervisorid",
            ['fieldid' => $supervisorfieldid, 'supervisorid' => (string)$supervisorid]
        ) : [];

        if (!empty($deputyfield)) {
            $deputyfieldid = (int)$DB->get_field('user_info_field', 'id', ['shortname' => $deputyfield]);
            if ($deputyfieldid) {
                // Supervisors that delegated to the current user (deputy list is comma-separated).
                $delegatesupervisors = $ispostgres
                    ? $DB->get_fieldset_sql(
                        "SELECT userid FROM {user_info_data}
                         WHERE fieldid = :fieldid
                         AND :supervisorid = ANY(string_to_array(data, ','))",
                        ['fieldid' => $deputyfieldid, 'supervisorid' => (string)$supervisorid]
                    )
                    : $DB->get_fieldset_sql(
                        "SELECT userid FROM {user_info_data}
                         WHERE fieldid = :fieldid AND FIND_IN_SET(:supervisorid, data)",
                        ['fieldid' => $deputyfieldid, 'supervisorid' => (string)$supervisorid]
                    );

                // For each delegating supervisor, fetch their subordinates (plain equality).
                foreach ($delegatesupervisors as $delegatesupervisorid) {
                    $deputysubordinates = $supervisorfieldid ? $DB->get_fieldset_sql(
                        "SELECT userid FROM {user_info_data}
                         WHERE fieldid = :fieldid AND data = :supervisorid",
                        ['fieldid' => $supervisorfieldid, 'supervisorid' => (string)$delegatesupervisorid]
                    ) : [];
                    $subordinateids = array_merge($subordinateids, $deputysubordinates);
                }
            }
        }

        $subordinateids = array_unique(array_map('intval', $subordinateids));

        if (empty($subordinateids)) {
            // No subordinates — short-circuit with an impossible WHERE.
            $where = '1 = 0';
            $this->from = " ( SELECT * FROM " . $this->from . " WHERE " . $where . " ) AS ts2 ";
            $where = " 1 = 1 ";
            return [$this->select, $this->from, $where, $params];
        }

        [$insql, $inparams] = $DB->get_in_or_equal($subordinateids, SQL_PARAMS_NAMED, 'sub');
        $where[] = "ts1.userid {$insql}";
        $params = array_merge($params, $inparams);

        $where = implode(' AND ', $where);

        $this->from = " ( SELECT * FROM " . $this->from . " WHERE " . $where . " ) AS ts2 ";

        $where = " 1 = 1 ";

        return [$this->select, $this->from, $where, $params];
    }

    /**
     * Generic SQL query to fetch assignments based on user ID and supervisor ID.
     * This method constructs the SQL query to retrieve assignments based on the provided parameters.
     * @param array $arguments
     * @param int $userid
     * @param int $active
     * @param int $assignmentid
     * @param array $status
     * @return array
     */
    private function return_assignments_sql(
        array $arguments,
        int $userid = 0,
        int $active = 1,
        int $assignmentid = 0,
        array $status = [],
    ): array {
        global $DB;
        $builder = new assignment_query_builder();

        // When we want a given assigmentid, we ignore all the other params.
        if (!empty($assignmentid)) {
            $builder->where_assignmentid($assignmentid)
                ->where_toclarify_assignment(!empty($arguments['toclarify']));
            [$where, $params] = $builder->get_sql();
        } else {
            $builder->where_active($active)
                ->where_userid($userid)
                ->where_status($status)
                ->where_toclarify_assignment(!empty($arguments['toclarify']));
            [$where, $params] = $builder->get_sql();
            $this->get_sql_parameter_array($params);
        }

        if (!empty($where)) {
            $where = implode(' AND ', $where);
        } else {
            $where = ' 1 = 1 ';
        }
        return [$this->select, $this->from, $where ?? ' 1 = 1 ', $params ?? []];
    }

    /**
     * Generic SQL query to fetch assignments based on user ID and supervisor ID.
     * @param array $params
     * @return void
     */
    private function get_sql_parameter_array(array &$params): void {
        global $DB;
        $assignmentfields = get_config('local_taskflow', 'assignment_fields');
        $assignmentfields = array_filter(array_map('trim', explode(',', $assignmentfields)));

        $additionalselect = '';

        if (!empty($assignmentfields)) {
            // Pre-fetch field IDs once to avoid a per-row JOIN on user_info_field.
            $fieldids = $DB->get_records_list('user_info_field', 'shortname', $assignmentfields, '', 'shortname, id');
            foreach ($assignmentfields as $fieldshortname) {
                // SQL query. The subselect will fix the "Did you remember to make the first column something...
                // ...unique in your call to get_records?" bug.
                if (isset($fieldids[$fieldshortname])) {
                    $fieldid = (int)$fieldids[$fieldshortname]->id;
                    $additionalselect .= " , (
                        SELECT uid.data
                        FROM {user_info_data} uid
                        WHERE uid.userid = ta.userid AND uid.fieldid = {$fieldid}
                        LIMIT 1
                    ) AS custom_{$fieldshortname} ";
                } else {
                    $additionalselect .= " , NULL AS custom_{$fieldshortname} ";
                }
            }
        }
        $this->set_from_sql($additionalselect);
    }

    /**
     * Loads the assignment data from the database based on the assignment ID.
     * @param int $assignmentid
     * @return void
     *
     */
    public function load_from_db($assignmentid = 0) {
        global $DB;

        $cache = cache::make('local_taskflow', 'assignments');
        $record = $cache->get($assignmentid);

        if ($record === false) {
            [$select, $from, $where, $params] = $this->return_assignments_sql([], 0, 1, $assignmentid);
            $record = $DB->get_record_sql("SELECT {$select} FROM {$from} WHERE {$where}", $params);
            if ($record) {
                $cache->set($assignmentid, $record);
            }
        }

        if ($record) {
            $this->id = $record->id;
            $this->targets = $record->targets;
            $this->messages = $record->messages;
            $this->userid = $record->userid;
            $this->ruleid = $record->ruleid;
            $this->unitid = $record->unitid;
            $this->active = $record->active;
            $this->assigneddate = $record->assigneddate;
            $this->duedate = $record->duedate;
            $this->usermodified = $record->usermodified;
            $this->timecreated = $record->timecreated;
            $this->timemodified = $record->timemodified;
            $this->status = $record->status;
            $this->rulejson = $record->rulejson;
            $this->keepchanges = $record->keepchanges;
            $this->overduecounter = $record->overduecounter;
            $this->prolongedcounter = $record->prolongedcounter;
            self::$instances[$this->id] = $this;
        }
    }

    /**
     * Returns the assignment data as a stdClass object for further processing or output.
     *
     * @return stdClass
     *
     */
    public function return_class_data(): stdClass {
        $data = new stdClass();
        $data->id = $this->id;
        $data->targets = $this->targets;
        $data->messages = $this->messages;
        $data->userid = $this->userid;
        $data->ruleid = $this->ruleid;
        $data->unitid = $this->unitid;
        $data->active = $this->active;
        $data->assigneddate = $this->assigneddate;
        $data->duedate = $this->duedate;
        $data->usermodified = $this->usermodified;
        $data->timecreated = $this->timecreated;
        $data->timemodified = $this->timemodified;
        $data->status = $this->status;
        $data->rulejson = $this->rulejson;
        $jsonobject = !empty($this->rulejson) ? json_decode($this->rulejson, true) : '';
        $data->name = $jsonobject['rulejson']['rule']['name'] ?? '';
        $data->ruledescription = $jsonobject['rulejson']['rule']['description'] ?? '';
        $data->targetgroup = $this->userid;
        $data->fullname = fullname(\core_user::get_user($this->userid));
        $data->keepchanges = $this->keepchanges;
        $data->overduecounter = $this->overduecounter;
        $data->prolongedcounter = $this->prolongedcounter;
        return $data;
    }

    /**
     * Add or update an assignment in the database.
     *
     * @param array $data
     * @param string $historytype
     * @param bool $manualupdate
     * @return stdClass
     *
     */
    public function add_or_update_assignment(
        array $data,
        string $historytype = history::TYPE_MANUAL_CHANGE,
        bool $manualupdate = false,
    ): stdClass {
        global $DB, $USER;

        if (empty($data['id'])) {
            // Create a new assignment.
            $data['timecreated'] = $data['timecreated'] ?? time();
            $data['timemodified'] = $data['timemodified'] ?? time();
            $data['status'] = $data['status'] ?? 0;
            $data['active'] = $data['active'] ?? 1;
            $data['overduecounter'] = 0;
            $data['prolongedcounter'] = 0;
            $this->id = $DB->insert_record('local_taskflow_assignment', (object)$data);
            $data['id'] = $this->id;

            if (!empty($data['duedate'])) {
                $this->set_check_assignment_status_task();
                assignment_status_facade::execute($this, $data, $manualupdate);
            }
        } else {
            $this->id = $data['id'];
            // Update an existing assignment.
            $data['timemodified'] = time();
            $data['usermodified'] = $data['usermodified'] ?? $USER->id;

            // For automatic updates, check if data should be kept.
            if (
                !empty($data['keepchanges'])
                && !$manualupdate
            ) {
                unset($data['duedate']);
                unset($data['active']);
            }

            if (
                $this->status_changed($data, $manualupdate)
                || $this->duedate != ($data['duedate'] ?? $this->duedate)
                || $this->active != ($data['active'] ?? $this->active)
                || $this->messages != ($data['messages'] ?? $this->messages)
                || $this->targets != ($data['targets'] ?? $this->targets)
                || $this->keepchanges != ($data['keepchanges'] ?? $this->keepchanges)
                || !empty($data['comment'])
            ) {
                // Only run the update when there is actually sth to update.
                $this->set_check_assignment_status_task();
                $this->set_prolonged_state_on_change($data);
                // Only if there is sth to update, we update.
                $DB->update_record('local_taskflow_assignment', (object)$data);
            } else {
                // If there are not changes, we return directly.
                return $this->return_class_data();
            }
        }
        // Reload the assignment data (delete stale cache entry first so load_from_db re-fetches).
        cache::make('local_taskflow', 'assignments')->delete($this->id);
        $this->load_from_db($this->id);
        cache_helper::purge_by_event('changesinassignmentslist');
        return $this->return_class_data();
    }

    /**
     * Here, we can introduce an additional select statement to the from SQL.
     * @return void
     */
    private function set_check_assignment_status_task(): void {
        if (
            $this->userid == null &&
            $this->id != null
        ) {
            $this->load_from_db($this->id);
        }
        $task = new check_assignment_status();
        $customdata = [
            'userid' => (string) $this->userid,
            'ruleid' => (string) $this->ruleid,
            'assignmentid' => (string) $this->id ?? '',
            'scheduledtime' => (string) $this->duedate ?? '',
        ];

        $now = time();
        $nextruntime = $this->duedate;

        $task->set_custom_data($customdata);
        $task->set_next_run_time($nextruntime > $now ? $nextruntime : $now);
        manager::reschedule_or_queue_adhoc_task($task);
    }

    /**
     * Here, we can introduce an additional select statement to the from SQL.
     * @param array $data
     * @return void
     */
    private function set_prolonged_state_on_change(&$data): void {
        if (
            $this->status == assignment_status_facade::get_status_identifier('overdue') &&
            isset($data['duedate']) &&
            $data['duedate'] > time()
        ) {
            assignment_status_facade::change_status(
                $data,
                'prolonged'
            );
        }
    }

    /**
     * Check if status has changed.
     *
     * @param array $data
     * @param bool $manualupdate
     * @return bool
     *
     */
    private function status_changed($data, $manualupdate): bool {
        $haschanged = $this->status != ($data['status'] ?? $this->status);
        if ($haschanged) {
            assignment_status_facade::execute($this, $data, $manualupdate);
            assignment_status_facade::change_status($this, $data['status']);
        }
        return $haschanged;
    }

    /**
     * Check if assignment is for this user?
     * @return bool
     */
    public function is_my_assignment(): bool {
        global $USER;
        return ($USER->id === $this->userid);
    }

    /**
     * Here, we can introduce an additional select statement to the from SQL.
     *
     * @param string $additionalselect = ''
     * @return void
     *
     */
    private function set_from_sql(string $additionalselect = ''): void {
        global $DB;

        $concat = $DB->sql_concat("u.firstname", "' '", "u.lastname");
        $modifierfullname = $DB->sql_concat("um.firstname", "' '", "um.lastname");
        $supervisorfullname = $DB->sql_concat('us.firstname', "' '", 'us.lastname');

        $supervisorfield = external_api_base::return_shortname_for_functionname(taskflowadapter::TRANSLATOR_USER_SUPERVISOR);

        $statusoverdue = assignment_status_facade::get_status_identifier('overdue');
        $statusprolonged = assignment_status_facade::get_status_identifier('prolonged');

        $statuswithcounter = $DB->sql_concat(
            'ta.status',
            "'_'",
            "
                CASE
                    WHEN ta.status = {$statusoverdue} THEN ta.overduecounter
                    WHEN ta.status = {$statusprolonged} THEN ta.prolongedcounter
                    ELSE 0
                END
            "
        );

        $additionalselect .= ", {$statuswithcounter} AS statussortkey";

        $lastcommentsfrom = "
            SELECT
                x.assignmentid,
                {$DB->sql_group_concat(
                    $DB->sql_concat(
                        'x.usermodified',
                        "' | '",
                        'x.firstname',
                        "' '",
                        'x.lastname',
                        "' | '",
                        $DB->sql_cast_char2int('x.timecreated'),
                        "' | '",
                        'x.message'
                    ),
                    '___',
                    'x.rn'
                )} AS lastinternalcomment
            FROM (
                SELECT
                    ic.assignmentid,
                    ic.message,
                    ic.timecreated,
                    ic.usermodified,
                    u.firstname,
                    u.lastname,
                    ROW_NUMBER() OVER (
                        PARTITION BY ic.assignmentid
                        ORDER BY ic.id DESC
                    ) AS rn
                FROM {local_taskflow_int_com} ic
                JOIN {user} u ON u.id = ic.usermodified
            ) x
            GROUP BY x.assignmentid
        ";
        $this->from = "(
            SELECT
                ta.id,
                tr.rulename,
                u.id AS userid,
                u.firstname,
                u.lastname,
                {$concat} AS fullname,
                {$supervisorfullname} AS supervisor,
                ta.messages,
                ta.ruleid,
                ta.unitid,
                ta.assigneddate,
                ta.duedate,
                ta.active,
                ta.status,
                ta.targets,
                tr.rulejson,
                ta.usermodified,
                {$modifierfullname} AS usermodified_fullname,
                ta.timecreated,
                ta.timemodified,
                ta.keepchanges
                {$additionalselect},
                lth.data,
                ta.overduecounter,
                ta.prolongedcounter,
                lth.annotation,
                ta.userid AS assignment_userid,
                lth.timecreated AS comment,
                icom.lastinternalcomment
                    FROM {local_taskflow_assignment} ta
                    JOIN {user} u ON ta.userid = u.id
                    JOIN {local_taskflow_rules} tr ON ta.ruleid = tr.id
                    LEFT JOIN {user} um ON ta.usermodified = um.id
                    LEFT JOIN {user_info_data} suid
                        ON suid.userid = u.id
                        AND suid.fieldid = (SELECT uif.id FROM {user_info_field} uif WHERE uif.shortname = '{$supervisorfield}')

                    /* ===== COMMENTS (PRE-AGGREGATED) ===== */
                    LEFT JOIN (
                        {$lastcommentsfrom}
                    ) icom ON icom.assignmentid = ta.id

                    LEFT JOIN {user} us
                        ON us.id = {$DB->sql_cast_char2int("NULLIF(suid.data, '')")}

                    /* ===== LAST HISTORY ENTRY ===== */
                    LEFT JOIN (
                        SELECT lth1.assignmentid, lth1.data, lth1.annotation, lth1.timecreated
                        FROM {local_taskflow_history} lth1
                        INNER JOIN (
                            SELECT assignmentid, MAX(id) AS maxid
                            FROM {local_taskflow_history}
                            WHERE annotation <> ''
                            GROUP BY assignmentid
                        ) lth2 ON lth1.id = lth2.maxid
                    ) lth ON lth.assignmentid = ta.id
        ) AS ts1";
    }
}
