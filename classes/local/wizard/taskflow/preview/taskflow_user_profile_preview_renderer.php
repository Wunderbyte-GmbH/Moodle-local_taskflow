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
 * Side-pane preview 'taskflow_user_profile': taskflow card of one person.
 *
 * Data contract (as filled by local_taskflow.get_user_taskflow_profile and reused by
 * manage_unit_membership): user {id, fullname, email}, units[] {id, name}, supervisor{},
 * deputies[], contractend, contractstart, longleave, externalid, mapped_fields{}, is_supervisor,
 * subordinates_count, assignments_by_status[] {status, label, count}, assignments_total,
 * adapter, change?, links{}.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class taskflow_user_profile_preview_renderer extends taskflow_preview_renderer_base {
    /** Preview type. */
    public const PREVIEW_TYPE = taskflow_preview_renderer_factory::TYPE_USER_PROFILE;

    /**
     * Build the template context.
     *
     * @param array $data
     * @return array|null Null when no user id is present.
     */
    protected function build_context(array $data): ?array {
        $user = is_array($data['user'] ?? null) ? (array)$data['user'] : [];
        $userid = (int)($user['id'] ?? 0);
        if ($userid <= 0) {
            return null;
        }

        $picture = '';
        $record = \core_user::get_user($userid, '*', IGNORE_MISSING);
        if ($record) {
            try {
                $output = \core\di::get(\core\output\renderer_helper::class)->get_core_renderer();
                $picture = (string)$output->user_picture($record, ['size' => 35, 'link' => false, 'alttext' => false]);
            } catch (\Throwable $e) {
                $picture = '';
            }
        }

        $units = [];
        foreach ((array)($data['units'] ?? []) as $unit) {
            $unit = (array)$unit;
            $units[] = ['id' => (int)($unit['id'] ?? 0), 'name' => $this->name($unit['name'] ?? '')];
        }
        $supervisor = is_array($data['supervisor'] ?? null) ? (array)$data['supervisor'] : [];
        $deputies = [];
        foreach ((array)($data['deputies'] ?? []) as $deputy) {
            $deputy = (array)$deputy;
            if (empty($deputy['id'])) {
                continue;
            }
            $deputies[] = $this->link_user((int)$deputy['id'], $this->name($deputy['fullname'] ?? ''));
        }

        $mapped = [];
        foreach ((array)($data['mapped_fields'] ?? []) as $key => $field) {
            $field = (array)$field;
            if (in_array((string)$key, ['supervisor', 'deputy', 'contractend', 'longleave'], true)) {
                continue;
            }
            $value = $field['value'] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $labelkey = get_string_manager()->string_exists((string)$key, 'local_taskflow') ? (string)$key : '';
            $mapped[] = [
                'label' => $this->esc($labelkey !== '' ? $this->str($labelkey) : (string)($field['field'] ?? $key)),
                'value' => $this->esc((string)($field['value_text'] ?? $value)),
            ];
        }

        $statuses = [];
        foreach ((array)($data['assignments_by_status'] ?? []) as $row) {
            $row = (array)$row;
            $badge = $this->status_badge((int)($row['status'] ?? 0));
            $badge['count'] = (int)($row['count'] ?? 0);
            $statuses[] = $badge;
        }

        $longleave = $data['longleave'] ?? null;
        $contractend = (int)($data['contractend'] ?? 0);
        $links = is_array($data['links'] ?? null) ? (array)$data['links'] : [];
        $docs = [];
        foreach ((array)($links['docs'] ?? []) as $url) {
            $url = trim((string)$url);
            if ($url !== '') {
                $docs[] = $this->link($url, $this->str('agent_preview_open_docs'));
            }
        }
        $change = trim((string)($data['change'] ?? ''));

        return [
            'id' => $userid,
            'picture' => $picture,
            'fullname' => $this->name($user['fullname'] ?? ''),
            'email' => $this->esc($user['email'] ?? ''),
            'profile' => $this->link_user($userid),
            'unitslabel' => $this->esc($this->str('agent_preview_units')),
            'units' => $units,
            'hasunits' => !empty($units),
            'nounits' => $this->esc($this->str('agent_preview_none')),
            'supervisorlabel' => $this->esc($this->str('agent_preview_supervisor')),
            'supervisor' => !empty($supervisor['id'])
                ? $this->link_user((int)$supervisor['id'], $this->name($supervisor['fullname'] ?? ''))
                : null,
            'nosupervisor' => $this->esc($this->str('agent_preview_no_supervisor')),
            'deputieslabel' => $this->esc($this->str('agent_preview_deputies')),
            'deputies' => $deputies,
            'hasdeputies' => !empty($deputies),
            'contractendlabel' => $this->esc($this->str('contractend')),
            'contractend' => $contractend > 0
                ? $this->esc(userdate($contractend, get_string('strftimedate', 'langconfig'))) : '',
            'longleavelabel' => $this->esc($this->str('longleave')),
            'longleave' => $longleave === null ? '' : $this->esc(get_string($longleave ? 'yes' : 'no')),
            'issupervisorlabel' => $this->esc($this->str('agent_preview_is_supervisor')),
            'issupervisor' => $this->esc(get_string(!empty($data['is_supervisor']) ? 'yes' : 'no')),
            'subordinates' => (int)($data['subordinates_count'] ?? 0),
            'mappedlabel' => $this->esc($this->str('agent_preview_adapter_fields', (string)($data['adapter'] ?? ''))),
            'mapped' => $mapped,
            'hasmapped' => !empty($mapped),
            'assignmentslabel' => $this->esc($this->str('agent_preview_assignments')),
            'assignmentstotal' => (int)($data['assignments_total'] ?? 0),
            'statuses' => $statuses,
            'hasstatuses' => !empty($statuses),
            'noassignments' => $this->esc($this->str('agent_preview_none')),
            'change' => $this->esc($change),
            'changelabel' => $this->esc($this->str('agent_preview_change')),
            'dashboard' => $this->link_dashboard($data),
            'certificates' => $this->link(
                taskflow_result_link_builder::my_certificates_url($userid),
                $this->str('agent_preview_open_certificates')
            ),
            'docs' => $docs,
        ];
    }

    /**
     * Id arrays for accumulation.
     *
     * @param array $data
     * @return array<string,int[]>
     */
    protected function default_payload(array $data): array {
        $user = is_array($data['user'] ?? null) ? (array)$data['user'] : [];
        $unitids = array_map(static fn($unit): int => (int)(((array)$unit)['id'] ?? 0), (array)($data['units'] ?? []));
        return ['userids' => [(int)($user['id'] ?? 0)], 'unitids' => $unitids];
    }
}
