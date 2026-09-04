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

use local_taskflow\local\wizard\taskflow\taskflow_result_link_builder;

/**
 * Side-pane preview of a rule list (skill local_taskflow.search_rules, concept §2.1/§2.2 #3).
 *
 * Data shape (as filled by the skill): ['rules' => [{id, name, type, unitid, unitname, isactive,
 * targettypes[], assignments_count, edit_url}], 'total' => int, 'query' => string, 'limit' => int].
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class taskflow_rule_list_preview_renderer extends taskflow_preview_renderer_base {
    /** Preview type. */
    public const PREVIEW_TYPE = taskflow_preview_renderer_factory::TYPE_RULE_LIST;

    /**
     * Template context: table rows or the empty state.
     *
     * @param array $data
     * @return array|null
     */
    protected function build_context(array $data): ?array {
        $rules = array_values(array_filter((array)($data['rules'] ?? []), 'is_array'));
        $query = trim((string)($data['query'] ?? ''));
        $total = max((int)($data['total'] ?? count($rules)), count($rules));

        $rows = [];
        foreach ($rules as $rule) {
            $id = (int)($rule['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $active = (bool)($rule['isactive'] ?? false);
            $targettypes = [];
            foreach ((array)($rule['targettypes'] ?? []) as $type) {
                $targettypes[] = ['label' => $this->target_type_label((string)$type)];
            }
            $type = (string)($rule['type'] ?? 'unit') === 'user' ? 'user' : 'unit';
            $rows[] = [
                'id' => $id,
                'name' => $this->name((string)($rule['name'] ?? '')),
                'url' => (string)($rule['edit_url'] ?? taskflow_result_link_builder::edit_rule_url($id)),
                'typelabel' => $this->esc($this->str('agent_preview_rule_type_' . $type)),
                'unitname' => $this->name((string)($rule['unitname'] ?? '')),
                'assignments' => (int)($rule['assignments_count'] ?? 0),
                'active' => $active,
                'activelabel' => $this->esc($this->str($active ? 'activityactive' : 'activityinactive')),
                'activeglyph' => $active ? '✓' : '✗',
                'targettypes' => $targettypes,
                'hastargettypes' => !empty($targettypes),
            ];
        }

        $context = [
            'title' => $this->esc($this->str('agent_preview_rule_list_title', count($rows))),
            'hasquery' => $query !== '',
            'query' => $this->esc($this->str('agent_preview_rule_list_query', $query)),
            'hasshowing' => $total > count($rows),
            'showing' => $this->esc($this->str('agent_preview_rule_list_showing', (object)[
                'shown' => count($rows),
                'total' => $total,
            ])),
            'isempty' => empty($rows),
            'emptyhtml' => $this->empty_state_html(),
            'dashboardlink' => $this->link(
                taskflow_result_link_builder::dashboard_url(),
                $this->str('agent_preview_open_dashboard')
            ),
            'headers' => [
                'name' => $this->esc($this->str('name')),
                'type' => $this->esc($this->str('type')),
                'unit' => $this->esc($this->str('agent_preview_unit')),
                'assignments' => $this->esc($this->str('agent_preview_assignments')),
                'active' => $this->esc($this->str('active')),
                'targets' => $this->esc($this->str('agent_preview_targets')),
            ],
            'rows' => $rows,
        ];

        return $context;
    }

    /**
     * Rule ids of the rendered rows.
     *
     * @param array $data
     * @return array<string,int[]>
     */
    protected function default_payload(array $data): array {
        $ids = [];
        foreach ((array)($data['rules'] ?? []) as $rule) {
            $id = is_array($rule) ? (int)($rule['id'] ?? 0) : 0;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return ['ruleids' => $ids];
    }

    /**
     * Escaped label of a target type (lang key = type name, raw type when unknown).
     *
     * @param string $type
     * @return string
     */
    private function target_type_label(string $type): string {
        $type = trim($type);
        if ($type !== '' && get_string_manager()->string_exists($type, 'local_taskflow')) {
            return $this->esc($this->str($type));
        }
        return $this->esc($type);
    }
}
