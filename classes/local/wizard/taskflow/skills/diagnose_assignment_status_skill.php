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

namespace local_taskflow\local\wizard\taskflow\skills;

use local_taskflow\local\actions\targets\targets_factory;
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\wizard\engine\observation_time;
use local_taskflow\local\wizard\engine\skill_risk_class;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\taskflow_input_normalizer;
use local_taskflow\local\wizard\taskflow\taskflow_permission_resolver;
use local_taskflow\local\wizard\taskflow\taskflow_result_link_builder;
use local_taskflow\local\wizard\taskflow\taskflow_settings_catalog;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use stdClass;

/**
 * Read-only skill local_taskflow.diagnose_assignment_status (implementation plan §2 #14).
 *
 * Explains the current status of one assignment out of engine state only: the stored status
 * and its activation flag, the due date against now (naming the adhoc task that changes the
 * status), the counters, the live completion state of every target, and the settings that
 * govern the status machine (allowoverduecompletion, adapter usingprolongedstate/excludestatus,
 * rule extensionperiod, keepchanges) plus the adhoc tasks still queued for the assignment.
 *
 * Completion is probed with the read-only checkers completion_process\types\{moodlecourse,
 * bookingoption}::is_completed(). types\competency::is_completed() is deliberately NOT called:
 * it invalidates the mod_booking answers cache (booking_option::purge_cache_for_answers()) and
 * writes mtrace output on CLI, so it is not side-effect free; for competency targets the stored
 * completionstatus is reported instead and the explanation says so.
 * completion_operator::get_assignment_status() is never called because it writes.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class diagnose_assignment_status_skill extends taskflow_skill_base {
    /** Skill name. */
    public const TASK_NAME = 'local_taskflow.diagnose_assignment_status';

    /** Adhoc task classes (local_taskflow\task\*) that address a single assignment. */
    public const ASSIGNMENT_TASKS = [
        'check_assignment_status',
        'reset_cyclic_assignment',
        'open_planned_assignment',
        'update_assignment',
    ];

    /** Target types whose completion checker is free of side effects and may be probed live. */
    public const LIVE_CHECK_TYPES = ['moodlecourse', 'bookingoption'];

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(true, skill_risk_class::R0);
    }

    /**
     * Skill name.
     *
     * @return string
     */
    public function get_name(): string {
        return self::TASK_NAME;
    }

    /**
     * Input schema.
     *
     * @return array
     */
    protected function define_schema(): array {
        return [
            'version' => 1,
            'description' => 'Explain the status of one assignment: due date versus now, counters, the live '
                . 'completion state of every target, the settings that govern the status machine and the '
                . 'pending adhoc tasks. Read-only, nothing is recalculated or written.',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'Why is assignment 4711 overdue?',
                'Why is assignment 4711 not completed although the course is finished?',
                'Which settings affect the status of assignment 4711?',
                'What happens next with assignment 4711?',
            ],
            'properties' => [
                'assignmentid' => [
                    'type' => 'integer',
                    'description' => 'Id of the assignment (find it with local_taskflow.search_assignments).',
                    'required' => true,
                ],
            ],
            'required' => ['assignmentid'],
        ];
    }

    /**
     * Prompt metadata.
     *
     * @return array<string,mixed>
     */
    protected function prompt_meta(): array {
        return [
            'intent' => 'Explain the status of one identified assignment from stored facts.',
            'input_fields_for_prompt' => ['assignmentid'],
            'anchor_fields' => ['assignmentid'],
        ];
    }

    /**
     * Example input for the planner contract.
     *
     * @return array
     */
    public function get_example_input(): array {
        return ['assignmentid' => 4711];
    }

    /**
     * Structural check: assignmentid is required.
     *
     * @param array $input
     * @return array{valid:bool,errors:string[],ambiguities:string[]}
     */
    public function check_structure(array $input): array {
        $errors = [];
        if ((taskflow_input_normalizer::to_int($input['assignmentid'] ?? null) ?? 0) <= 0) {
            $errors[] = $this->localized_string('agent_assignmentid_required', null, $this->get_output_language($input));
        }
        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => []];
    }

    /**
     * Preflight: assignment must exist and be within scope.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array{status:string,prepared_input:array,issues:array}
     */
    protected function run_preflight(array $input, int $contextid, int $userid): array {
        $lang = $this->get_output_language($input);
        $structure = $this->check_structure($input);
        if (!$structure['valid']) {
            $issues = [];
            foreach ($structure['errors'] as $error) {
                $issues[] = [
                    'code' => 'VALIDATION_ERROR',
                    'severity' => 'needs_clarification',
                    'field' => 'assignmentid',
                    'message' => (string)$error,
                ];
            }
            return $this->invalid($issues);
        }

        $assignmentid = (int)taskflow_input_normalizer::to_int($input['assignmentid']);
        if ($this->resolve_assignment($input) === null) {
            return $this->invalid([
                $this->not_found_issue(
                    self::ISSUE_ASSIGNMENT_NOT_FOUND,
                    $this->localized_string('agent_notfound_assignment', $assignmentid, $lang),
                    ['field' => 'assignmentid']
                ),
            ]);
        }
        if ($this->permissions()->scope_for_assignment($assignmentid, $userid) === taskflow_permission_resolver::SCOPE_NONE) {
            return $this->invalid([$this->scope_denied_issue($lang, ['field' => 'assignmentid'])]);
        }

        $prepared = $input;
        $prepared['assignmentid'] = $assignmentid;
        return $this->pass($prepared);
    }

    /**
     * Execute: collect the facts and turn them into deterministic explanations.
     *
     * @param array $input Prepared input.
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        $lang = $this->get_output_language($input);
        $assignmentid = taskflow_input_normalizer::to_int($input['assignmentid'] ?? null) ?? 0;
        $debug = $this->build_task_debug_message(self::TASK_NAME, $input);

        $data = $this->resolve_assignment(['assignmentid' => $assignmentid]);
        if ($data === null) {
            return $this->error_result(
                self::ISSUE_ASSIGNMENT_NOT_FOUND,
                $this->localized_string('agent_notfound_assignment', $assignmentid, $lang),
                ['debugmessage' => $debug]
            );
        }
        $scope = $this->permissions()->scope_for_assignment($assignmentid, $userid);
        if ($scope === taskflow_permission_resolver::SCOPE_NONE) {
            return $this->error_result(
                self::ISSUE_SCOPE_DENIED,
                $this->localized_string('agent_scope_denied', null, $lang),
                ['debugmessage' => $debug]
            );
        }

        $now = time();
        $statusid = (int)$data->status;
        $duedate = (int)($data->duedate ?? 0);
        $rule = $this->resolve_rule((int)$data->ruleid);
        $targets = $this->build_targets($data, $lang);
        $settings = $this->settings_in_effect($data, $rule, $lang);
        $pending = $this->pending_tasks($assignmentid);

        $status = [
            'id' => $statusid,
            'label' => $this->status_label($statusid, $lang),
            'active' => !empty($data->active),
            'excluded' => assignment_status_facade::check_excluded((string)$statusid),
        ];

        $explanations = $this->build_explanations($data, $status, $duedate, $now, $targets, $settings, $pending, $lang);
        $done = count(array_filter($targets, static fn(array $target): bool => $target['completed_now'] === true));

        $links = $this->links(
            taskflow_result_link_builder::assignment_url($assignmentid),
            ['assignments_status_lifecycle', 'assignments_due_dates', 'assignments_cyclic'],
            $this->permissions()->can_edit_assignment($assignmentid, $userid)
                ? ['edit' => taskflow_result_link_builder::edit_assignment_url($assignmentid)] : []
        );

        $usermessage = $this->localized_string('agent_diagnose_assignment_status_summary', (object)[
            'id' => $assignmentid,
            'fullname' => (string)$data->fullname,
            'status' => $status['label'],
            'done' => $done,
            'targets' => count($targets),
            'explanations' => count($explanations),
        ], $lang);

        $observation = [$usermessage];
        foreach ($explanations as $explanation) {
            $observation[] = '- ' . $explanation['text'];
        }
        foreach ($settings as $setting) {
            $observation[] = sprintf('setting %s = %s', $setting['name'], $setting['value_text']);
        }
        foreach ($pending as $task) {
            $observation[] = 'pending task ' . $task['name'] . ' (next run ' . $task['nextruntime_text'] . ')';
        }

        $checks = $this->build_checks($explanations, $targets, $settings, $lang);

        return $this->base_result(self::STATUS_EXECUTED, [
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'observation_full' => implode("\n", $observation),
            'resultid' => $assignmentid,
            'assignment' => [
                'id' => $assignmentid,
                'userid' => (int)$data->userid,
                'fullname' => (string)$data->fullname,
                'ruleid' => (int)$data->ruleid,
                'rulename' => (string)($data->name !== '' ? $data->name : ($rule['rulename'] ?? '')),
                'duedate' => $duedate,
                'duedate_text' => $this->format_time($duedate),
                'assigneddate' => (int)($data->assigneddate ?? 0),
                'overdue' => $duedate > 0 && $duedate < $now,
            ],
            'assignment_status' => $status,
            'explanations' => $explanations,
            'targets' => $targets,
            'targets_done' => $done,
            'counters' => [
                'overduecounter' => (int)($data->overduecounter ?? 0),
                'prolongedcounter' => (int)($data->prolongedcounter ?? 0),
            ],
            'settings_in_effect' => $settings,
            'pending_tasks' => $pending,
            'scope' => $scope,
            'links' => $links,
            'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, ['Scope: ' . $scope]),
            'preview' => [
                'type' => taskflow_preview_renderer_factory::TYPE_DIAGNOSTIC_CHECKLIST,
                'data' => [
                    'title' => $this->localized_string('agent_preview_diagnosis_title', (object)[
                        'subject' => (string)$data->fullname,
                        'object' => $this->localized_string('agent_preview_assignment_heading', $assignmentid, $lang),
                    ], $lang),
                    'status' => $statusid,
                    'rows' => $checks,
                    'links' => $links,
                    'ids' => ['assignmentids' => [$assignmentid], 'userids' => [(int)$data->userid]],
                ],
                'payload' => ['assignmentids' => [$assignmentid], 'userids' => [(int)$data->userid]],
            ],
        ]);
    }

    /**
     * Targets with stored and (where side-effect free) live completion state.
     *
     * @param stdClass $data
     * @param string $lang
     * @return array<int,array<string,mixed>>
     */
    private function build_targets(stdClass $data, string $lang): array {
        $decoded = json_decode((string)($data->targets ?? ''), true);
        if (!is_array($decoded)) {
            return [];
        }
        $targets = [];
        foreach ($decoded as $target) {
            $target = (array)$target;
            $type = (string)($target['targettype'] ?? '');
            $targetid = (int)($target['targetid'] ?? 0);
            $stored = (int)($target['completionstatus'] ?? 0) === 1;
            $live = $this->live_completion($type, $targetid, (int)$data->userid, $data);
            $name = '';
            try {
                $name = (string)targets_factory::get_name($type, $targetid);
            } catch (\Throwable $e) {
                $name = '';
            }
            if ($name === '') {
                $name = (string)($target['targetname'] ?? '');
            }
            $targets[] = [
                'targettype' => $type,
                'typelabel' => get_string_manager()->string_exists($type, 'local_taskflow')
                    ? $this->localized_string($type, null, $lang) : $type,
                'targetid' => $targetid,
                'name' => $name,
                'completionstatus' => $stored ? 1 : 0,
                'completed_stored' => $stored,
                'completed_now' => $live,
                'live_checked' => $live !== null,
                'completebeforenext' => !empty($target['completebeforenext']),
                'sortorder' => (int)($target['sortorder'] ?? 0),
            ];
        }
        return $targets;
    }

    /**
     * Live completion probe for the side-effect free target types.
     *
     * @param string $type
     * @param int $targetid
     * @param int $userid
     * @param stdClass $assignment
     * @return bool|null Null when the type is not probed live (competency) or the probe failed.
     */
    private function live_completion(string $type, int $targetid, int $userid, stdClass $assignment): ?bool {
        if ($targetid <= 0 || !in_array($type, self::LIVE_CHECK_TYPES, true)) {
            return null;
        }
        $class = '\\local_taskflow\\local\\completion_process\\types\\' . $type;
        if (!class_exists($class)) {
            return null;
        }
        try {
            $checker = new $class($targetid, $userid, $type);
            return (bool)$checker->is_completed($assignment);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The settings that govern the status machine for this assignment.
     *
     * @param stdClass $data
     * @param array $rule
     * @param string $lang
     * @return array<int,array<string,mixed>>
     */
    private function settings_in_effect(stdClass $data, array $rule, string $lang): array {
        $adapter = taskflow_settings_catalog::active_adapter();
        $component = 'taskflowadapter_' . $adapter;
        $excluded = [];
        foreach (array_filter(array_map('trim', explode(',', (string)get_config($component, 'excludestatus')))) as $id) {
            if (is_numeric($id)) {
                $excluded[] = ['id' => (int)$id, 'label' => $this->status_label((int)$id, $lang)];
            }
        }
        $allowoverdue = !empty(get_config('local_taskflow', 'allowoverduecompletion'));
        $prolongedstate = !empty(get_config($component, 'usingprolongedstate'));
        $extensionperiod = (int)($rule['rule']['extensionperiod'] ?? 0);
        $keepchanges = !empty($data->keepchanges);

        return [
            [
                'name' => 'local_taskflow/allowoverduecompletion',
                'value' => $allowoverdue,
                'value_text' => get_string($allowoverdue ? 'yes' : 'no'),
            ],
            [
                'name' => $component . '/usingprolongedstate',
                'value' => $prolongedstate,
                'value_text' => get_string($prolongedstate ? 'yes' : 'no'),
            ],
            [
                'name' => $component . '/excludestatus',
                'value' => array_column($excluded, 'id'),
                'value_text' => empty($excluded)
                    ? $this->localized_string('agent_preview_none', null, $lang)
                    : implode(', ', array_column($excluded, 'label')),
            ],
            [
                'name' => 'rule/extensionperiod',
                'value' => $extensionperiod,
                'value_text' => $extensionperiod > 0 ? format_time($extensionperiod)
                    : $this->localized_string('agent_preview_none', null, $lang),
            ],
            [
                'name' => 'assignment/keepchanges',
                'value' => $keepchanges,
                'value_text' => get_string($keepchanges ? 'yes' : 'no'),
            ],
        ];
    }

    /**
     * Deterministic explanation sentences built from the collected facts.
     *
     * @param stdClass $data
     * @param array $status
     * @param int $duedate
     * @param int $now
     * @param array $targets
     * @param array $settings
     * @param array $pending
     * @param string $lang
     * @return array<int,array{code:string,text:string,severity:string}>
     */
    private function build_explanations(
        stdClass $data,
        array $status,
        int $duedate,
        int $now,
        array $targets,
        array $settings,
        array $pending,
        string $lang
    ): array {
        $explanations = [];
        $explanations[] = $this->explanation('status', 'ok', 'agent_explain_status', (object)[
            'label' => $status['label'],
            'id' => $status['id'],
            'active' => get_string($status['active'] ? 'yes' : 'no'),
        ], $lang);

        if ($duedate <= 0) {
            $explanations[] = $this->explanation('duedate_none', 'warn', 'agent_explain_no_duedate', null, $lang);
        } else if ($duedate < $now) {
            $explanations[] = $this->explanation(
                'duedate_passed',
                'fail',
                'agent_explain_duedate_passed',
                $this->format_time($duedate),
                $lang
            );
        } else {
            $explanations[] = $this->explanation(
                'duedate_future',
                'ok',
                'agent_explain_duedate_future',
                $this->format_time($duedate),
                $lang
            );
        }

        $explanations[] = $this->explanation('counters', 'ok', 'agent_explain_counters', (object)[
            'overdue' => (int)($data->overduecounter ?? 0),
            'prolonged' => (int)($data->prolongedcounter ?? 0),
        ], $lang);

        $done = count(array_filter($targets, static fn(array $target): bool => $target['completed_now'] === true));
        $explanations[] = $this->explanation('targets', 'ok', 'agent_explain_targets', (object)[
            'done' => $done,
            'total' => count($targets),
        ], $lang);
        foreach ($targets as $target) {
            $label = $target['name'] !== '' ? $target['name'] : ($target['typelabel'] . ' #' . $target['targetid']);
            if (!$target['live_checked']) {
                $explanations[] = $this->explanation(
                    'target_not_checked',
                    'warn',
                    'agent_explain_target_notchecked',
                    (object)['name' => $label, 'stored' => get_string($target['completed_stored'] ? 'yes' : 'no')],
                    $lang
                );
                continue;
            }
            if ($target['completed_stored'] !== $target['completed_now']) {
                $explanations[] = $this->explanation(
                    'target_mismatch',
                    'fail',
                    'agent_explain_target_mismatch',
                    (object)[
                        'name' => $label,
                        'stored' => get_string($target['completed_stored'] ? 'yes' : 'no'),
                        'live' => get_string($target['completed_now'] ? 'yes' : 'no'),
                    ],
                    $lang
                );
            }
        }

        $keyed = array_column($settings, null, 'name');
        $keepchanges = !empty($keyed['assignment/keepchanges']['value']);
        $explanations[] = $this->explanation(
            'keepchanges',
            $keepchanges ? 'warn' : 'ok',
            $keepchanges ? 'agent_explain_keepchanges' : 'agent_explain_keepchanges_off',
            null,
            $lang
        );
        $allowoverdue = !empty($keyed['local_taskflow/allowoverduecompletion']['value']);
        $explanations[] = $this->explanation(
            'allowoverduecompletion',
            'ok',
            $allowoverdue ? 'agent_explain_allowoverduecompletion_on' : 'agent_explain_allowoverduecompletion_off',
            null,
            $lang
        );
        $adapter = taskflow_settings_catalog::active_adapter();
        $prolonged = !empty($keyed['taskflowadapter_' . $adapter . '/usingprolongedstate']['value']);
        $extensionperiod = (int)($keyed['rule/extensionperiod']['value'] ?? 0);
        $explanations[] = $this->explanation(
            'prolongedstate',
            'ok',
            $prolonged && $extensionperiod > 0 ? 'agent_explain_prolongedstate_on' : 'agent_explain_prolongedstate_off',
            (object)['adapter' => $adapter, 'period' => format_time(max(0, $extensionperiod))],
            $lang
        );
        $excluded = (string)($keyed['taskflowadapter_' . $adapter . '/excludestatus']['value_text'] ?? '');
        $explanations[] = $this->explanation(
            'excludestatus',
            empty($keyed['taskflowadapter_' . $adapter . '/excludestatus']['value']) ? 'ok' : 'warn',
            empty($keyed['taskflowadapter_' . $adapter . '/excludestatus']['value'])
                ? 'agent_explain_excludestatus_none' : 'agent_explain_excludestatus',
            (object)['adapter' => $adapter, 'statuses' => $excluded],
            $lang
        );
        if ($status['excluded']) {
            $explanations[] = $this->explanation(
                'status_excluded',
                'warn',
                'agent_explain_status_excluded',
                $status['label'],
                $lang
            );
        }

        if (empty($pending)) {
            $explanations[] = $this->explanation('pending_none', 'ok', 'agent_explain_no_pending_tasks', null, $lang);
        }
        foreach ($pending as $task) {
            $explanations[] = $this->explanation('pending_task', 'warn', 'agent_explain_pending_task', (object)[
                'name' => $task['name'],
                'time' => $task['nextruntime_text'],
            ], $lang);
        }

        return $explanations;
    }

    /**
     * One explanation entry.
     *
     * @param string $code
     * @param string $severity ok|warn|fail
     * @param string $key Lang key.
     * @param mixed $a
     * @param string $lang
     * @return array{code:string,text:string,severity:string}
     */
    private function explanation(string $code, string $severity, string $key, $a, string $lang): array {
        return ['code' => $code, 'severity' => $severity, 'text' => $this->localized_string($key, $a, $lang)];
    }

    /**
     * Checklist preview rows: explanations plus the settings block.
     *
     * @param array $explanations
     * @param array $targets
     * @param array $settings
     * @param string $lang
     * @return array<int,array<string,mixed>>
     */
    private function build_checks(array $explanations, array $targets, array $settings, string $lang): array {
        $rows = [];
        foreach ($explanations as $explanation) {
            $rows[] = [
                'status' => $explanation['severity'],
                'check' => $explanation['text'],
                'detail' => '',
                'url' => '',
            ];
        }
        foreach ($targets as $target) {
            $rows[] = [
                'status' => $target['completed_now'] === true ? 'ok' : ($target['completed_now'] === null ? 'warn' : 'fail'),
                'check' => $this->localized_string('agent_check_target', $target['name'] !== ''
                    ? $target['name'] : ($target['typelabel'] . ' #' . $target['targetid']), $lang),
                'detail' => $target['typelabel'],
                'url' => '',
            ];
        }
        foreach ($settings as $setting) {
            $rows[] = [
                'status' => 'warn',
                'check' => $setting['name'],
                'detail' => $setting['value_text'],
                'url' => '',
            ];
        }
        return $rows;
    }

    /**
     * Pending adhoc tasks of local_taskflow addressing this assignment.
     *
     * @param int $assignmentid
     * @return array<int,array<string,mixed>>
     */
    private function pending_tasks(int $assignmentid): array {
        global $DB;

        $classnames = array_map(
            static fn(string $name): string => '\\local_taskflow\\task\\' . $name,
            self::ASSIGNMENT_TASKS
        );
        [$insql, $params] = $DB->get_in_or_equal($classnames, SQL_PARAMS_NAMED, 'cls');
        $records = $DB->get_records_select(
            'task_adhoc',
            "classname {$insql}",
            $params,
            'nextruntime ASC, id ASC',
            'id, classname, customdata, nextruntime, timestarted, faildelay'
        );
        $tasks = [];
        foreach ($records as $record) {
            $customdata = json_decode((string)$record->customdata, true);
            if (!is_array($customdata) || (int)($customdata['assignmentid'] ?? 0) !== $assignmentid) {
                continue;
            }
            $tasks[] = [
                'id' => (int)$record->id,
                'classname' => (string)$record->classname,
                'name' => ltrim(substr((string)$record->classname, strrpos((string)$record->classname, '\\')), '\\'),
                'nextruntime' => (int)$record->nextruntime,
                'nextruntime_text' => $this->format_time((int)$record->nextruntime),
                'started' => !empty($record->timestarted),
            ];
        }
        return $tasks;
    }

    /**
     * Timezone-adjusted date text ('' when unset).
     *
     * @param int $timestamp
     * @return string
     */
    private function format_time(int $timestamp): string {
        if ($timestamp <= 0) {
            return '';
        }
        if (class_exists(observation_time::class)) {
            return (string)observation_time::format($timestamp);
        }
        return userdate($timestamp, get_string('strftimedatetime', 'langconfig'));
    }
}
