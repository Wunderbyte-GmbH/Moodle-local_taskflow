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

/**
 * Side-pane preview of one rule (skill local_taskflow.get_rule_details, concept §2.1/§2.2 #4).
 *
 * Data shape = the payload of get_rule_details_skill: rule{}, filters[], targets[], messages[],
 * requests{}, assignments_by_status[], assignments_total, pending_update_rule_tasks, links{}.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class taskflow_rule_preview_renderer extends taskflow_preview_renderer_base {
    /** Preview type. */
    public const PREVIEW_TYPE = taskflow_preview_renderer_factory::TYPE_RULE;

    /**
     * Template context of the full rule card.
     *
     * @param array $data
     * @return array|null Null when no rule id is present.
     */
    protected function build_context(array $data): ?array {
        $rule = is_array($data['rule'] ?? null) ? (array)$data['rule'] : [];
        $ruleid = (int)($rule['id'] ?? 0);
        if ($ruleid <= 0) {
            return null;
        }

        $active = (bool)($rule['isactive'] ?? false);
        $type = (string)($rule['type'] ?? 'unit') === 'user' ? 'user' : 'unit';
        $unitname = trim((string)($rule['unitname'] ?? ''));
        $unitid = (int)($rule['unitid'] ?? 0);

        $filters = $this->filter_rows((array)($data['filters'] ?? []));
        $targets = $this->target_rows((array)($data['targets'] ?? []));
        $messages = $this->message_rows((array)($data['messages'] ?? []));
        $requests = $this->request_rows((array)($data['requests'] ?? []));
        $stats = $this->status_rows((array)($data['assignments_by_status'] ?? []));
        $pending = (int)($data['pending_update_rule_tasks'] ?? 0);

        $links = [$this->link_rule($ruleid)];
        foreach (['rules', 'rules_filters', 'rules_targets'] as $anchor) {
            $doc = $this->link_docs($anchor);
            if ($doc !== null) {
                $links[] = $doc;
            }
        }

        return [
            'id' => $ruleid,
            'heading' => $this->esc($this->str('agent_preview_rule_heading', $ruleid)),
            'name' => $this->name((string)($rule['name'] ?? '')),
            'description' => $this->esc($this->truncate((string)($rule['description'] ?? ''))),
            'hasdescription' => trim((string)($rule['description'] ?? '')) !== '',
            'url' => $this->link_rule($ruleid)['url'],
            'active' => $active,
            'activelabel' => $this->esc($this->str($active ? 'activityactive' : 'activityinactive')),
            'activeclass' => $active ? 'badge bg-success' : 'badge bg-secondary',
            'typelabel' => $this->esc($this->str('agent_preview_rule_type_' . $type)),
            'hasunit' => $unitid > 0,
            'unitlabel' => $this->esc($this->str('agent_preview_unit')),
            'unitname' => $unitname !== '' ? $this->name($unitname) : $this->esc('#' . $unitid),
            'hasuser' => (int)($rule['userid'] ?? 0) > 0,
            'userid' => (int)($rule['userid'] ?? 0),
            'inheritance' => (bool)($rule['inheritance'] ?? false),
            'inheritancelabel' => $this->esc($this->str('agent_preview_inheritance')),
            'recursive' => (bool)($rule['recursive'] ?? false),
            'recursivelabel' => $this->esc($this->str('agent_preview_recursive')),
            'duedatelabel' => $this->esc($this->str('agent_preview_duedate')),
            'duedatetext' => $this->esc($this->due_date_text($rule)),
            'extensionlabel' => $this->esc($this->str('agent_preview_extensionperiod')),
            'extensiontext' => $this->esc($this->duration_text((int)($rule['extensionperiod'] ?? 0))),
            'cycliclabel' => $this->esc($this->str('agent_preview_cyclic')),
            'cyclictext' => $this->esc($this->cyclic_text($rule)),
            'activationlabel' => $this->esc($this->str('agent_preview_activationdelay')),
            'activationtext' => $this->esc($this->duration_text((int)($rule['activationdelay'] ?? 0))),
            'filterslabel' => $this->esc($this->str('agent_preview_filters')),
            'filters' => $filters,
            'filterscount' => count($filters),
            'hasfilters' => !empty($filters),
            'targetslabel' => $this->esc($this->str('agent_preview_targets')),
            'targets' => $targets,
            'targetscount' => count($targets),
            'hastargets' => !empty($targets),
            'messageslabel' => $this->esc($this->str('agent_preview_messages')),
            'messages' => $messages,
            'messagescount' => count($messages),
            'hasmessages' => !empty($messages),
            'requestslabel' => $this->esc($this->str('agent_preview_requests')),
            'requests' => $requests,
            'hasrequests' => !empty($requests),
            'assignmentslabel' => $this->esc($this->str('agent_preview_assignments')),
            'assignmentstotal' => (int)($data['assignments_total'] ?? array_sum(array_column($stats, 'count'))),
            'stats' => $stats,
            'hasstats' => !empty($stats),
            'haspending' => $pending > 0,
            'pendinglabel' => $this->esc($this->str('agent_preview_pending_tasks')),
            'pendingtext' => $this->esc($this->str('agent_preview_pending_update_rule', $pending)),
            'nonetext' => $this->esc($this->str('agent_preview_none')),
            'links' => $links,
        ];
    }

    /**
     * Rule id for accumulation.
     *
     * @param array $data
     * @return array<string,int[]>
     */
    protected function default_payload(array $data): array {
        $ruleid = (int)(($data['rule'] ?? [])['id'] ?? 0);
        return ['ruleids' => $ruleid > 0 ? [$ruleid] : []];
    }

    /**
     * Readable due-date model.
     *
     * @param array $rule
     * @return string
     */
    private function due_date_text(array $rule): string {
        if ((string)($rule['duedatetype'] ?? '') === 'fixeddate') {
            $fixeddate = (int)($rule['fixeddate'] ?? 0);
            $date = $fixeddate > 0 ? userdate($fixeddate, get_string('strftimedate', 'langconfig')) : '—';
            return $this->str('agent_preview_duedate_fixed', $date);
        }
        return $this->str('agent_preview_duedate_duration', $this->duration_text((int)($rule['duration'] ?? 0)));
    }

    /**
     * Readable cyclic setting.
     *
     * @param array $rule
     * @return string
     */
    private function cyclic_text(array $rule): string {
        if (!(bool)($rule['cyclicvalidation'] ?? false)) {
            return $this->core_string('no');
        }
        return $this->str('agent_preview_cyclic_every', $this->duration_text((int)($rule['cyclicduration'] ?? 0)));
    }

    /**
     * Duration in days/hours (localized units), '0' when unset.
     *
     * @param int $seconds
     * @return string
     */
    private function duration_text(int $seconds): string {
        if ($seconds <= 0) {
            return '0';
        }
        if ($seconds % DAYSECS === 0) {
            return ($seconds / DAYSECS) . ' ' . $this->str('agent_preview_days');
        }
        if ($seconds % HOURSECS === 0) {
            return ($seconds / HOURSECS) . ' ' . $this->str('agent_preview_hours');
        }
        return format_time($seconds);
    }

    /**
     * Core string in the render language.
     *
     * @param string $identifier
     * @return string
     */
    private function core_string(string $identifier): string {
        return get_string_manager()->get_string($identifier, 'core', null, $this->lang === '' ? null : $this->lang);
    }

    /**
     * Filter rows (field, operator label, value/date).
     *
     * @param array $filters
     * @return array
     */
    private function filter_rows(array $filters): array {
        $rows = [];
        foreach ($filters as $filter) {
            if (!is_array($filter)) {
                continue;
            }
            $value = (string)($filter['value'] ?? '');
            $date = (int)($filter['date'] ?? 0);
            if ($value === '' && $date > 0) {
                $value = userdate($date, get_string('strftimedate', 'langconfig'));
            }
            $rows[] = [
                'field' => $this->esc((string)($filter['field'] ?? '')),
                'operator' => $this->esc((string)($filter['operator_label'] ?? ($filter['operator'] ?? ''))),
                'value' => $this->esc($value),
            ];
        }
        return $rows;
    }

    /**
     * Target rows (type label, name, gate flag).
     *
     * @param array $targets
     * @return array
     */
    private function target_rows(array $targets): array {
        $rows = [];
        foreach ($targets as $target) {
            if (!is_array($target)) {
                continue;
            }
            $type = trim((string)($target['targettype'] ?? ''));
            $typelabel = $type !== '' && get_string_manager()->string_exists($type, 'local_taskflow')
                ? $this->str($type)
                : $type;
            $name = trim((string)($target['name'] ?? ''));
            $rows[] = [
                'typelabel' => $this->esc($typelabel),
                'name' => $name !== '' ? $this->name($name) : $this->esc('#' . (int)($target['targetid'] ?? 0)),
                'completebeforenext' => (bool)($target['completebeforenext'] ?? false),
                'gatelabel' => $this->esc($this->str('agent_preview_complete_before_next')),
            ];
        }
        return $rows;
    }

    /**
     * Message template rows.
     *
     * @param array $messages
     * @return array
     */
    private function message_rows(array $messages): array {
        $rows = [];
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            $id = (int)($message['id'] ?? 0);
            $name = trim((string)($message['name'] ?? ''));
            $rows[] = [
                'id' => $id,
                'name' => $name !== '' ? $this->name($name) : $this->esc('#' . $id),
                'class' => $this->esc((string)($message['class'] ?? '')),
                'exists' => (bool)($message['exists'] ?? true),
                'missinglabel' => $this->esc($this->str('agent_preview_message_missing')),
            ];
        }
        return $rows;
    }

    /**
     * Request rows: type label + receiver label.
     *
     * @param array $requests type => not_allowed|supervisor|hr|null
     * @return array
     */
    private function request_rows(array $requests): array {
        $rows = [];
        foreach ($requests as $type => $receiver) {
            $type = (string)$type;
            if ($receiver === null || $receiver === '') {
                continue;
            }
            $receiver = (string)$receiver;
            $receiverkey = in_array($receiver, ['not_allowed', 'supervisor', 'hr'], true) ? $receiver : 'supervisor';
            $rows[] = [
                'label' => $this->esc(
                    get_string_manager()->string_exists($type, 'local_taskflow') ? $this->str($type) : $type
                ),
                'receiver' => $this->esc($this->str('agent_preview_request_' . $receiverkey)),
                'allowed' => $receiverkey !== 'not_allowed',
            ];
        }
        return $rows;
    }

    /**
     * Status badge rows with counts.
     *
     * @param array $bystatus
     * @return array
     */
    private function status_rows(array $bystatus): array {
        $rows = [];
        foreach ($bystatus as $row) {
            if (!is_array($row)) {
                continue;
            }
            $badge = $this->status_badge((int)($row['status'] ?? 0));
            $badge['count'] = (int)($row['count'] ?? 0);
            $rows[] = $badge;
        }
        return $rows;
    }
}
