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

use context_system;
use local_taskflow\local\actions\targets\targets_factory;
use local_taskflow\local\operators\string_compare_operators;
use local_taskflow\local\units\organisational_unit_factory;
use local_taskflow\local\wizard\engine\skill_risk_class;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\taskflow_input_normalizer;
use local_taskflow\local\wizard\taskflow\taskflow_result_link_builder;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;

/**
 * Skill local_taskflow.get_rule_details: full decode of one rule (implementation plan §2 #4).
 *
 * Read-only, R0, native capability local/taskflow:viewrules. Returns the scalar rule fields,
 * filters, targets (names via targets_factory), referenced message templates, request receivers,
 * assignment statistics per status and pending update_rule adhoc tasks.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_rule_details_skill extends taskflow_skill_base {
    /** Skill name. */
    public const TASK_NAME = 'local_taskflow.get_rule_details';

    /** Native capability required to read rules. */
    public const CAPABILITY = 'local/taskflow:viewrules';

    /** Adhoc task class that propagates a saved rule into assignments. */
    public const UPDATE_RULE_TASK = 'local_taskflow\task\update_rule';

    /** Request receiver value: request type disabled for this rule. */
    public const RECEIVER_NOT_ALLOWED = 'not_allowed';
    /** Request receiver value: supervisor. */
    public const RECEIVER_SUPERVISOR = 'supervisor';
    /** Request receiver value: HR. */
    public const RECEIVER_HR = 'hr';

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(true, skill_risk_class::R0, [self::CAPABILITY]);
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
            'description' => 'Show the full configuration of ONE taskflow rule. It covers name, unit or person, due-date model '
                . '(duration or fixed date), extension period, cyclic repetition, activation delay, inheritance, '
                . 'filters, targets (courses, booking options, competencies), message templates, self-service request '
                . 'settings, the number of assignments per status and pending propagation tasks. Requires the rule id '
                . '(use search_rules to find it).',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'Show me the details of rule 17',
                'What does taskflow rule 17 assign?',
                'Which filters and targets does the data protection rule have?',
                'How many assignments does rule 17 have per status?',
                'When is rule 17 due and is it cyclic?',
            ],
            'properties' => [
                'ruleid' => [
                    'type' => 'integer',
                    'description' => 'Id of the taskflow rule.',
                    'required' => true,
                ],
            ],
            'required' => ['ruleid'],
        ];
    }

    /**
     * Prompt metadata.
     *
     * @return array<string,mixed>
     */
    protected function prompt_meta(): array {
        return [
            'intent' => 'Explain the complete configuration and assignment statistics of one taskflow rule.',
        ];
    }

    /**
     * Example input.
     *
     * @return array
     */
    public function get_example_input(): array {
        return ['ruleid' => 17];
    }

    /**
     * Structural validation (no DB access).
     *
     * @param array $input
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>}
     */
    public function check_structure(array $input): array {
        $errors = [];
        $ruleid = taskflow_input_normalizer::to_int($input['ruleid'] ?? null);
        if ($ruleid === null || $ruleid <= 0) {
            $errors[] = $this->localized_string('agent_invalid_ruleid', null, $this->get_output_language($input));
        }
        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => []];
    }

    /**
     * Preflight: capability gate, structure check, rule existence.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array{status:string,prepared_input:array,issues:array}
     */
    protected function run_preflight(array $input, int $contextid, int $userid): array {
        $lang = $this->get_output_language($input);
        if (!has_capability(self::CAPABILITY, context_system::instance(), $userid)) {
            return $this->invalid([$this->scope_denied_issue($lang, ['field' => 'ruleid'])]);
        }

        $structure = $this->check_structure($input);
        if (!($structure['valid'] ?? false)) {
            $issues = [];
            foreach ((array)($structure['errors'] ?? []) as $error) {
                $issues[] = [
                    'code' => 'VALIDATION_ERROR',
                    'severity' => 'needs_clarification',
                    'field' => 'ruleid',
                    'message' => (string)$error,
                ];
            }
            return $this->invalid($issues);
        }

        $ruleid = (int)taskflow_input_normalizer::to_int($input['ruleid']);
        if (empty($this->resolve_rule($ruleid))) {
            return $this->invalid([
                $this->not_found_issue(
                    self::ISSUE_RULE_NOT_FOUND,
                    $this->localized_string('agent_notfound_rule', $ruleid, $lang),
                    ['field' => 'ruleid']
                ),
            ]);
        }

        $input['ruleid'] = $ruleid;
        return $this->pass($input);
    }

    /**
     * Execute: decode the rule and assemble the detail payload.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        $lang = $this->get_output_language($input);
        if (!has_capability(self::CAPABILITY, context_system::instance(), $userid)) {
            return $this->error_result(
                self::ISSUE_SCOPE_DENIED,
                $this->localized_string('agent_scope_denied', null, $lang),
                ['links' => $this->links(null, ['rules'])]
            );
        }

        $ruleid = (int)(taskflow_input_normalizer::to_int($input['ruleid'] ?? null) ?? 0);
        $resolved = $this->resolve_rule($ruleid);
        if (empty($resolved)) {
            return $this->error_result(
                self::ISSUE_RULE_NOT_FOUND,
                $this->localized_string('agent_notfound_rule', $ruleid, $lang),
                ['links' => $this->links(taskflow_result_link_builder::dashboard_url(), ['rules'])]
            );
        }

        $document = $resolved['rule'];
        $action = $this->first_action($document);

        $rule = $this->scalar_fields($resolved);
        $filters = $this->decode_filters((array)($document['filter'] ?? []));
        $targets = $this->decode_targets((array)($action['targets'] ?? []));
        $messages = $this->decode_messages((array)($action['messages'] ?? []));
        $requests = $this->decode_requests((array)($action['requests'] ?? []));
        $bystatus = $this->assignments_by_status($ruleid, $lang);
        $pending = $this->pending_update_rule_tasks($ruleid);

        $links = $this->links(
            taskflow_result_link_builder::edit_rule_url($ruleid),
            ['rules', 'rules_rule_step', 'rules_filters', 'rules_targets', 'rules_messages_step', 'rules_requests_step'],
            ['dashboard' => taskflow_result_link_builder::dashboard_url()]
        );

        $assignmentstotal = array_sum(array_map(static fn(array $row): int => (int)$row['count'], $bystatus));
        $usermessage = $this->localized_string('agent_get_rule_details_summary', (object)[
            'id' => $ruleid,
            'name' => $rule['name'],
            'targets' => count($targets),
            'filters' => count($filters),
            'assignments' => $assignmentstotal,
        ], $lang);

        $payload = [
            'rule' => $rule,
            'filters' => $filters,
            'targets' => $targets,
            'messages' => $messages,
            'requests' => $requests,
            'assignments_by_status' => $bystatus,
            'assignments_total' => $assignmentstotal,
            'pending_update_rule_tasks' => $pending,
            'links' => $links,
        ];

        $debug = $this->build_task_debug_message(self::TASK_NAME, $input, [
            'Targets: ' . count($targets) . ', filters: ' . count($filters) . ', messages: ' . count($messages),
            'Assignments: ' . $assignmentstotal . ', pending update_rule tasks: ' . $pending,
        ]);

        return $this->base_result(self::STATUS_EXECUTED, $payload + [
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'observation_full' => $this->build_observation_full($usermessage, $payload),
            'resultid' => $ruleid,
            'debugmessage' => $debug,
            'outputlang' => $lang,
            'preview' => [
                'type' => taskflow_preview_renderer_factory::TYPE_RULE,
                'data' => $payload,
                'payload' => ['ruleids' => [$ruleid]],
            ],
        ]);
    }

    /**
     * The single action block of the rule (forms write actions[0]; the runtime reads the last one).
     *
     * @param array $document rulejson.rule
     * @return array
     */
    private function first_action(array $document): array {
        $actions = array_values(array_filter((array)($document['actions'] ?? []), 'is_array'));
        return empty($actions) ? [] : (array)end($actions);
    }

    /**
     * Scalar rule fields (row + document).
     *
     * @param array $resolved resolve_rule() result
     * @return array
     */
    private function scalar_fields(array $resolved): array {
        $document = $resolved['rule'];
        $unitid = (int)$resolved['unitid'];
        $userid = (int)$resolved['userid'];
        $type = $userid > 0 && $unitid <= 0 ? 'user' : 'unit';

        $duedatetype = strtolower(trim((string)($document['duedatetype'] ?? '')));
        if (!in_array($duedatetype, ['duration', 'fixeddate'], true)) {
            $duedatetype = 'fixeddate';
        }

        return [
            'id' => (int)$resolved['id'],
            'name' => $resolved['rulename'] !== '' ? $resolved['rulename'] : (string)($document['name'] ?? ''),
            'description' => (string)($document['description'] ?? ''),
            'type' => $type,
            'unitid' => $unitid,
            'unitname' => $unitid > 0 ? $this->unit_name($unitid) : '',
            'userid' => $userid,
            'enabled' => $this->flag($document['enabled'] ?? 1),
            'isactive' => (bool)$resolved['isactive'],
            'duedatetype' => $duedatetype,
            'duration' => (int)($document['duration'] ?? 0),
            'fixeddate' => (int)($document['fixeddate'] ?? 0),
            'extensionperiod' => (int)($document['extensionperiod'] ?? 0),
            'activationdelay' => (int)($document['activationdelay'] ?? 0),
            'cyclicvalidation' => $this->flag($document['cyclicvalidation'] ?? 0),
            'cyclicduration' => (int)($document['cyclicduration'] ?? 0),
            'inheritance' => $this->flag($document['inheritance'] ?? 0),
            'recursive' => $this->flag($document['recursive'] ?? 0),
            'timecreated' => (int)($document['timecreated'] ?? 0),
            'timemodified' => (int)($document['timemodified'] ?? 0),
            'usermodified' => (int)($document['usermodified'] ?? 0),
        ];
    }

    /**
     * Loose flag decoding (readers compare with == '1').
     *
     * @param mixed $value
     * @return bool
     */
    private function flag($value): bool {
        return $value == '1';
    }

    /**
     * Filter rows with operator labels.
     *
     * @param array $filters
     * @return array
     */
    private function decode_filters(array $filters): array {
        $operatorlabels = (new string_compare_operators())->get_operator_keys_and_values();
        $rows = [];
        foreach ($filters as $filter) {
            if (!is_array($filter)) {
                continue;
            }
            $filtertype = (string)($filter['filtertype'] ?? '');
            $field = (string)($filter['userprofilefield'] ?? ($filter['userfield'] ?? ''));
            $operator = (string)($filter['operator'] ?? '');
            $rows[] = [
                'filtertype' => $filtertype,
                'field' => $field,
                'operator' => $operator,
                'operator_label' => (string)($operatorlabels[$operator] ?? $operator),
                'value' => (string)($filter['value'] ?? ''),
                'date' => (int)($filter['date'] ?? 0),
            ];
        }
        return $rows;
    }

    /**
     * Target rows with resolved names (stored targetname as fallback).
     *
     * @param array $targets
     * @return array
     */
    private function decode_targets(array $targets): array {
        $rows = [];
        foreach ($targets as $target) {
            if (!is_array($target)) {
                continue;
            }
            $targettype = (string)($target['targettype'] ?? '');
            $targetid = (int)($target['targetid'] ?? 0);
            $name = '';
            if ($targettype !== '' && $targetid > 0) {
                try {
                    $name = (string)targets_factory::get_name($targettype, $targetid);
                } catch (\Throwable $e) {
                    $name = '';
                }
            }
            if ($name === '') {
                $name = (string)($target['targetname'] ?? '');
            }
            $rows[] = [
                'targettype' => $targettype,
                'targetid' => $targetid,
                'name' => $name,
                'completebeforenext' => $this->flag($target['completebeforenext'] ?? 0),
                'sortorder' => (int)($target['sortorder'] ?? 0),
                'actiontype' => (string)($target['actiontype'] ?? ''),
            ];
        }
        return $rows;
    }

    /**
     * Referenced message templates (id, name, class); missing templates are flagged.
     *
     * @param array $messages
     * @return array
     */
    private function decode_messages(array $messages): array {
        global $DB;

        $ids = [];
        foreach ($messages as $message) {
            $id = is_array($message) ? (int)($message['messageid'] ?? 0) : (int)$message;
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        if (empty($ids)) {
            return [];
        }

        $records = $DB->get_records_list('local_taskflow_messages', 'id', $ids, '', 'id, name, class');
        $rows = [];
        foreach ($ids as $id) {
            $record = $records[$id] ?? null;
            $rows[] = [
                'id' => $id,
                'name' => $record ? (string)$record->name : '',
                'class' => $record ? (string)$record->class : '',
                'exists' => (bool)$record,
            ];
        }
        return $rows;
    }

    /**
     * Request receiver per request type: not_allowed | supervisor | hr | null (globally disabled).
     *
     * Keys are the SETTINGKEY constants of local\requests\request_types\types\*.
     *
     * @param array $requests actions[0].requests
     * @return array<string,string|null>
     */
    private function decode_requests(array $requests): array {
        $result = [];
        foreach ($this->request_type_keys() as $type) {
            $raw = $requests['receiver_' . $type] ?? null;
            if ($raw === null) {
                $result[$type] = null;
                continue;
            }
            $raw = (string)$raw;
            if ($raw === self::RECEIVER_NOT_ALLOWED) {
                $result[$type] = self::RECEIVER_NOT_ALLOWED;
            } else if ($raw === '1') {
                $result[$type] = self::RECEIVER_HR;
            } else {
                $result[$type] = self::RECEIVER_SUPERVISOR;
            }
        }
        return $result;
    }

    /**
     * SETTINGKEY of every request type class.
     *
     * @return string[]
     */
    private function request_type_keys(): array {
        global $CFG;

        $keys = [];
        $dir = $CFG->dirroot . '/local/taskflow/classes/local/requests/request_types/types';
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $class = 'local_taskflow\\local\\requests\\request_types\\types\\' . basename($file, '.php');
            if (class_exists($class) && defined($class . '::SETTINGKEY')) {
                $keys[] = (string)constant($class . '::SETTINGKEY');
            }
        }
        sort($keys);
        return $keys;
    }

    /**
     * Assignment counts per status (labels via assignment_status_facade).
     *
     * @param int $ruleid
     * @param string $lang
     * @return array<int,array{status:int,label:string,count:int}>
     */
    private function assignments_by_status(int $ruleid, string $lang): array {
        global $DB;

        $rows = $DB->get_records_sql(
            "SELECT status, COUNT(id) AS assignments
               FROM {local_taskflow_assignment}
              WHERE ruleid = :ruleid
           GROUP BY status
           ORDER BY status",
            ['ruleid' => $ruleid]
        );

        $result = [];
        foreach ($rows as $row) {
            $status = (int)$row->status;
            $result[] = [
                'status' => $status,
                'label' => $this->status_label($status, $lang),
                'count' => (int)$row->assignments,
            ];
        }
        return $result;
    }

    /**
     * Number of queued update_rule adhoc tasks whose custom data names this rule.
     *
     * @param int $ruleid
     * @return int
     */
    private function pending_update_rule_tasks(int $ruleid): int {
        global $DB;

        $tasks = $DB->get_records_list(
            'task_adhoc',
            'classname',
            ['\\' . self::UPDATE_RULE_TASK, self::UPDATE_RULE_TASK],
            '',
            'id, customdata'
        );

        $pending = 0;
        foreach ($tasks as $task) {
            $data = json_decode((string)$task->customdata, true);
            if (is_array($data) && (int)($data['id'] ?? 0) === $ruleid) {
                $pending++;
            }
        }
        return $pending;
    }

    /**
     * Name of an organisational unit ('' when unresolvable).
     *
     * @param int $unitid
     * @return string
     */
    private function unit_name(int $unitid): string {
        try {
            $unit = organisational_unit_factory::instance($unitid);
        } catch (\Throwable $e) {
            return '';
        }
        return is_object($unit) && method_exists($unit, 'get_name') ? (string)$unit->get_name() : '';
    }

    /**
     * Observation payload for follow-up reasoning steps (message + JSON).
     *
     * @param string $usermessage
     * @param array $payload
     * @return string
     */
    private function build_observation_full(string $usermessage, array $payload): string {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || $json === '') {
            return $usermessage;
        }
        return $usermessage . "\n\nRule payload (JSON):\n" . $json;
    }
}
