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
 * Side-pane preview 'taskflow_supervisor_overview': team table of one supervisor.
 *
 * Data contract (filled by local_taskflow.supervisor_overview): supervisor {id, fullname},
 * subordinates[] {userid, fullname, open, overdue, completed, open_requests, unread_chats},
 * totals {open, overdue, completed, open_requests, unread_chats}. Counters greater than
 * zero are shown as badges; overdue turns red only when it is actually greater than zero, so
 * the colour follows the number, never a wording.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class taskflow_supervisor_overview_preview_renderer extends taskflow_preview_renderer_base {
    /** Preview type. */
    public const PREVIEW_TYPE = taskflow_preview_renderer_factory::TYPE_SUPERVISOR_OVERVIEW;

    /**
     * Build the template context.
     *
     * @param array $data
     * @return array|null
     */
    protected function build_context(array $data): ?array {
        $supervisor = (array)($data['supervisor'] ?? []);
        $entries = is_array($data['subordinates'] ?? null) ? (array)$data['subordinates'] : [];
        $totals = (array)($data['totals'] ?? []);

        $rows = [];
        foreach ($entries as $entry) {
            $entry = (array)$entry;
            $subordinateid = (int)($entry['userid'] ?? 0);
            if ($subordinateid <= 0) {
                continue;
            }
            $overdue = (int)($entry['overdue'] ?? 0);
            $rows[] = [
                'userid' => $subordinateid,
                'fullname' => $this->name($entry['fullname'] ?? ''),
                'url' => taskflow_result_link_builder::user_url($subordinateid),
                'open' => (int)($entry['open'] ?? 0),
                'overdue' => $overdue,
                'overdueclass' => 'badge ' . ($overdue > 0 ? 'bg-danger' : 'bg-light text-dark'),
                'completed' => (int)($entry['completed'] ?? 0),
                'openrequests' => (int)($entry['open_requests'] ?? 0),
                'unreadchats' => (int)($entry['unread_chats'] ?? 0),
            ];
        }

        $supervisorname = $this->name($supervisor['fullname'] ?? '');
        $title = $supervisorname !== ''
            ? $this->esc($this->str('agent_preview_supervisor_overview_title', (object)[
                'fullname' => (string)($supervisor['fullname'] ?? ''),
                'count' => count($rows),
            ]))
            : $this->esc($this->str('agent_preview_supervisor_overview_titleshort', count($rows)));

        $overduetotal = (int)($totals['overdue'] ?? 0);
        $summary = [
            [
                'label' => $this->esc($this->str('agent_preview_open')),
                'value' => (int)($totals['open'] ?? 0),
                'class' => 'badge bg-primary',
            ],
            [
                'label' => $this->esc($this->str('agent_preview_overdue')),
                'value' => $overduetotal,
                'class' => 'badge ' . ($overduetotal > 0 ? 'bg-danger' : 'bg-light text-dark'),
            ],
            [
                'label' => $this->esc($this->str('agent_preview_completed')),
                'value' => (int)($totals['completed'] ?? 0),
                'class' => 'badge bg-success',
            ],
            [
                'label' => $this->esc($this->str('agent_preview_open_requests')),
                'value' => (int)($totals['open_requests'] ?? 0),
                'class' => 'badge bg-light text-dark',
            ],
            [
                'label' => $this->esc($this->str('agent_preview_unread_chats')),
                'value' => (int)($totals['unread_chats'] ?? 0),
                'class' => 'badge bg-light text-dark',
            ],
        ];

        return [
            'title' => $title,
            'totalsprefix' => $this->esc($this->str('agent_preview_totals')),
            'summary' => $summary,
            'rows' => $rows,
            'hasrows' => !empty($rows),
            'emptytext' => $this->esc($this->str('agent_preview_empty_supervisor_overview')),
            'headers' => [
                'fullname' => $this->esc($this->str('fullname')),
                'open' => $this->esc($this->str('agent_preview_open')),
                'overdue' => $this->esc($this->str('agent_preview_overdue')),
                'completed' => $this->esc($this->str('agent_preview_completed')),
                'requests' => $this->esc($this->str('agent_preview_open_requests')),
                'chats' => $this->esc($this->str('agent_preview_unread_chats')),
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
        $entries = is_array($data['subordinates'] ?? null) ? (array)$data['subordinates'] : [];
        $userids = array_values(array_filter(array_map(
            static fn($row): int => (int)(((array)$row)['userid'] ?? 0),
            $entries
        )));
        $supervisorid = (int)(((array)($data['supervisor'] ?? []))['id'] ?? 0);
        if ($supervisorid > 0) {
            array_unshift($userids, $supervisorid);
        }
        return ['userids' => $userids];
    }
}
