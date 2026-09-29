<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Privacy subsystem implementation for local_taskflow.
 *
 * @package     local_taskflow
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\privacy;

use context;
use context_system;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy subsystem implementation for local_taskflow.
 *
 * All data of the plugin lives in the system context. The user is either the subject of the
 * data (assignee, unit member, recipient) or the actor (supervisor writing a comment or a
 * history entry on someone else's assignment). Data of the subject is deleted, references to
 * the actor on data of other users are anonymised.
 *
 * @package     local_taskflow
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /** Tables and the columns which directly point to a user. */
    private const USERCOLUMNS = [
        'local_taskflow_assignment' => ['userid', 'usermodified'],
        'local_taskflow_unit_members' => ['userid', 'usermodified'],
        'local_taskflow_rules' => ['userid'],
        'local_taskflow_sent_messages' => ['userid'],
        'local_taskflow_bulk_check' => ['userid'],
        'local_taskflow_history' => ['userid', 'createdby'],
        'local_taskflow_assgin_comp' => ['userid'],
        'local_taskflow_requests' => ['userid', 'usermodified'],
        'local_taskflow_int_com' => ['usermodified'],
        'local_taskflow_last_seen' => ['userid'],
        'local_taskflow_units' => ['usermodified'],
        'local_taskflow_unit_rel' => ['usermodified'],
        'local_taskflow_messages' => ['usermodified'],
        'local_taskflow_bulk_config' => ['usermodified'],
    ];

    /** Configuration tables which only store who modified a record last. */
    private const CONFIGTABLES = [
        'local_taskflow_units',
        'local_taskflow_unit_rel',
        'local_taskflow_messages',
        'local_taskflow_bulk_config',
    ];

    /**
     * Returns meta data about this system.
     *
     * @param collection $collection The initialised collection to add items to.
     * @return collection A listing of user data stored through this system.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_taskflow_assignment', [
            'userid' => 'privacy:metadata:local_taskflow_assignment:userid',
            'ruleid' => 'privacy:metadata:local_taskflow_assignment:ruleid',
            'unitid' => 'privacy:metadata:local_taskflow_assignment:unitid',
            'targets' => 'privacy:metadata:local_taskflow_assignment:targets',
            'status' => 'privacy:metadata:local_taskflow_assignment:status',
            'active' => 'privacy:metadata:local_taskflow_assignment:active',
            'duedate' => 'privacy:metadata:local_taskflow_assignment:duedate',
            'assigneddate' => 'privacy:metadata:local_taskflow_assignment:assigneddate',
            'periodstart' => 'privacy:metadata:local_taskflow_assignment:periodstart',
            'completeddate' => 'privacy:metadata:local_taskflow_assignment:completeddate',
            'overduecounter' => 'privacy:metadata:local_taskflow_assignment:overduecounter',
            'prolongedcounter' => 'privacy:metadata:local_taskflow_assignment:prolongedcounter',
            'usermodified' => 'privacy:metadata:usermodified',
            'timecreated' => 'privacy:metadata:timecreated',
            'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:local_taskflow_assignment');

        $collection->add_database_table('local_taskflow_history', [
            'assignmentid' => 'privacy:metadata:assignmentid',
            'userid' => 'privacy:metadata:local_taskflow_history:userid',
            'type' => 'privacy:metadata:local_taskflow_history:type',
            'data' => 'privacy:metadata:local_taskflow_history:data',
            'annotation' => 'privacy:metadata:local_taskflow_history:annotation',
            'createdby' => 'privacy:metadata:local_taskflow_history:createdby',
            'timecreated' => 'privacy:metadata:timecreated',
        ], 'privacy:metadata:local_taskflow_history');

        $collection->add_database_table('local_taskflow_requests', [
            'userid' => 'privacy:metadata:local_taskflow_requests:userid',
            'assignmentid' => 'privacy:metadata:assignmentid',
            'request' => 'privacy:metadata:local_taskflow_requests:request',
            'status' => 'privacy:metadata:local_taskflow_requests:status',
            'comment' => 'privacy:metadata:local_taskflow_requests:comment',
            'json' => 'privacy:metadata:local_taskflow_requests:json',
            'usermodified' => 'privacy:metadata:usermodified',
            'timecreated' => 'privacy:metadata:timecreated',
            'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:local_taskflow_requests');

        $collection->add_database_table('local_taskflow_int_com', [
            'assignmentid' => 'privacy:metadata:assignmentid',
            'message' => 'privacy:metadata:local_taskflow_int_com:message',
            'usermodified' => 'privacy:metadata:local_taskflow_int_com:usermodified',
            'timecreated' => 'privacy:metadata:timecreated',
            'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:local_taskflow_int_com');

        $collection->add_database_table('local_taskflow_assgin_comp', [
            'assignmentid' => 'privacy:metadata:assignmentid',
            'userid' => 'privacy:metadata:local_taskflow_assgin_comp:userid',
            'competencyid' => 'privacy:metadata:local_taskflow_assgin_comp:competencyid',
            'competencyevidenceid' => 'privacy:metadata:local_taskflow_assgin_comp:competencyevidenceid',
            'status' => 'privacy:metadata:local_taskflow_assgin_comp:status',
            'validationondate' => 'privacy:metadata:local_taskflow_assgin_comp:validationondate',
            'timecreated' => 'privacy:metadata:timecreated',
            'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:local_taskflow_assgin_comp');

        $collection->add_database_table('local_taskflow_last_seen', [
            'userid' => 'privacy:metadata:local_taskflow_last_seen:userid',
            'assignmentid' => 'privacy:metadata:assignmentid',
            'lastseen' => 'privacy:metadata:local_taskflow_last_seen:lastseen',
        ], 'privacy:metadata:local_taskflow_last_seen');

        $collection->add_database_table('local_taskflow_unit_members', [
            'unitid' => 'privacy:metadata:local_taskflow_unit_members:unitid',
            'userid' => 'privacy:metadata:local_taskflow_unit_members:userid',
            'active' => 'privacy:metadata:local_taskflow_unit_members:active',
            'timeadded' => 'privacy:metadata:local_taskflow_unit_members:timeadded',
            'usermodified' => 'privacy:metadata:usermodified',
            'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:local_taskflow_unit_members');

        $collection->add_database_table('local_taskflow_rules', [
            'userid' => 'privacy:metadata:local_taskflow_rules:userid',
            'rulename' => 'privacy:metadata:local_taskflow_rules:rulename',
            'isactive' => 'privacy:metadata:local_taskflow_rules:isactive',
        ], 'privacy:metadata:local_taskflow_rules');

        $collection->add_database_table('local_taskflow_sent_messages', [
            'messageid' => 'privacy:metadata:messageid',
            'ruleid' => 'privacy:metadata:ruleid',
            'userid' => 'privacy:metadata:local_taskflow_sent_messages:userid',
            'timesent' => 'privacy:metadata:local_taskflow_sent_messages:timesent',
        ], 'privacy:metadata:local_taskflow_sent_messages');

        $collection->add_database_table('local_taskflow_bulk_check', [
            'messageid' => 'privacy:metadata:messageid',
            'ruleid' => 'privacy:metadata:ruleid',
            'userid' => 'privacy:metadata:local_taskflow_bulk_check:userid',
            'status' => 'privacy:metadata:local_taskflow_bulk_check:status',
            'scheduledtime' => 'privacy:metadata:local_taskflow_bulk_check:scheduledtime',
            'timecreated' => 'privacy:metadata:timecreated',
        ], 'privacy:metadata:local_taskflow_bulk_check');

        foreach (self::CONFIGTABLES as $table) {
            $collection->add_database_table($table, [
                'usermodified' => 'privacy:metadata:usermodified',
                'timemodified' => 'privacy:metadata:timemodified',
            ], 'privacy:metadata:' . $table);
        }

        $collection->add_subsystem_link('core_message', [], 'privacy:metadata:core_message');
        $collection->add_subsystem_link('core_competency', [], 'privacy:metadata:core_competency');

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid The user to search.
     * @return contextlist The contextlist containing the list of contexts used in this plugin.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        if (self::user_has_data($userid)) {
            $contextlist->add_system_context();
        }
        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist containing the list of users who have data in this context.
     */
    public static function get_users_in_context(userlist $userlist) {
        if (!$userlist->get_context() instanceof context_system) {
            return;
        }
        foreach (self::USERCOLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $userlist->add_from_sql($column, "SELECT $column FROM {{$table}} WHERE $column > 0", []);
            }
        }
    }

    /**
     * Export all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to export information for.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $context = self::get_system_context_from_list($contextlist->get_contexts());
        if (!$context) {
            return;
        }
        $userid = $contextlist->get_user()->id;
        $writer = writer::with_context($context);
        $base = [get_string('pluginname', 'local_taskflow')];

        // Assignments of the user, with everything attached to them.
        $assignments = $DB->get_records('local_taskflow_assignment', ['userid' => $userid], 'id ASC');
        foreach ($assignments as $assignment) {
            $rulename = $DB->get_field('local_taskflow_rules', 'rulename', ['id' => $assignment->ruleid]);
            $data = (object) [
                'rule' => $rulename ?: $assignment->ruleid,
                'unitid' => $assignment->unitid,
                'targets' => $assignment->targets,
                'status' => $assignment->status,
                'active' => transform::yesno($assignment->active),
                'duedate' => self::datetime($assignment->duedate),
                'assigneddate' => self::datetime($assignment->assigneddate),
                'periodstart' => self::datetime($assignment->periodstart),
                'completeddate' => self::datetime($assignment->completeddate),
                'overduecounter' => $assignment->overduecounter,
                'prolongedcounter' => $assignment->prolongedcounter,
                'timecreated' => self::datetime($assignment->timecreated),
                'timemodified' => self::datetime($assignment->timemodified),
                'history' => array_values(array_map(
                    fn($r) => self::export_history($r),
                    $DB->get_records('local_taskflow_history', ['assignmentid' => $assignment->id], 'timecreated ASC, id ASC')
                )),
                'requests' => array_values(array_map(
                    fn($r) => self::export_request($r),
                    $DB->get_records('local_taskflow_requests', ['assignmentid' => $assignment->id], 'timecreated ASC, id ASC')
                )),
                'internalmessages' => array_values(array_map(
                    fn($r) => self::export_internal_message($r),
                    $DB->get_records('local_taskflow_int_com', ['assignmentid' => $assignment->id], 'timecreated ASC, id ASC')
                )),
                'competencies' => array_values(array_map(
                    fn($r) => (object) [
                        'competencyid' => $r->competencyid,
                        'competencyevidenceid' => $r->competencyevidenceid,
                        'status' => $r->status,
                        'validationondate' => self::datetime($r->validationondate),
                        'timecreated' => self::datetime($r->timecreated),
                        'timemodified' => self::datetime($r->timemodified),
                    ],
                    $DB->get_records('local_taskflow_assgin_comp', ['assignmentid' => $assignment->id], 'id ASC')
                )),
                'lastseen' => self::datetime($DB->get_field(
                    'local_taskflow_last_seen',
                    'lastseen',
                    ['assignmentid' => $assignment->id, 'userid' => $userid]
                )),
            ];
            $writer->export_data(array_merge($base, [get_string('privacy:assignments', 'local_taskflow'), $assignment->id]), $data);
        }

        // Unit memberships, the units are either taskflow units or cohorts.
        $unittable = get_config('local_taskflow', 'organisational_unit_option') === 'cohort' ? 'cohort' : 'local_taskflow_units';
        $sql = "SELECT um.id, um.unitid, u.name, um.active, um.timeadded, um.timemodified
                  FROM {local_taskflow_unit_members} um
             LEFT JOIN {{$unittable}} u ON u.id = um.unitid
                 WHERE um.userid = :userid
              ORDER BY um.id ASC";
        $units = array_values(array_map(fn($r) => (object) [
            'unit' => $r->name ?? $r->unitid,
            'active' => transform::yesno($r->active),
            'timeadded' => self::datetime($r->timeadded),
            'timemodified' => self::datetime($r->timemodified),
        ], $DB->get_records_sql($sql, ['userid' => $userid])));
        if ($units) {
            $writer->export_data(array_merge($base, [get_string('privacy:units', 'local_taskflow')]), (object) ['units' => $units]);
        }

        // Rules which target the user directly.
        $rules = array_values(array_map(fn($r) => (object) [
            'rulename' => $r->rulename,
            'isactive' => transform::yesno($r->isactive),
        ], $DB->get_records('local_taskflow_rules', ['userid' => $userid], 'id ASC', 'id, rulename, isactive')));
        if ($rules) {
            $writer->export_data(array_merge($base, [get_string('privacy:rules', 'local_taskflow')]), (object) ['rules' => $rules]);
        }

        // Messages sent to the user and scheduled sends.
        $sql = "SELECT sm.id, sm.ruleid, m.name, sm.timesent
                  FROM {local_taskflow_sent_messages} sm
             LEFT JOIN {local_taskflow_messages} m ON m.id = sm.messageid
                 WHERE sm.userid = :userid
              ORDER BY sm.timesent ASC, sm.id ASC";
        $sent = array_values(array_map(fn($r) => (object) [
            'message' => $r->name,
            'ruleid' => $r->ruleid,
            'timesent' => self::datetime($r->timesent),
        ], $DB->get_records_sql($sql, ['userid' => $userid])));
        $sql = "SELECT bc.id, bc.ruleid, m.name, bc.status, bc.scheduledtime
                  FROM {local_taskflow_bulk_check} bc
             LEFT JOIN {local_taskflow_messages} m ON m.id = bc.messageid
                 WHERE bc.userid = :userid
              ORDER BY bc.scheduledtime ASC, bc.id ASC";
        $scheduled = array_values(array_map(fn($r) => (object) [
            'message' => $r->name,
            'ruleid' => $r->ruleid,
            'status' => $r->status,
            'scheduledtime' => self::datetime($r->scheduledtime),
        ], $DB->get_records_sql($sql, ['userid' => $userid])));
        if ($sent || $scheduled) {
            $writer->export_data(
                array_merge($base, [get_string('privacy:messages', 'local_taskflow')]),
                (object) ['sent' => $sent, 'scheduled' => $scheduled]
            );
        }

        // Actions the user performed on assignments of other users.
        $params = ['userid' => $userid, 'owner' => $userid];
        $sql = "SELECT h.*
                  FROM {local_taskflow_history} h
                 WHERE h.createdby = :userid AND h.userid <> :owner
              ORDER BY h.timecreated ASC, h.id ASC";
        $history = array_values(array_map(fn($r) => self::export_history($r), $DB->get_records_sql($sql, $params)));
        $sql = "SELECT c.*
                  FROM {local_taskflow_int_com} c
                  JOIN {local_taskflow_assignment} a ON a.id = c.assignmentid
                 WHERE c.usermodified = :userid AND a.userid <> :owner
              ORDER BY c.timecreated ASC, c.id ASC";
        $comments = array_values(array_map(fn($r) => self::export_internal_message($r), $DB->get_records_sql($sql, $params)));
        $sql = "SELECT r.*
                  FROM {local_taskflow_requests} r
                 WHERE r.usermodified = :userid AND r.userid <> :owner
              ORDER BY r.timecreated ASC, r.id ASC";
        $requests = array_values(array_map(fn($r) => self::export_request($r), $DB->get_records_sql($sql, $params)));
        if ($history || $comments || $requests) {
            $writer->export_data(
                array_merge($base, [get_string('privacy:actions', 'local_taskflow')]),
                (object) ['history' => $history, 'internalmessages' => $comments, 'requests' => $requests]
            );
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param context $context The specific context to delete data for.
     */
    public static function delete_data_for_all_users_in_context(context $context) {
        global $DB;

        if (!$context instanceof context_system) {
            return;
        }
        foreach (
            [
                'local_taskflow_history',
                'local_taskflow_assgin_comp',
                'local_taskflow_requests',
                'local_taskflow_int_com',
                'local_taskflow_last_seen',
                'local_taskflow_assignment',
                'local_taskflow_unit_members',
                'local_taskflow_sent_messages',
                'local_taskflow_bulk_check',
            ] as $table
        ) {
            $DB->delete_records($table);
        }
        $DB->set_field_select('local_taskflow_rules', 'isactive', 0, 'userid > 0');
        $DB->set_field_select('local_taskflow_rules', 'userid', 0, 'userid > 0');
        foreach (self::CONFIGTABLES as $table) {
            $DB->set_field_select($table, 'usermodified', 0, 'usermodified > 0');
        }
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts and user information to delete information for.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        if (!self::get_system_context_from_list($contextlist->get_contexts())) {
            return;
        }
        self::delete_data_for_userids([$contextlist->get_user()->id]);
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param approved_userlist $userlist The approved context and user information to delete information for.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        if (!$userlist->get_context() instanceof context_system) {
            return;
        }
        $userids = $userlist->get_userids();
        if ($userids) {
            self::delete_data_for_userids($userids);
        }
    }

    /**
     * Deletes the data of the given users and anonymises their actions on data of other users.
     *
     * @param array $userids
     * @return void
     */
    private static function delete_data_for_userids(array $userids): void {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        // Everything attached to the assignments of the users goes together with the assignments.
        $assignmentsql = "SELECT id FROM {local_taskflow_assignment} WHERE userid $insql";
        foreach (
            [
                'local_taskflow_history',
                'local_taskflow_assgin_comp',
                'local_taskflow_requests',
                'local_taskflow_int_com',
                'local_taskflow_last_seen',
            ] as $table
        ) {
            $DB->delete_records_select($table, "assignmentid IN ($assignmentsql)", $params);
        }
        $DB->delete_records_select('local_taskflow_assignment', "userid $insql", $params);

        foreach (
            [
                'local_taskflow_history',
                'local_taskflow_assgin_comp',
                'local_taskflow_requests',
                'local_taskflow_last_seen',
                'local_taskflow_unit_members',
                'local_taskflow_sent_messages',
                'local_taskflow_bulk_check',
            ] as $table
        ) {
            $DB->delete_records_select($table, "userid $insql", $params);
        }

        // Rules targeting the users are configuration of the site, they are unlinked and deactivated.
        $DB->set_field_select('local_taskflow_rules', 'isactive', 0, "userid $insql", $params);
        $DB->set_field_select('local_taskflow_rules', 'userid', 0, "userid $insql", $params);

        // Actions on data of other users are kept, only the reference to the actor is removed.
        $DB->set_field_select('local_taskflow_history', 'createdby', 0, "createdby $insql", $params);
        foreach (self::USERCOLUMNS as $table => $columns) {
            if (in_array('usermodified', $columns)) {
                $DB->set_field_select($table, 'usermodified', 0, "usermodified $insql", $params);
            }
        }
    }

    /**
     * Checks whether any column of the plugin points to the user.
     *
     * @param int $userid
     * @return bool
     */
    private static function user_has_data(int $userid): bool {
        global $DB;

        foreach (self::USERCOLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                if ($DB->record_exists($table, [$column => $userid])) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Returns the system context if it is part of the list.
     *
     * @param array $contexts
     * @return context_system|null
     */
    private static function get_system_context_from_list(array $contexts): ?context_system {
        foreach ($contexts as $context) {
            if ($context instanceof context_system) {
                return $context;
            }
        }
        return null;
    }

    /**
     * Exports a history record.
     *
     * @param \stdClass $record
     * @return \stdClass
     */
    private static function export_history(\stdClass $record): \stdClass {
        return (object) [
            'assignmentid' => $record->assignmentid,
            'type' => $record->type,
            'data' => $record->data,
            'annotation' => $record->annotation,
            'createdby' => $record->createdby,
            'timecreated' => self::datetime($record->timecreated),
        ];
    }

    /**
     * Exports a request record.
     *
     * @param \stdClass $record
     * @return \stdClass
     */
    private static function export_request(\stdClass $record): \stdClass {
        return (object) [
            'assignmentid' => $record->assignmentid,
            'request' => $record->request,
            'status' => $record->status,
            'treated' => transform::yesno($record->treated),
            'comment' => $record->comment,
            'json' => $record->json,
            'usermodified' => $record->usermodified,
            'timecreated' => self::datetime($record->timecreated),
            'timemodified' => self::datetime($record->timemodified),
        ];
    }

    /**
     * Exports an internal message record.
     *
     * @param \stdClass $record
     * @return \stdClass
     */
    private static function export_internal_message(\stdClass $record): \stdClass {
        return (object) [
            'assignmentid' => $record->assignmentid,
            'message' => $record->message,
            'author' => $record->usermodified,
            'timecreated' => self::datetime($record->timecreated),
            'timemodified' => self::datetime($record->timemodified),
        ];
    }

    /**
     * Transforms a timestamp, empty values stay empty.
     *
     * @param mixed $time
     * @return string|null
     */
    private static function datetime($time): ?string {
        return empty($time) ? null : transform::datetime($time);
    }
}
