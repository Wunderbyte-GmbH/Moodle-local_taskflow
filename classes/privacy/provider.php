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

namespace local_taskflow\privacy;

use cache_helper;
use context;
use context_system;
use context_user;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_taskflow\local\dashboard\person_tabs;

/**
 * Privacy provider of local_taskflow.
 *
 * Contexts: everything Taskflow stores *about* a person (assignments with their history, chat, requests and
 * evidence, messages sent, unit memberships, individually assigned rules, notes) belongs to the user context of
 * that person, so it follows the lifecycle of the person's account. Where a user only *acted* on somebody else's
 * record (last modifier, author of a chat message or note, the person who treated a request or assigned a rule),
 * the reference lives in the system context, like the rest of Taskflow, which works in the system context only.
 *
 * Deletion removes the data about a person. References where the person only acted are anonymised (set to 0), so
 * the records of other people and their audit trail stay intact.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\user_preference_provider {
    /** @var string[] Tables with data about a person: table => column of that person. */
    private const SUBJECT_TABLES = [
        'local_taskflow_assignment' => 'userid',
        'local_taskflow_history' => 'userid',
        'local_taskflow_requests' => 'userid',
        'local_taskflow_last_seen' => 'userid',
        'local_taskflow_sent_messages' => 'userid',
        'local_taskflow_assgin_comp' => 'userid',
        'local_taskflow_unit_members' => 'userid',
        'local_taskflow_rule_users' => 'userid',
        'local_taskflow_rules' => 'userid',
        'local_taskflow_person_notes' => 'userid',
    ];

    /** @var string[] Tables that reference an acting user: table => column of that user. */
    private const ACTOR_COLUMNS = [
        'local_taskflow_units' => 'usermodified',
        'local_taskflow_unit_rel' => 'usermodified',
        'local_taskflow_unit_members' => 'usermodified',
        'local_taskflow_assignment' => 'usermodified',
        'local_taskflow_messages' => 'usermodified',
        'local_taskflow_history' => 'createdby',
        'local_taskflow_requests' => 'usermodified',
        'local_taskflow_int_com' => 'usermodified',
        'local_taskflow_rule_users' => 'usermodified',
        'local_taskflow_person_notes' => 'usermodified',
    ];

    /** @var string[] Keys inside stored JSON (history data, rule definitions) that hold a user id. */
    private const JSON_USER_KEYS = ['userid', 'usermodified', 'createdby', 'releateduserid'];

    /**
     * Describes the personal data Taskflow stores.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $f = 'privacy:metadata:field:';
        $collection->add_database_table('local_taskflow_assignment', [
            'userid' => $f . 'userid',
            'ruleid' => $f . 'ruleid',
            'status' => $f . 'status',
            'duedate' => 'privacy:metadata:local_taskflow_assignment:dates',
            'usermodified' => $f . 'usermodified',
            'timemodified' => $f . 'timemodified',
        ], 'privacy:metadata:local_taskflow_assignment');
        $collection->add_database_table('local_taskflow_history', [
            'assignmentid' => $f . 'assignmentid',
            'userid' => $f . 'userid',
            'data' => $f . 'data',
            'createdby' => $f . 'createdby',
            'annotation' => $f . 'annotation',
            'timecreated' => $f . 'timecreated',
        ], 'privacy:metadata:local_taskflow_history');
        $collection->add_database_table('local_taskflow_requests', [
            'userid' => $f . 'userid',
            'assignmentid' => $f . 'assignmentid',
            'status' => $f . 'status',
            'comment' => 'privacy:metadata:local_taskflow_requests:comment',
            'usermodified' => 'privacy:metadata:local_taskflow_requests:usermodified',
            'timemodified' => $f . 'timemodified',
        ], 'privacy:metadata:local_taskflow_requests');
        $collection->add_database_table('local_taskflow_int_com', [
            'assignmentid' => $f . 'assignmentid',
            'message' => $f . 'message',
            'usermodified' => $f . 'usermodified',
            'timecreated' => $f . 'timecreated',
        ], 'privacy:metadata:local_taskflow_int_com');
        $collection->add_database_table('local_taskflow_last_seen', [
            'userid' => $f . 'userid',
            'assignmentid' => $f . 'assignmentid',
            'lastseen' => 'privacy:metadata:local_taskflow_last_seen:lastseen',
        ], 'privacy:metadata:local_taskflow_last_seen');
        $collection->add_database_table('local_taskflow_sent_messages', [
            'userid' => $f . 'userid',
            'ruleid' => $f . 'ruleid',
            'timesent' => 'privacy:metadata:local_taskflow_sent_messages:timesent',
        ], 'privacy:metadata:local_taskflow_sent_messages');
        $collection->add_database_table('local_taskflow_assgin_comp', [
            'userid' => $f . 'userid',
            'assignmentid' => $f . 'assignmentid',
            'status' => $f . 'status',
            'timemodified' => $f . 'timemodified',
        ], 'privacy:metadata:local_taskflow_assgin_comp');
        $collection->add_database_table('local_taskflow_unit_members', [
            'userid' => $f . 'userid',
            'active' => $f . 'active',
            'usermodified' => $f . 'usermodified',
            'timemodified' => $f . 'timemodified',
        ], 'privacy:metadata:local_taskflow_unit_members');
        $collection->add_database_table('local_taskflow_rule_users', [
            'ruleid' => $f . 'ruleid',
            'userid' => $f . 'userid',
            'annotation' => $f . 'annotation',
            'usermodified' => 'privacy:metadata:local_taskflow_rule_users:usermodified',
            'timecreated' => $f . 'timecreated',
        ], 'privacy:metadata:local_taskflow_rule_users');
        $collection->add_database_table('local_taskflow_rules', [
            'userid' => $f . 'userid',
            'rulename' => $f . 'name',
        ], 'privacy:metadata:local_taskflow_rules');
        $collection->add_database_table('local_taskflow_person_notes', [
            'userid' => $f . 'userid',
            'note' => 'privacy:metadata:local_taskflow_person_notes:note',
            'usermodified' => 'privacy:metadata:local_taskflow_person_notes:usermodified',
            'timecreated' => $f . 'timecreated',
        ], 'privacy:metadata:local_taskflow_person_notes');
        foreach (['local_taskflow_units', 'local_taskflow_unit_rel', 'local_taskflow_messages'] as $table) {
            $collection->add_database_table($table, [
                'usermodified' => $f . 'usermodified',
                'timemodified' => $f . 'timemodified',
            ], 'privacy:metadata:' . $table);
        }
        $collection->add_user_preference(person_tabs::PREFERENCE, 'privacy:metadata:preference:persontabs');
        $collection->add_subsystem_link('core_message', [], 'privacy:metadata:core_message');
        $collection->add_subsystem_link('core_competency', [], 'privacy:metadata:core_competency');
        $collection->add_subsystem_link('core_cohort', [], 'privacy:metadata:core_cohort');
        $collection->add_subsystem_link('core_user', [], 'privacy:metadata:core_user');
        $collection->add_plugintype_link('taskflowadapter', [], 'privacy:metadata:taskflowadapter');
        return $collection;
    }

    /**
     * Contexts with data of a user: their user context (data about them), the system context (their actions).
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        if (self::has_subject_data($userid)) {
            $contextlist->add_user_context($userid);
        }
        if (self::has_actor_data($userid)) {
            $contextlist->add_system_context();
        }
        return $contextlist;
    }

    /**
     * Users with data in a context.
     *
     * @param userlist $userlist
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context instanceof context_user) {
            if (self::has_subject_data((int)$context->instanceid)) {
                $userlist->add_user((int)$context->instanceid);
            }
            return;
        }
        if ($context instanceof context_system) {
            foreach (self::ACTOR_COLUMNS as $table => $column) {
                $userlist->add_from_sql($column, "SELECT {$column} FROM {{$table}} WHERE {$column} > 0", []);
            }
        }
    }

    /**
     * Exports the data of a user.
     *
     * @param approved_contextlist $contextlist
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof context_user && (int)$context->instanceid === $userid) {
                self::export_subject_data($userid, $context);
            } else if ($context instanceof context_system) {
                self::export_actor_data($userid, $context);
            }
        }
    }

    /**
     * Exports the open person tabs of the dashboard.
     *
     * @param int $userid
     * @return void
     */
    public static function export_user_preferences(int $userid): void {
        $value = get_user_preferences(person_tabs::PREFERENCE, null, $userid);
        if ($value !== null) {
            writer::export_user_preference(
                'local_taskflow',
                person_tabs::PREFERENCE,
                $value,
                get_string('privacy:metadata:preference:persontabs', 'local_taskflow')
            );
        }
    }

    /**
     * Deletes the data of all users in a context.
     *
     * @param context $context
     * @return void
     */
    public static function delete_data_for_all_users_in_context(context $context): void {
        if ($context instanceof context_user) {
            self::delete_subject_data((int)$context->instanceid);
        } else if ($context instanceof context_system) {
            self::anonymise_actor(null);
        }
    }

    /**
     * Deletes the data of one user in the approved contexts.
     *
     * @param approved_contextlist $contextlist
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof context_user && (int)$context->instanceid === $userid) {
                self::delete_subject_data($userid);
            } else if ($context instanceof context_system) {
                self::anonymise_actor($userid);
            }
        }
    }

    /**
     * Deletes the data of several users in one context.
     *
     * @param approved_userlist $userlist
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        $context = $userlist->get_context();
        $userids = array_map('intval', $userlist->get_userids());
        if ($context instanceof context_user) {
            if (in_array((int)$context->instanceid, $userids, true)) {
                self::delete_subject_data((int)$context->instanceid);
            }
        } else if ($context instanceof context_system) {
            foreach ($userids as $userid) {
                self::anonymise_actor($userid);
            }
        }
    }

    /**
     * Whether Taskflow stores data about the user.
     *
     * @param int $userid
     * @return bool
     */
    private static function has_subject_data(int $userid): bool {
        global $DB;
        if ($userid <= 0) {
            return false;
        }
        foreach (self::SUBJECT_TABLES as $table => $column) {
            if ($DB->record_exists($table, [$column => $userid])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether Taskflow references the user as the one who acted.
     *
     * @param int $userid
     * @return bool
     */
    private static function has_actor_data(int $userid): bool {
        global $DB;
        if ($userid <= 0) {
            return false;
        }
        foreach (self::ACTOR_COLUMNS as $table => $column) {
            if ($DB->record_exists($table, [$column => $userid])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Exports everything Taskflow stores about the user into their user context.
     *
     * @param int $userid
     * @param context $context
     * @return void
     */
    private static function export_subject_data(int $userid, context $context): void {
        global $DB;
        $params = ['userid' => $userid];
        $groups = [];

        $groups['assignments'] = array_map(fn($a) => [
            'rule' => $a->rulename,
            'status' => (int)$a->status,
            'active' => transform::yesno(!empty($a->active)),
            'assigneddate' => self::date($a->assigneddate),
            'duedate' => self::date($a->duedate),
            'completeddate' => self::date($a->completeddate),
            'overduecounter' => (int)$a->overduecounter,
            'prolongedcounter' => (int)$a->prolongedcounter,
            'targets' => json_decode((string)$a->targets, true),
            'timecreated' => self::date($a->timecreated),
            'timemodified' => self::date($a->timemodified),
        ], array_values($DB->get_records_sql(
            "SELECT a.*, r.rulename
               FROM {local_taskflow_assignment} a
          LEFT JOIN {local_taskflow_rules} r ON r.id = a.ruleid
              WHERE a.userid = :userid
           ORDER BY a.id",
            $params
        )));

        $groups['history'] = array_map(fn($h) => [
            'assignment' => (int)$h->assignmentid,
            'rule' => $h->rulename,
            'type' => $h->type,
            'data' => json_decode((string)$h->data, true),
            'annotation' => $h->annotation,
            'causedbyyou' => transform::yesno((int)$h->createdby === $userid),
            'timecreated' => self::date($h->timecreated),
        ], array_values($DB->get_records_sql(
            "SELECT h.*, r.rulename
               FROM {local_taskflow_history} h
          LEFT JOIN {local_taskflow_assignment} a ON a.id = h.assignmentid
          LEFT JOIN {local_taskflow_rules} r ON r.id = a.ruleid
              WHERE h.userid = :userid
           ORDER BY h.timecreated, h.id",
            $params
        )));

        $groups['requests'] = array_map(fn($q) => [
            'assignment' => (int)$q->assignmentid,
            'rule' => $q->rulename,
            'type' => (int)$q->request,
            'status' => (int)$q->status,
            'treated' => (int)$q->treated,
            'comment' => $q->comment,
            'timecreated' => self::date($q->timecreated),
            'timemodified' => self::date($q->timemodified),
        ], array_values($DB->get_records_sql(
            "SELECT q.*, r.rulename
               FROM {local_taskflow_requests} q
          LEFT JOIN {local_taskflow_assignment} a ON a.id = q.assignmentid
          LEFT JOIN {local_taskflow_rules} r ON r.id = a.ruleid
              WHERE q.userid = :userid
           ORDER BY q.id",
            $params
        )));

        $groups['chat'] = array_map(fn($m) => [
            'assignment' => (int)$m->assignmentid,
            'rule' => $m->rulename,
            'message' => $m->message,
            'writtenbyyou' => transform::yesno((int)$m->usermodified === $userid),
            'timecreated' => self::date($m->timecreated),
        ], array_values($DB->get_records_sql(
            "SELECT m.*, r.rulename
               FROM {local_taskflow_int_com} m
               JOIN {local_taskflow_assignment} a ON a.id = m.assignmentid
          LEFT JOIN {local_taskflow_rules} r ON r.id = a.ruleid
              WHERE a.userid = :userid
           ORDER BY m.timecreated, m.id",
            $params
        )));

        $groups['lastseen'] = array_map(fn($s) => [
            'assignment' => (int)$s->assignmentid,
            'lastseen' => self::date($s->lastseen),
        ], array_values($DB->get_records('local_taskflow_last_seen', $params, 'id')));

        $groups['sentmessages'] = array_map(fn($s) => [
            'message' => $s->name,
            'rule' => $s->rulename,
            'timesent' => self::date($s->timesent),
        ], array_values($DB->get_records_sql(
            "SELECT s.*, m.name, r.rulename
               FROM {local_taskflow_sent_messages} s
          LEFT JOIN {local_taskflow_messages} m ON m.id = s.messageid
          LEFT JOIN {local_taskflow_rules} r ON r.id = s.ruleid
              WHERE s.userid = :userid
           ORDER BY s.timesent, s.id",
            $params
        )));

        $groups['competencies'] = array_map(fn($c) => [
            'assignment' => (int)$c->assignmentid,
            'competency' => (int)$c->competencyid,
            'evidence' => (int)$c->competencyevidenceid,
            'status' => $c->status,
            'validationondate' => self::date($c->validationondate),
            'timecreated' => self::date($c->timecreated),
        ], array_values($DB->get_records('local_taskflow_assgin_comp', $params, 'id')));

        $groups['units'] = array_map(fn($u) => [
            'unit' => $u->name ?? (int)$u->unitid,
            'active' => transform::yesno(!empty($u->active)),
            'timeadded' => self::date($u->timeadded),
        ], array_values($DB->get_records_sql(
            "SELECT m.*, u.name
               FROM {local_taskflow_unit_members} m
          LEFT JOIN {local_taskflow_units} u ON u.id = m.unitid
              WHERE m.userid = :userid
           ORDER BY m.id",
            $params
        )));

        $groups['personalrules'] = array_map(fn($p) => [
            'rule' => $p->rulename,
            'annotation' => $p->annotation,
            'timecreated' => self::date($p->timecreated),
        ], array_values($DB->get_records_sql(
            "SELECT p.*, r.rulename
               FROM {local_taskflow_rule_users} p
          LEFT JOIN {local_taskflow_rules} r ON r.id = p.ruleid
              WHERE p.userid = :userid
           ORDER BY p.id",
            $params
        )));

        $groups['rulesforuser'] = array_map(fn($r) => [
            'rule' => $r->rulename,
            'active' => transform::yesno(!empty($r->isactive)),
        ], array_values($DB->get_records('local_taskflow_rules', $params, 'id', 'id, rulename, isactive')));

        $groups['notes'] = array_map(fn($n) => [
            'note' => $n->note,
            'timecreated' => self::date($n->timecreated),
        ], array_values($DB->get_records('local_taskflow_person_notes', $params, 'timecreated, id')));

        foreach ($groups as $name => $rows) {
            if (!empty($rows)) {
                writer::with_context($context)->export_data(
                    [get_string('privacy:export:' . $name, 'local_taskflow')],
                    (object)[$name => $rows]
                );
            }
        }
    }

    /**
     * Exports what the user did on records of other people into the system context.
     *
     * @param int $userid
     * @param context $context
     * @return void
     */
    private static function export_actor_data(int $userid, context $context): void {
        global $DB;
        $params = ['userid' => $userid];
        $actions = [];

        $actions['assignments'] = array_values(array_map(fn($a) => [
            'assignment' => (int)$a->id,
            'rule' => $a->rulename,
            'timemodified' => self::date($a->timemodified),
        ], $DB->get_records_sql(
            "SELECT a.id, a.timemodified, r.rulename
               FROM {local_taskflow_assignment} a
          LEFT JOIN {local_taskflow_rules} r ON r.id = a.ruleid
              WHERE a.usermodified = :userid
           ORDER BY a.id",
            $params
        )));
        $actions['history'] = array_values(array_map(fn($h) => [
            'assignment' => (int)$h->assignmentid,
            'type' => $h->type,
            'annotation' => $h->annotation,
            'timecreated' => self::date($h->timecreated),
        ], $DB->get_records('local_taskflow_history', ['createdby' => $userid], 'timecreated, id')));
        $actions['requests'] = array_values(array_map(fn($q) => [
            'request' => (int)$q->id,
            'assignment' => (int)$q->assignmentid,
            'status' => (int)$q->status,
            'timemodified' => self::date($q->timemodified),
        ], $DB->get_records('local_taskflow_requests', ['usermodified' => $userid], 'id')));
        $actions['chat'] = array_values(array_map(fn($m) => [
            'assignment' => (int)$m->assignmentid,
            'message' => $m->message,
            'timecreated' => self::date($m->timecreated),
        ], $DB->get_records('local_taskflow_int_com', ['usermodified' => $userid], 'timecreated, id')));
        $actions['personalrules'] = array_values(array_map(fn($p) => [
            'rule' => $p->rulename,
            'annotation' => $p->annotation,
            'timecreated' => self::date($p->timecreated),
        ], $DB->get_records_sql(
            "SELECT p.*, r.rulename
               FROM {local_taskflow_rule_users} p
          LEFT JOIN {local_taskflow_rules} r ON r.id = p.ruleid
              WHERE p.usermodified = :userid
           ORDER BY p.id",
            $params
        )));
        $actions['notes'] = array_values(array_map(fn($n) => [
            'note' => $n->note,
            'timecreated' => self::date($n->timecreated),
        ], $DB->get_records('local_taskflow_person_notes', ['usermodified' => $userid], 'timecreated, id')));
        $actions['units'] = array_values(array_map(fn($u) => [
            'unit' => $u->name,
            'timemodified' => self::date($u->timemodified),
        ], $DB->get_records('local_taskflow_units', ['usermodified' => $userid], 'id')));
        $actions['messages'] = array_values(array_map(fn($m) => [
            'template' => $m->name,
            'timemodified' => self::date($m->timemodified),
        ], $DB->get_records('local_taskflow_messages', ['usermodified' => $userid], 'id')));
        $actions['unitrelations'] = $DB->count_records('local_taskflow_unit_rel', ['usermodified' => $userid]);
        $actions['unitmemberships'] = $DB->count_records('local_taskflow_unit_members', ['usermodified' => $userid]);

        $actions = array_filter($actions);
        if (!empty($actions)) {
            writer::with_context($context)->export_data(
                [get_string('privacy:export:actions', 'local_taskflow')],
                (object)$actions
            );
        }
    }

    /**
     * Deletes everything Taskflow stores about a person.
     *
     * @param int $userid
     * @return void
     */
    private static function delete_subject_data(int $userid): void {
        global $DB;
        $assignmentids = $DB->get_fieldset_select('local_taskflow_assignment', 'id', 'userid = ?', [$userid]);
        if (!empty($assignmentids)) {
            [$insql, $inparams] = $DB->get_in_or_equal($assignmentids);
            $bytable = [
                'local_taskflow_int_com',
                'local_taskflow_last_seen',
                'local_taskflow_history',
                'local_taskflow_requests',
                'local_taskflow_assgin_comp',
            ];
            foreach ($bytable as $table) {
                $DB->delete_records_select($table, "assignmentid {$insql}", $inparams);
            }
            $DB->delete_records_select('local_taskflow_assignment', "id {$insql}", $inparams);
        }
        foreach (self::SUBJECT_TABLES as $table => $column) {
            if ($table === 'local_taskflow_rules' || $table === 'local_taskflow_assignment') {
                continue;
            }
            $DB->delete_records($table, [$column => $userid]);
        }
        // A rule written for this one person keeps its definition but no longer points to them.
        foreach ($DB->get_records('local_taskflow_rules', ['userid' => $userid]) as $rule) {
            $rule->userid = 0;
            $rule->rulejson = self::anonymise_json($rule->rulejson, $userid);
            $DB->update_record('local_taskflow_rules', $rule);
        }
        unset_user_preference(person_tabs::PREFERENCE, $userid);
        self::purge_caches();
    }

    /**
     * Anonymises the references to a user who only acted on records, or to every acting user when $userid is null.
     *
     * @param int|null $userid
     * @return void
     */
    private static function anonymise_actor(?int $userid): void {
        global $DB;
        foreach (self::ACTOR_COLUMNS as $table => $column) {
            $select = $userid === null ? "{$column} > 0" : "{$column} = :userid";
            $params = $userid === null ? [] : ['userid' => $userid];
            if ($table === 'local_taskflow_history') {
                // The history also keeps the acting user inside its details.
                $rs = $DB->get_recordset_select($table, $select, $params, '', 'id, data');
                foreach ($rs as $entry) {
                    $DB->update_record($table, (object)[
                        'id' => $entry->id,
                        'createdby' => 0,
                        'data' => self::anonymise_json($entry->data, $userid),
                    ]);
                }
                $rs->close();
                continue;
            }
            $DB->set_field_select($table, $column, 0, $select, $params);
        }
        self::purge_caches();
    }

    /**
     * Replaces user ids in stored JSON: values of the user keys that equal $userid, or all of them when null.
     *
     * @param string|null $json
     * @param int|null $userid
     * @return string|null
     */
    private static function anonymise_json(?string $json, ?int $userid): ?string {
        if ($json === null || $json === '') {
            return $json;
        }
        $decoded = json_decode($json);
        if ($decoded === null) {
            return $json;
        }
        $walk = function ($value) use (&$walk, $userid) {
            if (is_array($value) || is_object($value)) {
                foreach ($value as $key => $item) {
                    $isuserkey = is_string($key) && in_array($key, self::JSON_USER_KEYS, true);
                    if ($isuserkey && is_numeric($item) && ($userid === null || (int)$item === $userid)) {
                        $item = 0;
                    } else {
                        $item = $walk($item);
                    }
                    if (is_array($value)) {
                        $value[$key] = $item;
                    } else {
                        $value->$key = $item;
                    }
                }
            }
            return $value;
        };
        return json_encode($walk($decoded));
    }

    /**
     * Human-readable date, or null when not set.
     *
     * @param mixed $time
     * @return string|null
     */
    private static function date($time): ?string {
        return empty($time) ? null : transform::datetime((int)$time);
    }

    /**
     * Drops the cached lists that may still show deleted or anonymised data.
     *
     * @return void
     */
    private static function purge_caches(): void {
        cache_helper::purge_by_event('changesinassignmentslist');
        cache_helper::purge_by_event('changesinhistorylist');
        cache_helper::purge_by_event('changesinrequestslist');
    }
}
