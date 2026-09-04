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

use local_taskflow\local\requests;
use local_taskflow\local\wizard\taskflow\taskflow_permission_resolver;
use local_taskflow\local\wizard\taskflow\taskflow_result_link_builder;

/**
 * Side-pane preview 'taskflow_request_list': compact table of taskflow requests.
 *
 * Data contract (filled by local_taskflow.list_requests and reused by the request mutation
 * skills): requests[] {id, type, typelabel, treated, treatedlabel, userid, fullname,
 * assignmentid, rulename, receiver, receiverlabel, comment, timecreated}, scope, total,
 * filters[]. Badge colours are keyed by the requests::TREATED_STATUS_* constants, never by
 * a literal state name.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class taskflow_request_list_preview_renderer extends taskflow_preview_renderer_base {
    /** Preview type. */
    public const PREVIEW_TYPE = taskflow_preview_renderer_factory::TYPE_REQUEST_LIST;

    /** Maximum length of the comment shown in the table (full text in the title attribute). */
    private const COMMENT_LENGTH = 80;

    /**
     * Badge class per treated state (keys are the requests constants).
     *
     * @return array<int,string>
     */
    private function badge_classes(): array {
        return [
            requests::TREATED_STATUS_UNTREATED => 'bg-warning text-dark',
            requests::TREATED_STATUS_CONFIRMED => 'bg-success',
            requests::TREATED_STATUS_DECLINED => 'bg-secondary',
        ];
    }

    /**
     * Build the template context.
     *
     * @param array $data
     * @return array|null
     */
    protected function build_context(array $data): ?array {
        $entries = is_array($data['requests'] ?? null) ? (array)$data['requests'] : [];
        $total = (int)($data['total'] ?? count($entries));
        $scope = trim((string)($data['scope'] ?? ''));
        $filters = array_values(array_filter(array_map('strval', (array)($data['filters'] ?? [])), 'strlen'));
        $classes = $this->badge_classes();

        $rows = [];
        foreach ($entries as $entry) {
            $entry = (array)$entry;
            $id = (int)($entry['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $treated = (int)($entry['treated'] ?? requests::TREATED_STATUS_UNTREATED);
            $assignmentid = (int)($entry['assignmentid'] ?? 0);
            $userid = (int)($entry['userid'] ?? 0);
            $comment = trim((string)($entry['comment'] ?? ''));
            $timecreated = (int)($entry['timecreated'] ?? 0);
            $rows[] = [
                'id' => $id,
                'fullname' => $this->name($entry['fullname'] ?? ''),
                'userurl' => $userid > 0 ? taskflow_result_link_builder::user_url($userid) : '',
                'typelabel' => $this->esc($entry['typelabel'] ?? ''),
                'assignmentid' => $assignmentid,
                'assignmenturl' => $assignmentid > 0 ? taskflow_result_link_builder::assignment_url($assignmentid) : '',
                'rulename' => $this->name($entry['rulename'] ?? ''),
                'badge' => [
                    'label' => $this->esc($entry['treatedlabel'] ?? ''),
                    'class' => 'badge ' . ($classes[$treated] ?? 'bg-light text-dark'),
                ],
                'receiverlabel' => $this->esc($entry['receiverlabel'] ?? ''),
                'timecreated' => $timecreated > 0
                    ? $this->esc(userdate($timecreated, get_string('strftimedate', 'langconfig')))
                    : '',
                'timecreatedtitle' => $timecreated > 0
                    ? $this->esc(userdate($timecreated, get_string('strftimedatetime', 'langconfig')))
                    : '',
                'comment' => $this->esc($this->truncate($comment, self::COMMENT_LENGTH)),
                'commenttitle' => $this->esc($comment),
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
            'title' => $this->esc($this->str('agent_preview_requests_title', $total)),
            'scopelabel' => $scopelabel,
            'scopeprefix' => $this->esc($this->str('agent_preview_scope')),
            'filters' => $this->esc(implode(' · ', $filters)),
            'hasfilters' => !empty($filters),
            'filtersprefix' => $this->esc($this->str('agent_preview_filters')),
            'rows' => $rows,
            'hasrows' => !empty($rows),
            'emptytext' => $this->esc($this->str('agent_preview_empty_request_list')),
            'showing' => count($rows) < $total
                ? $this->esc($this->str('agent_preview_showing', (object)['shown' => count($rows), 'total' => $total]))
                : '',
            'headers' => [
                'id' => '#',
                'fullname' => $this->esc($this->str('requestinguser')),
                'type' => $this->esc($this->str('agent_preview_requests')),
                'assignment' => $this->esc($this->str('assignment')),
                'receiver' => $this->esc($this->str('agent_preview_receiver')),
                'status' => $this->esc($this->str('status')),
                'timecreated' => $this->esc($this->str('timecreated')),
                'comment' => $this->esc($this->str('comment')),
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
        $entries = is_array($data['requests'] ?? null) ? (array)$data['requests'] : [];
        $ids = static fn(string $key): array => array_values(array_filter(array_map(
            static fn($row): int => (int)(((array)$row)[$key] ?? 0),
            $entries
        )));
        return [
            'requestids' => $ids('id'),
            'assignmentids' => $ids('assignmentid'),
            'userids' => $ids('userid'),
        ];
    }
}
