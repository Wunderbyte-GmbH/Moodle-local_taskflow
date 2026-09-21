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

use context_system;
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\rules\unit_rule_assignment_service;
use local_taskflow\local\units\unit_relations;
use local_taskflow\shortcodes;
use local_taskflow\taskflow_stringmanager;
use moodle_url;
use renderable;
use renderer_base;
use stdClass;
use templatable;

/**
 * HR overview: counters, assignments needing attention, status chart, all assignments, rules per unit,
 * open requests. Every table and chart is the existing shortcode building block, only arranged anew.
 *
 * @package local_taskflow
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hroverview implements adapter_view_interface, renderable, templatable {
    /** @var int Days ahead that count as "due soon". */
    private const DUE_SOON_DAYS = 14;

    /** @var moodle_url */
    private moodle_url $pageurl;

    /**
     * Constructor.
     *
     * @param moodle_url $pageurl
     */
    public function __construct(moodle_url $pageurl) {
        $this->pageurl = $pageurl;
    }

    /**
     * Whether the viewer may open the HR overview: same rule as the admin tab of index.php.
     *
     * @param int $viewerid
     * @return bool
     */
    public static function can_view(int $viewerid): bool {
        $hrusers = array_filter(array_map('trim', explode(
            ',',
            (string)get_config('bookingextension_confirmation_supervisor', 'confirmation_supervisor_hrusers')
        )));
        return in_array((string)$viewerid, $hrusers, true)
            || has_capability('local/taskflow:editassignment', context_system::instance(), $viewerid);
    }

    /**
     * The template of this view; adapters override this to swap the design.
     *
     * @return string
     */
    public function get_template(): string {
        return 'local_taskflow/dashboardpage';
    }

    /**
     * Prepare data for use in a template.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $env = new stdClass();
        $next = fn($a) => $a;
        $overdue = assignment_status_facade::get_status_identifier('overdue');

        return [
            'ishr' => true,
            'kpis' => $this->export_kpis(),
            'attentiontable' => shortcodes::assignmentsdashboard('', [
                'status' => (string)$overdue,
                'active' => 1,
                'columns' => 'fullname,rulename,duedate,statussortkey,actions',
                'sortby' => 'duedate',
                'sortorder' => 'asc',
                'perpage' => 10,
                'noheading' => 1,
                'bulkactions' => 1,
                'statusbadges' => 1,
                'toolbartemplate' => 1,
            ], null, $env, $next),
            'chart' => shortcodes::assignmentsdashboard(
                '',
                ['chart' => 1, 'noheading' => 1, 'chartlegend' => 'right'],
                null,
                $env,
                $next
            ),
            'assignmentstable' => shortcodes::assignmentsdashboard('', [
                'columns' => 'fullname,rulename,targets,duedate,statussortkey,timemodified,actions',
                'filter' => 'status,rulename,completed',
                'noheading' => 1,
                'bulkactions' => 1,
                'statusbadges' => 1,
                'toolbartemplate' => 1,
            ], null, $env, $next),
            'requeststable' => shortcodes::requests(
                '',
                ['noheader' => 1, 'bulkactions' => 1, 'all' => 1, 'toolbartemplate' => 1],
                null,
                $env,
                $next
            ),
            'units' => $this->export_units(),
            'organisationurl' => (new moodle_url('/local/taskflow/units.php'))->out(false),
            'createruleurl' => (new moodle_url('/local/taskflow/editrule.php', ['id' => 0]))->out(false),
        ];
    }

    /**
     * Counters over all assignments and requests.
     *
     * @return array
     */
    private function export_kpis(): array {
        global $DB;
        $now = time();
        $completed = assignment_status_facade::get_status_identifier('completed');
        $overdue = assignment_status_facade::get_status_identifier('overdue');

        $active = (int)$DB->count_records_select(
            'local_taskflow_assignment',
            'active = 1 AND status <> :c',
            ['c' => $completed]
        );
        $done = (int)$DB->count_records('local_taskflow_assignment', ['status' => $completed]);
        $late = (int)$DB->count_records('local_taskflow_assignment', ['active' => 1, 'status' => $overdue]);
        $duesoon = (int)$DB->count_records_select(
            'local_taskflow_assignment',
            'active = 1 AND status <> :c AND status <> :o AND duedate > :now AND duedate <= :soon',
            ['c' => $completed, 'o' => $overdue, 'now' => $now, 'soon' => $now + self::DUE_SOON_DAYS * DAYSECS]
        );
        // The column "status" holds the request type; "treated" is the processing state (0 = open).
        $requests = (int)$DB->count_records('local_taskflow_requests', ['treated' => 0]);
        $units = count(unitspage::get_all_unit_names());
        $rules = (int)$DB->count_records('local_taskflow_rules');

        $unitsurl = (new moodle_url('/local/taskflow/units.php'))->out(false);
        return [
            self::kpi($active, taskflow_stringmanager::get_string('kpi_active'), 'fa-tasks', 'text-primary'),
            self::kpi($done, taskflow_stringmanager::get_string('kpi_completed'), 'fa-check-circle', 'text-success'),
            self::kpi(
                $duesoon,
                taskflow_stringmanager::get_string('kpi_duesoon', self::DUE_SOON_DAYS),
                'fa-clock',
                'text-warning'
            ),
            self::kpi(
                $late,
                taskflow_stringmanager::get_string('kpi_overdue'),
                'fa-exclamation-triangle',
                $late ? 'text-danger' : 'text-muted'
            ),
            self::kpi($requests, taskflow_stringmanager::get_string('kpi_openrequests'), 'fa-envelope-open-text', 'text-info'),
            self::kpi(
                $units,
                taskflow_stringmanager::get_string('kpi_unitsrules', $rules),
                'fa-sitemap',
                'text-secondary',
                $unitsurl
            ),
        ];
    }

    /**
     * One counter tile.
     *
     * @param int $value
     * @param string $label
     * @param string $icon Font Awesome class.
     * @param string $class Colour class of the icon.
     * @param string $url Optional link.
     * @return array
     */
    private static function kpi(int $value, string $label, string $icon, string $class, string $url = ''): array {
        return ['value' => $value, 'label' => $label, 'icon' => $icon, 'class' => $class, 'url' => $url];
    }

    /**
     * Units with member and rule counts, in tree order, for the "rules per unit" card.
     *
     * @return array
     */
    private function export_units(): array {
        global $DB;
        $names = unitspage::get_all_unit_names();
        $parents = [];
        foreach (unit_relations::get_all_active_unit_relations() as $relation) {
            if (isset($names[(int)$relation->childid]) && isset($names[(int)$relation->parentid])) {
                $parents[(int)$relation->childid] = (int)$relation->parentid;
            }
        }
        $children = [];
        foreach ($parents as $child => $parent) {
            $children[$parent][] = $child;
        }
        $counts = [];
        $countrows = $DB->get_records_sql(
            "SELECT unitid, COUNT(id) AS n FROM {local_taskflow_unit_members}
              WHERE unitid > 0 AND (active = 1 OR active IS NULL) GROUP BY unitid"
        );
        foreach ($countrows as $row) {
            $counts[(int)$row->unitid] = (int)$row->n;
        }
        $rows = [];
        $walk = function (int $unitid, int $depth) use (&$walk, &$rows, $names, $children, $counts) {
            $own = unit_rule_assignment_service::get_rules_for_unit($unitid);
            $inherited = unit_rule_assignment_service::get_inherited_rules_for_unit($unitid);
            $rows[] = [
                'name' => $names[$unitid],
                'depth' => $depth,
                'indent' => $depth * 22,
                'members' => $counts[$unitid] ?? 0,
                'ownrules' => count($own),
                'inheritedrules' => count($inherited),
                'rulenames' => implode(' · ', array_map(fn($r) => format_string($r->rulename), $own)),
                'url' => (new moodle_url('/local/taskflow/units.php', ['id' => $unitid]))->out(false),
            ];
            foreach ($children[$unitid] ?? [] as $child) {
                $walk($child, $depth + 1);
            }
        };
        foreach (array_keys($names) as $unitid) {
            if (!isset($parents[$unitid])) {
                $walk($unitid, 0);
            }
        }
        return $rows;
    }
}
