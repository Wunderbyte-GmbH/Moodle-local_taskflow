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

namespace local_taskflow\output;

use local_taskflow\local\rules\personal_rule_assignment_service;
use local_taskflow\local\rules\unit_rule_assignment_service;
use local_taskflow\local\units\unit_relations;
use local_taskflow\table\unit_members_table;
use local_taskflow\taskflow_stringmanager;
use moodle_url;
use renderable;
use renderer_base;
use stdClass;
use templatable;

/**
 * The organisation page: the unit tree (organisation chart) with members, rules per unit,
 * inherited rules and the option to assign rules to a unit.
 *
 * @package local_taskflow
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class unitspage implements adapter_view_interface, renderable, templatable {
    /** @var int Up to this many members are listed inline; larger units get a lazily loaded, searchable table. */
    private const MEMBERS_INLINE = 10;

    /** @var bool */
    private bool $canassign;

    /** @var int Unit to highlight. */
    private int $focusunitid;

    /** @var moodle_url */
    private moodle_url $pageurl;

    /** @var array unitid => name */
    private array $unitnames = [];

    /** @var array unitid => member count */
    private array $membercounts = [];

    /** @var array unitid => [member records] */
    private array $members = [];

    /**
     * Constructor.
     *
     * @param bool $canassign
     * @param int $focusunitid
     * @param moodle_url $pageurl
     */
    public function __construct(bool $canassign, int $focusunitid, moodle_url $pageurl) {
        $this->canassign = $canassign;
        $this->focusunitid = $focusunitid;
        $this->pageurl = $pageurl;
    }

    /**
     * All units of the active backend as id => name.
     *
     * @return array
     */
    public static function get_all_unit_names(): array {
        global $DB;
        if (get_config('local_taskflow', 'organisational_unit_option') === 'cohort') {
            $records = $DB->get_records('cohort', null, 'name', 'id, name');
        } else {
            $records = $DB->get_records('local_taskflow_units', null, 'name', 'id, name');
        }
        $names = [];
        foreach ($records as $record) {
            $names[(int)$record->id] = format_string($record->name);
        }
        return $names;
    }

    /**
     * The template of this view; adapters override this to swap the design.
     *
     * @return string
     */
    public function get_template(): string {
        return 'local_taskflow/unitspage';
    }

    /**
     * Prepare data for use in a template.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        global $DB, $PAGE;

        $this->unitnames = self::get_all_unit_names();
        $this->load_members();

        // Parent relations: child => parent.
        $parents = [];
        foreach (unit_relations::get_all_active_unit_relations() as $relation) {
            if (isset($this->unitnames[(int)$relation->childid]) && isset($this->unitnames[(int)$relation->parentid])) {
                $parents[(int)$relation->childid] = (int)$relation->parentid;
            }
        }
        $children = [];
        foreach ($parents as $child => $parent) {
            $children[$parent][] = $child;
        }
        $roots = [];
        foreach (array_keys($this->unitnames) as $unitid) {
            if (!isset($parents[$unitid])) {
                $roots[] = $unitid;
            }
        }

        $rulecount = (int)$DB->count_records_select('local_taskflow_rules', 'unitid > 0');
        $tree = [];
        foreach ($roots as $unitid) {
            $tree[] = $this->export_unit($unitid, $children, [], 0);
        }

        $PAGE->requires->js_call_amd('local_taskflow/unitspage', 'init');
        return [
            'canassign' => $this->canassign,
            'units' => $tree,
            'hasunits' => !empty($tree),
            'unitcount' => count($this->unitnames),
            'membercount' => (int)$DB->count_records_select(
                'local_taskflow_unit_members',
                'unitid > 0 AND (active = 1 OR active IS NULL)'
            ),
            'rulecount' => $rulecount,
            'dashboardurl' => (new moodle_url('/local/taskflow/dashboard.php'))->out(false),
            'organisationurl' => (new moodle_url('/local/taskflow/units.php'))->out(false),
            'isorganisation' => true,
            'rulesurl' => (new moodle_url('/local/taskflow/editrule.php', ['id' => 0]))->out(false),
        ];
    }

    /**
     * Loads the member counts of all units and the names only for small units.
     *
     * Units can hold thousands of members; their names are never loaded here but through the
     * lazily loaded members table when the viewer expands the unit.
     *
     * @return void
     */
    private function load_members(): void {
        global $DB;
        $where = "um.unitid > 0 AND (um.active = 1 OR um.active IS NULL)";
        $counts = $DB->get_records_sql(
            "SELECT um.unitid, COUNT(um.id) AS membercount
               FROM {local_taskflow_unit_members} um
               JOIN {user} u ON u.id = um.userid AND u.deleted = 0
              WHERE $where
           GROUP BY um.unitid"
        );
        $smallunits = [];
        foreach ($counts as $row) {
            $this->membercounts[(int)$row->unitid] = (int)$row->membercount;
            if ((int)$row->membercount <= self::MEMBERS_INLINE) {
                $smallunits[] = (int)$row->unitid;
            }
        }
        if (empty($smallunits)) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($smallunits, SQL_PARAMS_NAMED, 'unit');
        $sql = "SELECT um.id, um.unitid, u.id AS userid, u.firstname, u.lastname, u.email, u.picture, u.imagealt,
                       u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename
                  FROM {local_taskflow_unit_members} um
                  JOIN {user} u ON u.id = um.userid AND u.deleted = 0
                 WHERE $where AND um.unitid $insql
              ORDER BY u.lastname, u.firstname";
        foreach ($DB->get_records_sql($sql, $params) as $record) {
            $this->members[(int)$record->unitid][] = $record;
        }
    }

    /**
     * The lazily loaded, searchable members table of a large unit.
     *
     * @param int $unitid
     * @return string
     */
    private function render_members_table(int $unitid): string {
        $table = new unit_members_table('local_taskflow_unit_members_' . $unitid);
        $table->define_columns(['fullname', 'email']);
        $table->define_headers([get_string('fullname'), get_string('email')]);
        $table->define_fulltextsearchcolumns(['firstname', 'lastname', 'email']);
        $table->define_sortablecolumns(['lastname', 'email']);
        $table->sort_default_column = 'lastname';
        $table->define_baseurl(new moodle_url('/local/taskflow/units.php', ['id' => $unitid]));
        $table->set_filter_sql(
            'u.id, u.firstname, u.lastname, u.email, u.firstnamephonetic, u.lastnamephonetic, u.middlename,
             u.alternatename, um.unitid',
            '{local_taskflow_unit_members} um JOIN {user} u ON u.id = um.userid',
            'um.unitid = :unitid AND u.deleted = 0 AND (um.active = 1 OR um.active IS NULL)',
            '',
            ['unitid' => $unitid]
        );
        // The AJAX reload of the table is only served to users who may open the organisation page.
        $table->requirelogin = true;
        $table->requirecapability = 'local/taskflow:vieworganisation';
        $table->pageable(true);
        $table->showcountlabel = true;
        $table->showrowcountselect = true;
        [, , $html] = $table->lazyouthtml(self::MEMBERS_INLINE, true);
        return $html;
    }

    /**
     * Exports one unit and its child units recursively.
     *
     * @param int $unitid
     * @param array $children parent => [child ids]
     * @param array $ancestors ancestor unit ids, nearest last
     * @param int $depth
     * @return array
     */
    private function export_unit(int $unitid, array $children, array $ancestors, int $depth): array {
        $ownrules = [];
        foreach (unit_rule_assignment_service::get_rules_for_unit($unitid) as $rule) {
            $ownrules[] = $this->export_rule($rule, $unitid, false);
        }
        $inheritedrules = [];
        foreach (array_reverse($ancestors) as $ancestorid) {
            foreach (unit_rule_assignment_service::get_rules_for_unit($ancestorid) as $rule) {
                if ($rule->inheritance) {
                    $inheritedrules[] = $this->export_rule($rule, $ancestorid, true);
                }
            }
        }

        $members = [];
        foreach ($this->members[$unitid] ?? [] as $member) {
            $members[] = [
                'userid' => (int)$member->userid,
                'fullname' => fullname($member),
                'url' => (new moodle_url('/local/taskflow/person.php', ['id' => $member->userid]))->out(false),
            ];
        }

        $childunits = [];
        foreach ($children[$unitid] ?? [] as $childid) {
            $childunits[] = $this->export_unit($childid, $children, array_merge($ancestors, [$unitid]), $depth + 1);
        }

        $membercount = $this->membercounts[$unitid] ?? 0;
        $descendantmembers = 0;
        foreach ($childunits as $child) {
            $descendantmembers += $child['membercount'] + $child['descendantmembers'];
        }

        return [
            'unitid' => $unitid,
            'name' => $this->unitnames[$unitid] ?? (string)$unitid,
            'depth' => $depth,
            'focus' => $unitid === $this->focusunitid,
            'membercount' => $membercount,
            'descendantmembers' => $descendantmembers,
            'members' => $members,
            'hasmembers' => !empty($members),
            'hasmemberstable' => $membercount > self::MEMBERS_INLINE,
            'memberstable' => $membercount > self::MEMBERS_INLINE ? $this->render_members_table($unitid) : '',
            'ownrules' => $ownrules,
            'ownrulecount' => count($ownrules),
            'inheritedrules' => $inheritedrules,
            'inheritedrulecount' => count($inheritedrules),
            'rulecount' => count($ownrules) + count($inheritedrules),
            'children' => $childunits,
            'haschildren' => !empty($childunits),
            'childcount' => count($childunits),
            'canassign' => $this->canassign,
        ];
    }

    /**
     * Exports one rule chip.
     *
     * @param stdClass $rule
     * @param int $unitid The unit the rule is bound to.
     * @param bool $inherited Whether the chip is shown on a descendant unit.
     * @return array
     */
    private function export_rule(stdClass $rule, int $unitid, bool $inherited): array {
        global $DB;
        $assignments = (int)$DB->count_records('local_taskflow_assignment', ['ruleid' => $rule->id, 'active' => 1]);
        return [
            'ruleid' => (int)$rule->id,
            'rulename' => format_string($rule->rulename),
            'isactive' => !empty($rule->isactive),
            'inheritance' => (bool)$rule->inheritance,
            'inherited' => $inherited,
            'inheritedfromname' => $inherited ? ($this->unitnames[$unitid] ?? (string)$unitid) : '',
            'activeassignments' => $assignments,
            'personalcount' => count(personal_rule_assignment_service::get_user_ids_for_rule((int)$rule->id)),
            'editurl' => (new moodle_url('/local/taskflow/editrule.php', ['id' => $rule->id]))->out(false),
            'unassignurl' => (new moodle_url($this->pageurl, [
                'action' => 'unassign',
                'ruleid' => (int)$rule->id,
                'unitid' => $unitid,
                'sesskey' => sesskey(),
            ]))->out(false),
        ];
    }
}
