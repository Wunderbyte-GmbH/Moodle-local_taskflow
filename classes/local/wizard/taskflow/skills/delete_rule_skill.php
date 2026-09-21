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
use core\task\manager;
use local_taskflow\local\wizard\engine\queue_identity_provider_interface;
use local_taskflow\local\wizard\engine\skill_risk_class;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\taskflow_input_normalizer;
use local_taskflow\local\wizard\taskflow\taskflow_result_link_builder;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use local_taskflow\task\removed_rule;

/**
 * Skill local_taskflow.delete_rule: queue the deletion of one rule (implementation plan §2 #20).
 *
 * R3 (irreversible), capability local/taskflow:createrules. The deletion runs exactly as the
 * rules table does it (rules_table::action_deleterule()): the adhoc task
 * local_taskflow\task\removed_rule is queued with the rule id as custom data, and that task
 * removes the rule together with all its assignments at the next cron run. The skill therefore
 * reports status 'queued' with the adhoc row as evidence and never claims that anything has
 * been deleted already.
 *
 * The destructive step is gated by a structured, two-stage confirmation and never by matching
 * any phrase (hard rule: no lexical detection). The first preflight of a rule that still owns
 * assignments returns confirmable() with the remedy option CONFIRM_DELETE_WITH_ASSIGNMENTS and
 * the exact number of assignments that will disappear with it; execution proceeds only when
 * that token is present in the structured 'override' list.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class delete_rule_skill extends taskflow_skill_base implements queue_identity_provider_interface {
    /** Skill name. */
    public const TASK_NAME = 'local_taskflow.delete_rule';

    /** Native capability required to delete rules. */
    public const CAPABILITY = 'local/taskflow:createrules';

    /** Adhoc task performing the deletion (queued, executed by cron). */
    public const REMOVED_RULE_TASK = 'local_taskflow\task\removed_rule';

    /** Structured override token confirming the deletion including its assignments. */
    public const OVERRIDE_CONFIRM_DELETE = 'CONFIRM_DELETE_WITH_ASSIGNMENTS';

    /** Issue code: the deletion awaits the structured confirmation token. */
    public const ISSUE_CONFIRM_REQUIRED = 'TASKFLOW_DELETE_RULE_CONFIRM_REQUIRED';

    /** Issue code: execute() was called without the confirmation token. */
    public const ISSUE_OVERRIDE_MISSING = 'TASKFLOW_DELETE_RULE_OVERRIDE_MISSING';

    /** Issue code: the task could not be queued. */
    public const ISSUE_TASK_NOT_QUEUED = 'TASKFLOW_REMOVED_RULE_NOT_QUEUED';

    /**
     * Constructor: mutating, irreversible, guarded by local/taskflow:createrules.
     */
    public function __construct() {
        parent::__construct(false, skill_risk_class::R3, [self::CAPABILITY]);
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
            'description' => 'Delete one taskflow rule together with all assignments created by it. '
                . 'The deletion is queued as an adhoc task and performed at the next cron run; it '
                . 'cannot be undone. The first call reports how many assignments are affected and '
                . 'requires the override token ' . self::OVERRIDE_CONFIRM_DELETE . ' to proceed.',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'Delete taskflow rule 17',
                'Remove the rule "Data protection basics" and everything assigned by it',
                'Rule 21 is obsolete, get rid of it',
            ],
            'properties' => [
                'ruleid' => [
                    'type' => 'integer',
                    'description' => 'Id of the rule to delete (use local_taskflow.search_rules to find it).',
                    'required' => true,
                ],
                'override' => [
                    'type' => 'array',
                    'description' => 'Structured override tokens for confirmed exceptions. Add '
                        . self::OVERRIDE_CONFIRM_DELETE . ' only after the user confirmed the reported '
                        . 'number of assignments that are deleted together with the rule.',
                    'required' => false,
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
            'intent' => 'Queue the irreversible deletion of one taskflow rule and its assignments.',
            'input_fields_for_prompt' => ['ruleid', 'override'],
            'anchor_fields' => ['ruleid'],
        ];
    }

    /**
     * Example input.
     *
     * @return array
     */
    public function get_example_input(): array {
        return ['ruleid' => 17, 'override' => [self::OVERRIDE_CONFIRM_DELETE]];
    }

    /**
     * Queue identity: deletions of the same rule deduplicate.
     *
     * @param array $input
     * @return array<string,mixed>
     */
    public function build_queue_business_identity(array $input): array {
        return [
            'task_family' => create_rule_skill::QUEUE_TASK_FAMILY,
            'skill' => self::TASK_NAME,
            'ruleid' => (int)(taskflow_input_normalizer::to_int($input['ruleid'] ?? null) ?? 0),
        ];
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
     * Preflight: capability, rule existence, then the structured two-stage confirmation.
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
        $resolved = $this->resolve_rule($ruleid);
        if (empty($resolved)) {
            return $this->invalid([
                $this->not_found_issue(
                    self::ISSUE_RULE_NOT_FOUND,
                    $this->localized_string('agent_notfound_rule', $ruleid, $lang),
                    ['field' => 'ruleid']
                ),
            ]);
        }

        $prepared = $input;
        $prepared['ruleid'] = $ruleid;
        $prepared['override'] = $this->override_tokens($input);

        if (!$this->is_confirmed($prepared)) {
            $counts = $this->assignment_counts($ruleid, $lang);
            return $this->confirmable($prepared, [[
                'code' => self::ISSUE_CONFIRM_REQUIRED,
                'severity' => 'needs_confirmation',
                'field' => 'override',
                'user_question' => $this->localized_string('agent_delete_rule_confirm', (object)[
                    'id' => $ruleid,
                    'name' => (string)$resolved['rulename'],
                    'assignments' => $counts['total'],
                    'breakdown' => $counts['breakdown'],
                    'token' => self::OVERRIDE_CONFIRM_DELETE,
                ], $lang),
                'remedy_options' => [self::OVERRIDE_CONFIRM_DELETE, 'KEEP_RULE'],
            ]]);
        }

        return $this->pass($prepared);
    }

    /**
     * Tier-3 confirmation preview: what disappears, and that it happens at the next cron run.
     *
     * @param array $input Prepared input.
     * @return array{title:string,summary:string,rows:array[]}|null
     */
    public function describe_proposed_action(array $input): ?array {
        $lang = $this->get_output_language($input);
        $ruleid = (int)(taskflow_input_normalizer::to_int($input['ruleid'] ?? null) ?? 0);
        if ($ruleid <= 0) {
            return null;
        }
        $resolved = $this->resolve_rule($ruleid);
        if (empty($resolved)) {
            return null;
        }
        $counts = $this->assignment_counts($ruleid, $lang);

        $rows = [
            [
                'label' => $this->localized_string('agent_rule_row_rule', null, $lang),
                'value' => '#' . $ruleid . ' ' . (string)$resolved['rulename'],
            ],
            [
                'label' => $this->localized_string('agent_rule_row_active', null, $lang),
                'value' => $resolved['isactive'] ? get_string('yes') : get_string('no'),
            ],
            [
                'label' => $this->localized_string('agent_rule_row_assignments', null, $lang),
                'value' => (string)$counts['total'],
            ],
            [
                'label' => $this->localized_string('agent_preview_warning', null, $lang),
                'value' => $this->localized_string('agent_delete_rule_warning', (object)[
                    'assignments' => $counts['total'],
                    'breakdown' => $counts['breakdown'],
                ], $lang),
            ],
            [
                'label' => $this->localized_string('agent_delete_rule_row_confirmation', null, $lang),
                'value' => $this->is_confirmed($input)
                    ? $this->localized_string('agent_delete_rule_confirmation_given', self::OVERRIDE_CONFIRM_DELETE, $lang)
                    : $this->localized_string('agent_delete_rule_confirmation_pending', self::OVERRIDE_CONFIRM_DELETE, $lang),
            ],
        ];

        return [
            'title' => $this->localized_string('agent_delete_rule_title', (object)[
                'id' => $ruleid,
                'name' => (string)$resolved['rulename'],
            ], $lang),
            'summary' => $this->localized_string('agent_delete_rule_summary', (object)[
                'assignments' => $counts['total'],
                'task' => self::REMOVED_RULE_TASK,
            ], $lang),
            'rows' => $rows,
        ];
    }

    /**
     * Execute: queue removed_rule exactly like rules_table::action_deleterule() and report 'queued'.
     *
     * @param array $input Prepared input.
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        $lang = $this->get_output_language($input);
        $ruleid = (int)(taskflow_input_normalizer::to_int($input['ruleid'] ?? null) ?? 0);
        $debug = $this->build_task_debug_message(self::TASK_NAME, $input);

        if (!has_capability(self::CAPABILITY, context_system::instance(), $userid)) {
            return $this->error_result(
                self::ISSUE_SCOPE_DENIED,
                $this->localized_string('agent_scope_denied', null, $lang),
                ['links' => $this->links(null, ['rules']), 'debugmessage' => $debug]
            );
        }

        $resolved = $ruleid > 0 ? $this->resolve_rule($ruleid) : [];
        if (empty($resolved)) {
            return $this->error_result(
                self::ISSUE_RULE_NOT_FOUND,
                $this->localized_string('agent_notfound_rule', $ruleid, $lang),
                [
                    'links' => $this->links(taskflow_result_link_builder::dashboard_url(), ['rules']),
                    'debugmessage' => $debug,
                ]
            );
        }

        // The destructive step needs the structured token; a missing token is never inferred from text.
        if (!$this->is_confirmed($input)) {
            return $this->error_result(
                self::ISSUE_OVERRIDE_MISSING,
                $this->localized_string('agent_delete_rule_override_missing', self::OVERRIDE_CONFIRM_DELETE, $lang),
                [
                    'links' => $this->links(taskflow_result_link_builder::edit_rule_url($ruleid), ['rules']),
                    'resultid' => $ruleid,
                    'debugmessage' => $debug,
                ]
            );
        }

        $counts = $this->assignment_counts($ruleid, $lang);
        $before = $this->queued_tasks($ruleid);

        // Identical to local_taskflow\table\rules_table::action_deleterule(): the adhoc task
        // removed_rule receives the rule id and performs the deletion at the next cron run.
        $task = new removed_rule();
        $task->set_custom_data(['id' => $ruleid]);
        manager::queue_adhoc_task($task);

        $tasks = array_values(array_diff($this->queued_tasks($ruleid), $before));
        $links = $this->links(
            taskflow_result_link_builder::edit_rule_url($ruleid),
            ['rules', 'scheduled_tasks'],
            ['dashboard' => taskflow_result_link_builder::dashboard_url()]
        );

        if (empty($tasks)) {
            return $this->error_result(
                self::ISSUE_TASK_NOT_QUEUED,
                $this->localized_string('agent_delete_rule_notqueued', $ruleid, $lang),
                ['links' => $links, 'resultid' => $ruleid, 'debugmessage' => $debug]
            );
        }

        $usermessage = $this->localized_string('agent_delete_rule_queued', (object)[
            'id' => $ruleid,
            'name' => (string)$resolved['rulename'],
            'assignments' => $counts['total'],
            'task' => self::REMOVED_RULE_TASK,
        ], $lang);

        $result = $this->base_result(self::STATUS_QUEUED, [
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'observation_full' => implode("\n", [
                $usermessage,
                '',
                'Rule: #' . $ruleid . ' ' . (string)$resolved['rulename'],
                'Assignments to be removed with the rule: ' . $counts['total']
                    . ($counts['breakdown'] !== '' ? ' (' . $counts['breakdown'] . ')' : ''),
                'Queued adhoc tasks (' . self::REMOVED_RULE_TASK . '): ' . implode(', ', $tasks),
                'Nothing has been deleted yet - the rule and its assignments are removed at the next cron run.',
            ]),
            'resultid' => $ruleid,
            'ruleid' => $ruleid,
            'rulename' => (string)$resolved['rulename'],
            'assignments_total' => $counts['total'],
            'assignments_by_status' => $counts['bystatus'],
            'queued_effects' => [[
                'task' => self::REMOVED_RULE_TASK,
                'status' => self::STATUS_QUEUED,
                'taskids' => $tasks,
                'detail' => $this->localized_string('agent_delete_rule_queued_effect', (object)[
                    'task' => self::REMOVED_RULE_TASK,
                    'assignments' => $counts['total'],
                ], $lang),
            ]],
            'pending_removed_rule_tasks' => count($tasks),
            'links' => $links,
            'outputlang' => $lang,
            'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, [
                'Queued task ids: ' . implode(', ', $tasks),
            ]),
        ]);

        $details = create_rule_skill::rule_details($ruleid, $lang, $userid);
        $data = is_array($details['preview']['data'] ?? null) ? (array)$details['preview']['data'] : [];
        if (!empty($data)) {
            $data['warning'] = [
                'badge' => $this->localized_string('agent_delete_rule_badge', null, $lang),
                'text' => $this->localized_string('agent_delete_rule_warning', (object)[
                    'assignments' => $counts['total'],
                    'breakdown' => $counts['breakdown'],
                ], $lang),
            ];
            $result['preview'] = [
                'type' => taskflow_preview_renderer_factory::TYPE_RULE,
                'data' => $data,
                'payload' => ['ruleids' => [$ruleid]],
            ];
        }

        return $result;
    }

    /**
     * Normalized structured override tokens of the input (upper case, no empty entries).
     *
     * @param array $input
     * @return string[]
     */
    private function override_tokens(array $input): array {
        $tokens = taskflow_input_normalizer::to_list($input['override'] ?? null) ?? [];
        $normalized = [];
        foreach ($tokens as $token) {
            $token = \core_text::strtoupper(trim((string)$token));
            if ($token !== '') {
                $normalized[] = $token;
            }
        }
        return array_values(array_unique($normalized));
    }

    /**
     * Whether the structured confirmation token is present.
     *
     * @param array $input
     * @return bool
     */
    private function is_confirmed(array $input): bool {
        return in_array(self::OVERRIDE_CONFIRM_DELETE, $this->override_tokens($input), true);
    }

    /**
     * Assignments of the rule: total, rows per status and a localized breakdown text.
     *
     * @param int $ruleid
     * @param string $lang
     * @return array{total:int,bystatus:array<int,array{status:int,label:string,count:int}>,breakdown:string}
     */
    private function assignment_counts(int $ruleid, string $lang): array {
        global $DB;

        $rows = $DB->get_records_sql(
            "SELECT status, COUNT(id) AS assignments
               FROM {local_taskflow_assignment}
              WHERE ruleid = :ruleid
           GROUP BY status
           ORDER BY status",
            ['ruleid' => $ruleid]
        );

        $total = 0;
        $bystatus = [];
        $parts = [];
        foreach ($rows as $row) {
            $status = (int)$row->status;
            $count = (int)$row->assignments;
            $total += $count;
            $label = $this->status_label($status, $lang);
            $bystatus[] = ['status' => $status, 'label' => $label, 'count' => $count];
            $parts[] = $count . ' × ' . $label;
        }

        return ['total' => $total, 'bystatus' => $bystatus, 'breakdown' => empty($parts) ? '-' : implode(', ', $parts)];
    }

    /**
     * Ids of the queued removed_rule adhoc tasks naming this rule (the evidence of the deletion).
     *
     * @param int $ruleid
     * @return int[]
     */
    private function queued_tasks(int $ruleid): array {
        global $DB;

        $rows = $DB->get_records_list(
            'task_adhoc',
            'classname',
            ['\\' . self::REMOVED_RULE_TASK, self::REMOVED_RULE_TASK],
            'id',
            'id, customdata'
        );

        $ids = [];
        foreach ($rows as $row) {
            $data = json_decode((string)$row->customdata, true);
            if (is_array($data) && (int)($data['id'] ?? 0) === $ruleid) {
                $ids[] = (int)$row->id;
            }
        }
        return $ids;
    }
}
