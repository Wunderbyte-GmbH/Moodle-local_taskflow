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
use local_taskflow\local\units\unit_hierarchy;
use stdClass;

/**
 * Assigns rules to organisational units (cohorts) from the organisation chart and removes them again.
 *
 * Today a rule carries exactly one unit (local_taskflow_rules.unitid). Every method of this service
 * already speaks in terms of "the units of a rule" so that the storage can later move to a
 * rule <-> unit relation table without touching the callers (organisation page, person page, tests).
 *
 * @package local_taskflow
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class unit_rule_assignment_service {
    /**
     * Assigns a rule to a unit and creates the assignments of the members immediately.
     *
     * @param int $ruleid
     * @param int $unitid
     * @param bool $inheritance Also apply the rule to the members of all child units.
     * @param int $actorid
     * @return void
     */
    public function assign(int $ruleid, int $unitid, bool $inheritance, int $actorid): void {
        global $DB;

        $rule = $DB->get_record('local_taskflow_rules', ['id' => $ruleid], '*', MUST_EXIST);
        $previousunits = self::get_unit_ids_for_rule($ruleid);

        $rulejson = json_decode($rule->rulejson);
        $rulejson->rulejson->rule->inheritance = $inheritance ? 1 : 0;
        $rulejson->rulejson->rule->unitid = $unitid;
        $rulejson->rulejson->rule->userid = 0;

        $service = new rule_persistence_service();
        $service->persist([
            'id' => (int)$rule->id,
            'unitid' => $unitid,
            'userid' => 0,
            'rulename' => $rule->rulename,
            'isactive' => (int)$rule->isactive,
            'rulejson' => json_encode($rulejson),
        ], $actorid);

        // Members of a unit the rule no longer addresses drop out (unless they hold the rule personally).
        foreach (array_diff($previousunits, [$unitid]) as $oldunitid) {
            $this->drop_unit_members($ruleid, $oldunitid, (bool)($rulejson->rulejson->rule->inheritance ?? false));
        }

        $this->process_rule_for_units($ruleid);
        cache_helper::purge_by_event('changesinassignmentslist');
    }

    /**
     * Removes a rule from a unit; the members' assignments drop out unless they hold the rule personally.
     *
     * @param int $ruleid
     * @param int $unitid
     * @param int $actorid
     * @return void
     */
    public function unassign(int $ruleid, int $unitid, int $actorid): void {
        global $DB;

        $rule = $DB->get_record('local_taskflow_rules', ['id' => $ruleid], '*', MUST_EXIST);
        if (!in_array($unitid, self::get_unit_ids_for_rule($ruleid), true)) {
            return;
        }
        $rulejson = json_decode($rule->rulejson);
        $inheritance = !empty($rulejson->rulejson->rule->inheritance);

        $this->drop_unit_members($ruleid, $unitid, $inheritance);

        $rulejson->rulejson->rule->unitid = 0;
        $service = new rule_persistence_service();
        $service->persist([
            'id' => (int)$rule->id,
            'unitid' => 0,
            'userid' => (int)$rule->userid,
            'rulename' => $rule->rulename,
            'isactive' => (int)$rule->isactive,
            'rulejson' => json_encode($rulejson),
        ], $actorid);
        cache_helper::purge_by_event('changesinassignmentslist');
    }

    /**
     * The units a rule addresses directly (today at most one).
     *
     * @param int $ruleid
     * @return int[]
     */
    public static function get_unit_ids_for_rule(int $ruleid): array {
        global $DB;
        $unitid = (int)$DB->get_field('local_taskflow_rules', 'unitid', ['id' => $ruleid]);
        return $unitid > 0 ? [$unitid] : [];
    }

    /**
     * Whether a rule inherits to the child units of its units.
     *
     * @param stdClass $rule A local_taskflow_rules record.
     * @return bool
     */
    public static function rule_has_inheritance(stdClass $rule): bool {
        $rulejson = json_decode($rule->rulejson ?? '');
        return !empty($rulejson->rulejson->rule->inheritance);
    }

    /**
     * Rules assigned directly to a unit.
     *
     * @param int $unitid
     * @return stdClass[] rule records keyed by id, with the extra property "inheritance"
     */
    public static function get_rules_for_unit(int $unitid): array {
        global $DB;
        $rules = $DB->get_records('local_taskflow_rules', ['unitid' => $unitid], 'rulename');
        foreach ($rules as $rule) {
            $rule->inheritance = self::rule_has_inheritance($rule);
        }
        return $rules;
    }

    /**
     * Rules a unit inherits from its ancestors (rules with inheritance on a parent unit).
     *
     * @param int $unitid
     * @return stdClass[] rule records keyed by id, with the extra property "inheritedfrom" (unit id)
     */
    public static function get_inherited_rules_for_unit(int $unitid): array {
        $tree = new unit_hierarchy();
        $inherited = [];
        foreach ($tree->get_all_parents($unitid) as $parentid) {
            if ((int)$parentid === $unitid || empty($parentid)) {
                continue;
            }
            foreach (self::get_rules_for_unit((int)$parentid) as $rule) {
                if ($rule->inheritance) {
                    $rule->inheritedfrom = (int)$parentid;
                    $inherited[$rule->id] = $rule;
                }
            }
        }
        return $inherited;
    }

    /**
     * Rules that can still be assigned to a unit: active rules that address no unit yet.
     *
     * With one unit per rule, a rule bound elsewhere would have to be moved; those are left out on purpose.
     *
     * @return stdClass[] keyed by rule id
     */
    public static function get_assignable_rules(): array {
        global $DB;
        return $DB->get_records('local_taskflow_rules', ['unitid' => 0, 'isactive' => 1], 'rulename');
    }

    /**
     * Runs the assignment pipeline for the rule and all units it addresses.
     *
     * @param int $ruleid
     * @return void
     */
    private function process_rule_for_units(int $ruleid): void {
        global $DB;
        $rule = $DB->get_record('local_taskflow_rules', ['id' => $ruleid], '*', MUST_EXIST);
        rules::reset_instances();
        unit_rules::reset_instances();
        $preprocessor = new assignment_preprocessor((array)$rule);
        $preprocessor->set_affected_users();
        $preprocessor->set_this_rules();
        $preprocessor->process_assignemnts();
    }

    /**
     * Drops the members of a unit (and of its child units when inheriting) out of a rule's assignments.
     *
     * @param int $ruleid
     * @param int $unitid
     * @param bool $inheritance
     * @return void
     */
    private function drop_unit_members(int $ruleid, int $unitid, bool $inheritance): void {
        global $DB;
        $rule = $DB->get_record('local_taskflow_rules', ['id' => $ruleid], '*', MUST_EXIST);
        $data = (array)$rule;
        $data['unitid'] = $unitid;
        $preprocessor = new assignment_preprocessor($data);
        if ($inheritance) {
            $preprocessor->set_all_inheritance_affected_users();
        } else {
            $preprocessor->set_all_affected_users();
        }
        $personal = personal_rule_assignment_service::get_user_ids_for_rule($ruleid);
        foreach ($preprocessor->get_affected_users() as $userid) {
            if (in_array((int)$userid, $personal, true)) {
                continue;
            }
            assignments_facade::delete_assignments([$ruleid], (int)$userid);
        }
    }
}
