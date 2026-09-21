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

use html_writer;

/**
 * Side-pane preview 'taskflow_units_tree': the organisational units as a nested tree.
 *
 * Data contract (filled by local_taskflow.list_units): units[] {id, name, parentid, depth,
 * path, members, rules_count}, backend, total, parentid, query. The nesting uses native
 * <details>/<summary> elements (open down to the second level) so the tree is keyboard
 * accessible without any JavaScript; the nested markup is built here and handed to the
 * template as one pre-escaped fragment, because mustache cannot recurse over a template
 * without a dedicated partial.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class taskflow_units_tree_preview_renderer extends taskflow_preview_renderer_base {
    /** Preview type. */
    public const PREVIEW_TYPE = taskflow_preview_renderer_factory::TYPE_UNITS_TREE;

    /** Levels rendered expanded (0 and 1 = root and its children). */
    private const OPEN_DEPTH = 1;

    /** Guard against pathological/cyclic data. */
    private const MAX_LEVEL = 12;

    /**
     * Build the template context.
     *
     * @param array $data
     * @return array|null
     */
    protected function build_context(array $data): ?array {
        $entries = is_array($data['units'] ?? null) ? (array)$data['units'] : [];
        $backend = trim((string)($data['backend'] ?? ''));
        $total = (int)($data['total'] ?? count($entries));
        $parentid = (int)($data['parentid'] ?? 0);
        $query = trim((string)($data['query'] ?? ''));

        $units = [];
        foreach ($entries as $entry) {
            $entry = (array)$entry;
            $id = (int)($entry['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $units[$id] = [
                'id' => $id,
                'name' => (string)($entry['name'] ?? ''),
                'parentid' => (int)($entry['parentid'] ?? 0),
                'members' => (int)($entry['members'] ?? 0),
                'rules' => (int)($entry['rules_count'] ?? 0),
            ];
        }

        $children = [];
        foreach ($units as $id => $unit) {
            $parent = isset($units[$unit['parentid']]) ? $unit['parentid'] : 0;
            $children[$parent][] = $id;
        }

        $filters = [];
        if ($parentid > 0) {
            $filters[] = $this->str('agent_preview_units_below', $parentid);
        }
        if ($query !== '') {
            $filters[] = $this->str('agent_preview_rule_list_query', $query);
        }

        return [
            'title' => $this->esc($this->str('agent_preview_units_title', $total)),
            'backend' => $this->esc($backend),
            'hasbackend' => $backend !== '',
            'backendprefix' => $this->esc($this->str('agent_preview_backend')),
            'filters' => $this->esc(implode(' · ', $filters)),
            'hasfilters' => !empty($filters),
            'filtersprefix' => $this->esc($this->str('agent_preview_filters')),
            'tree' => $this->render_level($children, $units, 0, 0),
            'hasrows' => !empty($units),
            'emptytext' => $this->esc($this->str('agent_preview_empty_units_tree')),
        ];
    }

    /**
     * Render one level of the tree as a nested <ul> of <details> elements.
     *
     * @param array<int,int[]> $children Parent id => child unit ids.
     * @param array<int,array> $units Unit id => unit data.
     * @param int $parentid
     * @param int $level
     * @return string HTML ('' when the level is empty).
     */
    private function render_level(array $children, array $units, int $parentid, int $level): string {
        if ($level > self::MAX_LEVEL || empty($children[$parentid])) {
            return '';
        }

        $items = '';
        foreach ($children[$parentid] as $unitid) {
            $unit = $units[$unitid] ?? null;
            if ($unit === null) {
                continue;
            }
            $badges = html_writer::span(
                $this->esc($this->str('agent_preview_members_count', $unit['members'])),
                'badge bg-light text-dark me-1'
            ) . html_writer::span(
                $this->esc($this->str('agent_preview_rules_count', $unit['rules'])),
                'badge bg-light text-dark'
            );
            $label = html_writer::tag(
                'summary',
                html_writer::span($this->name($unit['name']), 'fw-bold me-2') . '#' . $unitid . ' ' . $badges
            );
            $inner = $this->render_level($children, $units, $unitid, $level + 1);
            $attributes = ['class' => 'taskflow-ai-preview-unit'];
            if ($level <= self::OPEN_DEPTH) {
                $attributes['open'] = 'open';
            }
            $items .= html_writer::tag('li', html_writer::tag('details', $label . $inner, $attributes));
        }

        if ($items === '') {
            return '';
        }
        return html_writer::tag('ul', $items, ['class' => 'list-unstyled mb-0' . ($level > 0 ? ' ps-3' : '')]);
    }

    /**
     * Id arrays for accumulation.
     *
     * @param array $data
     * @return array<string,int[]>
     */
    protected function default_payload(array $data): array {
        $entries = is_array($data['units'] ?? null) ? (array)$data['units'] : [];
        return [
            'unitids' => array_values(array_filter(array_map(
                static fn($row): int => (int)(((array)$row)['id'] ?? 0),
                $entries
            ))),
        ];
    }
}
