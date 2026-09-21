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

namespace local_taskflow\local\rules;

use cache_helper;
use local_taskflow\local\assignment_process\assignment_preprocessor;
use local_taskflow\local\assignments\assignments_facade;
use stdClass;

/**
 * Assigns rules (curricula) to individual users and removes them again.
 *
 * A rule keeps its own audience (a unit, a single user, or nobody at all when it is a
 * curriculum that is only ever assigned individually). On top of that audience, any rule can be
 * handed to individual persons through the person page. These personal assignments are stored in
 * local_taskflow_rule_users and are honoured by the assignment preprocessor exactly like a unit
 * membership: the rule's filters still apply, and the assignment is created through the normal
 * assignment pipeline.
 *
 * @package local_taskflow
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class personal_rule_assignment_service {
    /**
     * Assigns a rule to one user and processes the resulting assignment immediately.
     *
     * @param int $ruleid
     * @param int $userid
     * @param int $actorid The user who assigns the rule.
     * @param string $annotation Optional note.
     * @return int The id of the local_taskflow_rule_users row.
     */
    public function assign(int $ruleid, int $userid, int $actorid, string $annotation = ''): int {
        global $DB;

        $now = time();
        $record = $DB->get_record('local_taskflow_rule_users', ['ruleid' => $ruleid, 'userid' => $userid]);
        if ($record) {
            $record->annotation = $annotation;
            $record->usermodified = $actorid;
            $record->timemodified = $now;
            $DB->update_record('local_taskflow_rule_users', $record);
            $id = (int)$record->id;
        } else {
            $id = $DB->insert_record('local_taskflow_rule_users', (object)[
                'ruleid' => $ruleid,
                'userid' => $userid,
                'annotation' => $annotation,
                'usermodified' => $actorid,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }

        $this->process_rule_for_user($ruleid, $userid);
        cache_helper::purge_by_event('changesinassignmentslist');
        return $id;
    }

    /**
     * Removes the personal assignment of a rule and drops the user out of the assignment.
     *
     * If the user still reaches the rule through a unit membership, the unit assignment stays
     * untouched because the preprocessor is run again for this user and rule.
     *
     * @param int $ruleid
     * @param int $userid
     * @return void
     */
    public function unassign(int $ruleid, int $userid): void {
        global $DB;

        $DB->delete_records('local_taskflow_rule_users', ['ruleid' => $ruleid, 'userid' => $userid]);

        if ($this->user_reaches_rule_through_unit($ruleid, $userid)) {
            $this->process_rule_for_user($ruleid, $userid);
        } else {
            assignments_facade::delete_assignments([$ruleid], $userid);
        }
        cache_helper::purge_by_event('changesinassignmentslist');
    }

    /**
     * Ids of the rules that were assigned to the user individually.
     *
     * @param int $userid
     * @return int[]
     */
    public static function get_rule_ids_for_user(int $userid): array {
        global $DB;
        return array_map('intval', $DB->get_fieldset_select(
            'local_taskflow_rule_users',
            'ruleid',
            'userid = :userid',
            ['userid' => $userid]
        ));
    }

    /**
     * Ids of the users a rule was assigned to individually.
     *
     * @param int $ruleid
     * @return int[]
     */
    public static function get_user_ids_for_rule(int $ruleid): array {
        global $DB;
        return array_map('intval', $DB->get_fieldset_select(
            'local_taskflow_rule_users',
            'userid',
            'ruleid = :ruleid',
            ['ruleid' => $ruleid]
        ));
    }

    /**
     * Ids of the users that a set of rules were assigned to individually, without duplicates.
     *
     * @param int[] $ruleids
     * @return int[]
     */
    public static function get_user_ids_for_rules(array $ruleids): array {
        global $DB;
        $ruleids = array_filter(array_map('intval', $ruleids));
        if (empty($ruleids)) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($ruleids, SQL_PARAMS_NAMED, 'ruleid');
        return array_map('intval', $DB->get_fieldset_select(
            'local_taskflow_rule_users',
            'DISTINCT userid',
            "ruleid $insql",
            $params
        ));
    }

    /**
     * The personal rule rows of a user including the rule name.
     *
     * @param int $userid
     * @return stdClass[] keyed by rule id
     */
    public static function get_personal_rules_of_user(int $userid): array {
        global $DB;
        $sql = "SELECT r.id, r.rulename, r.isactive, ru.annotation, ru.timecreated, ru.usermodified
                  FROM {local_taskflow_rule_users} ru
                  JOIN {local_taskflow_rules} r ON r.id = ru.ruleid
                 WHERE ru.userid = :userid
              ORDER BY r.rulename";
        return $DB->get_records_sql($sql, ['userid' => $userid]);
    }

    /**
     * Runs the assignment pipeline for exactly this user and rule.
     *
     * @param int $ruleid
     * @param int $userid
     * @return void
     */
    private function process_rule_for_user(int $ruleid, int $userid): void {
        global $DB;
        $rule = $DB->get_record('local_taskflow_rules', ['id' => $ruleid], '*', MUST_EXIST);
        rules::reset_instances();
        $preprocessor = new assignment_preprocessor([
            'id' => (int)$rule->id,
            'unitid' => (int)$rule->unitid,
            'userid' => (int)$rule->userid,
            'rulejson' => $rule->rulejson,
        ]);
        $preprocessor->set_this_user($userid);
        $preprocessor->set_this_rules();
        $preprocessor->process_assignemnts();
    }

    /**
     * Whether the user is still addressed by the rule through its own audience.
     *
     * @param int $ruleid
     * @param int $userid
     * @return bool
     */
    private function user_reaches_rule_through_unit(int $ruleid, int $userid): bool {
        global $DB;
        $rule = $DB->get_record('local_taskflow_rules', ['id' => $ruleid], '*', MUST_EXIST);
        if ((int)$rule->userid === $userid) {
            return true;
        }
        if (empty($rule->unitid)) {
            return false;
        }
        $preprocessor = new assignment_preprocessor([
            'id' => (int)$rule->id,
            'unitid' => (int)$rule->unitid,
            'userid' => (int)$rule->userid,
            'rulejson' => $rule->rulejson,
        ]);
        $preprocessor->set_affected_users();
        return in_array($userid, array_map('intval', $preprocessor->get_affected_users()), true);
    }
}
