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
 * Side-pane preview 'taskflow_assignment': full card of one assignment.
 *
 * Data contract (as filled by local_taskflow.get_assignment_details and the mutation skills
 * reusing the type): assignment {id, userid, fullname, ruleid, rulename, status, duedate,
 * assigneddate, overduecounter, prolongedcounter, supervisor{}, caneditassignment, change?},
 * targets[] {targettype, typelabel, targetid, name, completed, url, evidence{}}, open_requests[],
 * history[], chat_enabled, chat_total, chat_preview[], pending_tasks[], links{}.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class taskflow_assignment_preview_renderer extends taskflow_preview_renderer_base {
    /** Preview type. */
    public const PREVIEW_TYPE = taskflow_preview_renderer_factory::TYPE_ASSIGNMENT;

    /**
     * Build the template context.
     *
     * @param array $data
     * @return array|null Null when no assignment id is present.
     */
    protected function build_context(array $data): ?array {
        $assignment = is_array($data['assignment'] ?? null) ? (array)$data['assignment'] : [];
        $id = (int)($assignment['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        $now = time();
        $userid = (int)($assignment['userid'] ?? 0);
        $ruleid = (int)($assignment['ruleid'] ?? 0);
        $supervisor = is_array($assignment['supervisor'] ?? null) ? (array)$assignment['supervisor'] : [];
        $links = is_array($data['links'] ?? null) ? (array)$data['links'] : [];

        $targets = [];
        $done = 0;
        foreach ((array)($data['targets'] ?? []) as $target) {
            $target = (array)$target;
            $completed = !empty($target['completed']);
            $done += $completed ? 1 : 0;
            $evidence = is_array($target['evidence'] ?? null) ? (array)$target['evidence'] : [];
            $name = trim((string)($target['name'] ?? ''));
            $targets[] = [
                'completed' => $completed,
                'glyph' => $completed ? '✓' : '✗',
                'srtext' => $this->esc($this->str($completed ? 'agent_preview_target_done' : 'agent_preview_target_open')),
                'typelabel' => $this->esc($target['typelabel'] ?? ($target['targettype'] ?? '')),
                'name' => $this->name($name !== '' ? $name : '#' . (int)($target['targetid'] ?? 0)),
                'url' => trim((string)($target['url'] ?? '')),
                'evidence' => empty($evidence) ? '' : $this->esc($this->str('agent_preview_evidence') . ': '
                    . (string)($evidence['status'] ?? '')),
            ];
        }

        $requests = [];
        foreach ((array)($data['open_requests'] ?? []) as $request) {
            $request = (array)$request;
            $requests[] = [
                'id' => (int)($request['id'] ?? 0),
                'typelabel' => $this->esc($request['typelabel'] ?? ''),
                'treatedlabel' => $this->esc($request['treatedlabel'] ?? ''),
                'since' => $this->esc($this->date((int)($request['timecreated'] ?? 0))),
                'comment' => $this->esc($this->truncate((string)($request['comment'] ?? ''), 120)),
            ];
        }

        $history = [];
        foreach ((array)($data['history'] ?? []) as $entry) {
            $entry = (array)$entry;
            $history[] = [
                'when' => $this->esc($this->datetime((int)($entry['timecreated'] ?? 0))),
                'typelabel' => $this->esc($entry['typelabel'] ?? ($entry['type'] ?? '')),
                'createdby' => $this->name($entry['createdbyname'] ?? ''),
                'annotation' => $this->esc($this->truncate((string)($entry['annotation'] ?? ''), 160)),
            ];
        }

        $chat = [];
        foreach ((array)($data['chat_preview'] ?? []) as $message) {
            $message = (array)$message;
            $chat[] = [
                'when' => $this->esc($this->datetime((int)($message['timecreated'] ?? 0))),
                'fullname' => $this->name($message['fullname'] ?? ''),
                'text' => $this->esc($this->truncate((string)($message['text'] ?? ''), 200)),
            ];
        }

        $tasks = [];
        foreach ((array)($data['pending_tasks'] ?? []) as $task) {
            $task = (array)$task;
            $tasks[] = [
                'name' => $this->esc($task['name'] ?? ($task['classname'] ?? '')),
                'nextrun' => $this->esc($this->datetime((int)($task['nextruntime'] ?? 0))),
            ];
        }

        $counters = [];
        if ((int)($assignment['prolongedcounter'] ?? 0) > 0) {
            $counters[] = $this->str('agent_preview_prolonged_count', (int)$assignment['prolongedcounter']);
        }
        if ((int)($assignment['overduecounter'] ?? 0) > 0) {
            $counters[] = $this->str('agent_preview_overdue_count', (int)$assignment['overduecounter']);
        }

        $docs = [];
        foreach ((array)($links['docs'] ?? []) as $url) {
            $url = trim((string)$url);
            if ($url !== '') {
                $docs[] = $this->link($url, $this->str('agent_preview_open_docs'));
            }
        }
        if (empty($docs)) {
            $doc = $this->link_docs('assignments_status_lifecycle');
            if ($doc !== null) {
                $docs[] = $doc;
            }
        }
        $editurl = trim((string)($links['edit'] ?? ''));
        if ($editurl === '' && !empty($assignment['caneditassignment'])) {
            $editurl = taskflow_result_link_builder::edit_assignment_url($id);
        }
        $change = trim((string)($assignment['change'] ?? ''));

        return [
            'id' => $id,
            'title' => $this->esc($this->str('assignment')) . ' #' . $id,
            'fullname' => $this->name($assignment['fullname'] ?? ''),
            'userurl' => $userid > 0 ? taskflow_result_link_builder::user_url($userid) : '',
            'badge' => $this->status_badge((int)($assignment['status'] ?? 0)),
            'rulelabel' => $this->esc($this->str('rule')),
            'rulename' => $this->name($assignment['rulename'] ?? ''),
            'ruleurl' => $ruleid > 0 ? taskflow_result_link_builder::edit_rule_url($ruleid) : '',
            'duelabel' => $this->esc($this->str('duedate')),
            'due' => $this->due_chip((int)($assignment['duedate'] ?? 0) ?: null, $now),
            'assignedlabel' => $this->esc($this->str('assigneddate')),
            'assigned' => $this->esc($this->date((int)($assignment['assigneddate'] ?? 0))),
            'supervisorlabel' => $this->esc($this->str('agent_preview_supervisor')),
            'supervisor' => !empty($supervisor['id'])
                ? $this->link_user((int)$supervisor['id'], $this->name($supervisor['fullname'] ?? ''))
                : null,
            'nosupervisor' => $this->esc($this->str('agent_preview_no_supervisor')),
            'counters' => $this->esc(implode(' · ', $counters)),
            'inactive' => array_key_exists('active', $assignment) && empty($assignment['active']),
            'inactivetext' => $this->esc($this->str('activityinactive')),
            'change' => $this->esc($change),
            'changelabel' => $this->esc($this->str('agent_preview_change')),
            'targetslabel' => $this->esc($this->str('targets')),
            'progress' => $this->progress($done, count($targets)),
            'targets' => $targets,
            'hastargets' => !empty($targets),
            'requestslabel' => $this->esc($this->str('agent_preview_requests')),
            'requests' => $requests,
            'hasrequests' => !empty($requests),
            'requestscount' => count($requests),
            'historylabel' => $this->esc($this->str('agent_preview_history')),
            'history' => $history,
            'hashistory' => !empty($history),
            'historycount' => count($history),
            'chatenabled' => !empty($data['chat_enabled']),
            'chatlabel' => $this->esc($this->str('internalcommunication')),
            'chat' => $chat,
            'haschat' => !empty($chat),
            'chattotal' => (int)($data['chat_total'] ?? count($chat)),
            'taskslabel' => $this->esc($this->str('agent_preview_pending_tasks')),
            'tasks' => $tasks,
            'hastasks' => !empty($tasks),
            'taskscount' => count($tasks),
            'open' => $this->link_assignment($id),
            'edit' => $editurl !== '' ? $this->link($editurl, $this->str('agent_preview_edit_assignment')) : null,
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
        $assignment = is_array($data['assignment'] ?? null) ? (array)$data['assignment'] : [];
        return [
            'assignmentids' => [(int)($assignment['id'] ?? 0)],
            'userids' => [(int)($assignment['userid'] ?? 0)],
        ];
    }

    /**
     * Date text ('' when unset).
     *
     * @param int $timestamp
     * @return string
     */
    private function date(int $timestamp): string {
        return $timestamp > 0 ? userdate($timestamp, get_string('strftimedate', 'langconfig')) : '';
    }

    /**
     * Date-time text ('' when unset).
     *
     * @param int $timestamp
     * @return string
     */
    private function datetime(int $timestamp): string {
        return $timestamp > 0 ? userdate($timestamp, get_string('strftimedatetimeshort', 'langconfig')) : '';
    }
}
