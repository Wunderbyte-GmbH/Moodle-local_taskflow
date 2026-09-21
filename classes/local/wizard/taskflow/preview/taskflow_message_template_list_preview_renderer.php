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
 * Side-pane preview of a message template list (skill local_taskflow.search_message_templates).
 *
 * Data shape (as filled by the skill): ['templates' => [{id, name, type, class, subject,
 * recipients[], cc[], timing{}, timing_label, package[], priority, used_in_rules[] {id,name,url},
 * edit_url}], 'total' => int, 'query' => string, 'type' => string, 'limit' => int].
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class taskflow_message_template_list_preview_renderer extends taskflow_preview_renderer_base {
    /** Preview type. */
    public const PREVIEW_TYPE = taskflow_preview_renderer_factory::TYPE_MESSAGE_TEMPLATE_LIST;

    /** Priority id => lang key of its label. */
    private const PRIORITY_LABELS = [1 => 'prioritylow', 2 => 'prioritymedium', 3 => 'priorityhigh'];

    /**
     * Template context: table rows or the empty state.
     *
     * @param array $data
     * @return array|null
     */
    protected function build_context(array $data): ?array {
        $templates = array_values(array_filter((array)($data['templates'] ?? []), 'is_array'));
        $query = trim((string)($data['query'] ?? ''));
        $type = trim((string)($data['type'] ?? ''));
        $total = max((int)($data['total'] ?? count($templates)), count($templates));

        $rows = [];
        foreach ($templates as $template) {
            $id = (int)($template['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $rows[] = [
                'id' => $id,
                'name' => $this->name((string)($template['name'] ?? '')),
                'url' => (string)($template['edit_url'] ?? taskflow_result_link_builder::edit_message_url($id)),
                'typelabel' => $this->esc((string)($template['type'] ?? ($template['class'] ?? ''))),
                'subject' => $this->esc($this->truncate((string)($template['subject'] ?? ''), 80)),
                'recipients' => $this->role_list((array)($template['recipients'] ?? [])),
                'cc' => $this->role_list((array)($template['cc'] ?? [])),
                'timing' => $this->esc((string)($template['timing_label'] ?? '')),
                'prioritylabel' => $this->priority_label((int)($template['priority'] ?? 0)),
                'tags' => $this->badges((array)($template['package'] ?? [])),
                'hastags' => !empty($template['package']),
                'rules' => $this->rule_links((array)($template['used_in_rules'] ?? [])),
                'hasrules' => !empty($template['used_in_rules']),
            ];
        }

        return [
            'title' => $this->esc($this->str('agent_preview_message_template_list_title', count($rows))),
            'hasquery' => $query !== '',
            'query' => $this->esc($this->str('agent_preview_rule_list_query', $query)),
            'hastype' => $type !== '',
            'typefilter' => $this->esc($this->str('agent_preview_message_template_list_type', $type)),
            'hasshowing' => $total > count($rows),
            'showing' => $this->esc($this->str('agent_preview_rule_list_showing', (object)[
                'shown' => count($rows),
                'total' => $total,
            ])),
            'isempty' => empty($rows),
            'emptyhtml' => $this->empty_state_html(),
            'editorlink' => $this->link(
                taskflow_result_link_builder::edit_message_url(),
                $this->str('agent_preview_open_messages')
            ),
            'headers' => [
                'name' => $this->esc($this->str('name')),
                'type' => $this->esc($this->str('type')),
                'recipients' => $this->esc($this->str('recipientrole')),
                'cc' => $this->esc($this->str('carboncopyrole')),
                'timing' => $this->esc($this->str('senddirection')),
                'rules' => $this->esc($this->str('agent_preview_message_used_in_rules')),
            ],
            'rows' => $rows,
        ];
    }

    /**
     * Message template ids of the rendered rows.
     *
     * @param array $data
     * @return array<string,int[]>
     */
    protected function default_payload(array $data): array {
        $ids = [];
        foreach ((array)($data['templates'] ?? []) as $template) {
            $id = is_array($template) ? (int)($template['id'] ?? 0) : 0;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return ['messageids' => $ids];
    }

    /**
     * Escaped, comma separated recipient role labels (lang key = role name when it exists).
     *
     * @param array $roles
     * @return string
     */
    private function role_list(array $roles): string {
        $labels = [];
        foreach ($roles as $role) {
            $role = trim((string)$role);
            if ($role === '') {
                continue;
            }
            $labels[] = get_string_manager()->string_exists($role, 'local_taskflow') ? $this->str($role) : $role;
        }
        return $this->esc(implode(', ', $labels));
    }

    /**
     * Escaped priority label ('' when the id is unknown).
     *
     * @param int $priority
     * @return string
     */
    private function priority_label(int $priority): string {
        $key = self::PRIORITY_LABELS[$priority] ?? '';
        return $key === '' ? '' : $this->esc($this->str($key));
    }

    /**
     * Badge rows for the message package tags.
     *
     * @param array $tags
     * @return array<int,array{label:string}>
     */
    private function badges(array $tags): array {
        $badges = [];
        foreach ($tags as $tag) {
            $tag = trim((string)$tag);
            if ($tag !== '') {
                $badges[] = ['label' => $this->name($tag)];
            }
        }
        return $badges;
    }

    /**
     * Link rows for the rules using a template.
     *
     * @param array $rules
     * @return array<int,array{id:int,name:string,url:string}>
     */
    private function rule_links(array $rules): array {
        $links = [];
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $id = (int)($rule['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $links[] = [
                'id' => $id,
                'name' => $this->name((string)($rule['name'] ?? '')),
                'url' => (string)($rule['url'] ?? taskflow_result_link_builder::edit_rule_url($id)),
            ];
        }
        return $links;
    }
}
