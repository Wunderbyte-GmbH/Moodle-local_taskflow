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

namespace local_taskflow\local\wizard\taskflow\preview;

use local_taskflow\local\wizard\taskflow\taskflow_permission_resolver;
use local_taskflow\local\wizard\taskflow\taskflow_result_link_builder;

/**
 * Side-pane preview 'taskflow_assignment_list': compact table of assignments.
 *
 * Data contract (as filled by local_taskflow.search_assignments and the mutation skills
 * reusing the type): assignments[] {id, userid, fullname, ruleid, rulename, status, duedate,
 * overduecounter, prolongedcounter, active, change?}, scope, total, filters[].
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class taskflow_assignment_list_preview_renderer extends taskflow_preview_renderer_base {
    /** Preview type. */
    public const PREVIEW_TYPE = taskflow_preview_renderer_factory::TYPE_ASSIGNMENT_LIST;

    /**
     * Build the template context.
     *
     * @param array $data
     * @return array|null
     */
    protected function build_context(array $data): ?array {
        $assignments = is_array($data['assignments'] ?? null) ? (array)$data['assignments'] : [];
        $total = (int)($data['total'] ?? count($assignments));
        $scope = trim((string)($data['scope'] ?? ''));
        $filters = array_values(array_filter(array_map('strval', (array)($data['filters'] ?? [])), 'strlen'));
        $now = time();

        $rows = [];
        $haschange = false;
        foreach ($assignments as $assignment) {
            $assignment = (array)$assignment;
            $id = (int)($assignment['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $status = (int)($assignment['status'] ?? 0);
            $badge = $this->status_badge($status);
            $counters = [];
            if ((int)($assignment['prolongedcounter'] ?? 0) > 0) {
                $counters[] = (int)$assignment['prolongedcounter'] . '×';
            }
            if ((int)($assignment['overduecounter'] ?? 0) > 0) {
                $counters[] = (int)$assignment['overduecounter'] . '×';
            }
            $change = trim((string)($assignment['change'] ?? ''));
            $haschange = $haschange || $change !== '';
            $ruleid = (int)($assignment['ruleid'] ?? 0);
            $userid = (int)($assignment['userid'] ?? 0);
            $rows[] = [
                'id' => $id,
                'url' => taskflow_result_link_builder::assignment_url($id),
                'fullname' => $this->name($assignment['fullname'] ?? ''),
                'userurl' => $userid > 0 ? taskflow_result_link_builder::user_url($userid) : '',
                'rulename' => $this->name($assignment['rulename'] ?? ''),
                'ruleid' => $ruleid,
                'ruleurl' => $ruleid > 0 ? taskflow_result_link_builder::edit_rule_url($ruleid) : '',
                'due' => $this->due_chip((int)($assignment['duedate'] ?? 0) ?: null, $now),
                'badge' => $badge,
                'counters' => $this->esc(implode(' / ', $counters)),
                'inactive' => array_key_exists('active', $assignment) && empty($assignment['active']),
                'inactivetext' => $this->esc($this->str('activityinactive')),
                'change' => $this->esc($change),
            ];
        }

        $scopelabel = '';
        $knownscopes = [
            taskflow_permission_resolver::SCOPE_ADMIN,
            taskflow_permission_resolver::SCOPE_SUPERVISOR,
            taskflow_permission_resolver::SCOPE_SELF,
        ];
        if (in_array($scope, $knownscopes, true)) {
            $scopelabel = $this->esc($this->str('agent_scope_' . $scope));
        }

        return [
            'title' => $this->esc($this->str('agent_preview_assignments_title', $total)),
            'scopelabel' => $scopelabel,
            'scopeprefix' => $this->esc($this->str('agent_preview_scope')),
            'filters' => $this->esc(implode(' · ', $filters)),
            'hasfilters' => !empty($filters),
            'filtersprefix' => $this->esc($this->str('agent_preview_filters')),
            'rows' => $rows,
            'hasrows' => !empty($rows),
            'haschange' => $haschange,
            'emptytext' => $this->esc($this->str('agent_preview_empty_assignment_list')),
            'showing' => count($rows) < $total
                ? $this->esc($this->str('agent_preview_showing', (object)['shown' => count($rows), 'total' => $total]))
                : '',
            'headers' => [
                'id' => '#',
                'fullname' => $this->esc($this->str('fullname')),
                'rule' => $this->esc($this->str('rule')),
                'duedate' => $this->esc($this->str('duedate')),
                'status' => $this->esc($this->str('status')),
                'change' => $this->esc($this->str('agent_preview_change')),
            ],
            'dashboard' => $this->link(
                taskflow_result_link_builder::dashboard_url(),
                $this->str('agent_preview_open_dashboard')
            ),
        ];
    }

    /**
     * Id arrays for accumulation.
     *
     * @param array $data
     * @return array<string,int[]>
     */
    protected function default_payload(array $data): array {
        $assignments = is_array($data['assignments'] ?? null) ? (array)$data['assignments'] : [];
        $ids = static fn(string $key): array => array_values(array_filter(array_map(
            static fn($row): int => (int)(((array)$row)[$key] ?? 0),
            $assignments
        )));
        return ['assignmentids' => $ids('id'), 'userids' => $ids('userid')];
    }
}
