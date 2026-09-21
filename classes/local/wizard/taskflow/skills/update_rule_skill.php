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
use local_taskflow\local\rules\rule_persistence_service;
use local_taskflow\local\rules\rules;
use local_taskflow\local\rules\unit_rules;
use local_taskflow\local\wizard\engine\queue_identity_provider_interface;
use local_taskflow\local\wizard\engine\skill_risk_class;
use local_taskflow\local\wizard\taskflow\taskflow_input_normalizer;
use local_taskflow\local\wizard\taskflow\taskflow_result_link_builder;
use local_taskflow\local\wizard\taskflow\taskflow_rule_builder;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;

/**
 * Skill local_taskflow.update_rule: change an existing taskflow rule (implementation plan §2 #19).
 *
 * Mutating, R2, capability local/taskflow:createrules. The stored rulejson is decoded, only the
 * fields present in the input are merged into it and the merged document goes back through the
 * shared form path (taskflow_rule_builder::build_steps() → rule_persistence_service) — a pure
 * activation or deactivation takes exactly the same route, so the change manager, the
 * rule_created_updated event and the cache purges always run. Existing assignments are
 * re-evaluated asynchronously by the adhoc task local_taskflow\task\update_rule, which is
 * reported as a queued effect.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_rule_skill extends taskflow_skill_base implements queue_identity_provider_interface {
    /** Skill name. */
    public const TASK_NAME = 'local_taskflow.update_rule';

    /** Native capability required to write rules. */
    public const CAPABILITY = 'local/taskflow:createrules';

    /** Issue code: the proposed change needs an explicit confirmation. */
    public const ISSUE_CONFIRM_REQUIRED = 'TASKFLOW_UPDATE_RULE_CONFIRM_REQUIRED';

    /** Issue code: the input would not change anything. */
    public const ISSUE_NO_CHANGES = 'TASKFLOW_RULE_NO_CHANGES';

    /** Fields excluded from the change detection (bookkeeping only). */
    private const IGNORED_FIELDS = ['recordid', 'timecreated'];

    /** @var array<string,array> Memoized preparation per input. */
    private array $memo = [];

    /**
     * Constructor: mutating, broad write, guarded by local/taskflow:createrules.
     */
    public function __construct() {
        parent::__construct(false, skill_risk_class::R2, [self::CAPABILITY]);
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
            'description' => 'Change an existing taskflow rule. Rename it, activate or deactivate it, change the due-date model, '
                . 'extension period, cyclic repetition, activation delay, inheritance or filters, add or remove targets '
                . '(courses, booking options, competencies) and message templates, or change the self-service request '
                . 'settings. Only the fields you pass are changed, everything else stays as it is. Existing assignments '
                . 'are re-evaluated by an adhoc task at the next cron run.',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'Deactivate taskflow rule 17',
                'Change the due date of rule 17 to 120 days',
                'Add the refresher booking option as a target to rule 17',
                'Remove message template 12 from rule 21 and rename it',
            ],
            'properties' => [
                'ruleid' => [
                    'type' => 'integer',
                    'description' => 'Id of the rule to change (use search_rules to find it).',
                    'required' => true,
                ],
                'name' => ['type' => 'string', 'description' => 'New name of the rule.'],
                'description' => ['type' => 'string', 'description' => 'New description.'],
                'ruletype' => [
                    'type' => 'string',
                    'description' => 'unit = rule for an organisational unit, user = personal rule.',
                    'enum' => [taskflow_rule_builder::RULETYPE_UNIT, taskflow_rule_builder::RULETYPE_USER],
                ],
                'unitid' => ['type' => 'integer', 'description' => 'New organisational unit (cohort) of the rule.'],
                'userid' => ['type' => 'integer', 'description' => 'New person of a personal rule.'],
                'duedatetype' => [
                    'type' => 'string',
                    'description' => 'duration = computed from the assignment date, fixeddate = one fixed date.',
                    'enum' => [taskflow_rule_builder::DUEDATE_DURATION, taskflow_rule_builder::DUEDATE_FIXEDDATE],
                ],
                'duration' => ['type' => 'integer', 'description' => 'Seconds between assignment and due date.'],
                'fixeddate' => ['type' => 'string', 'description' => 'Fixed due date (unix timestamp or date string).'],
                'extensionperiod' => ['type' => 'integer', 'description' => 'Seconds an overdue assignment may be prolonged.'],
                'activationdelay' => ['type' => 'integer', 'description' => 'Seconds the assignment stays planned.'],
                'cyclicvalidation' => ['type' => 'boolean', 'description' => 'The rule must be repeated.'],
                'cyclicduration' => ['type' => 'integer', 'description' => 'Seconds until the assignment is repeated.'],
                'inheritance' => ['type' => 'boolean', 'description' => 'Also apply to the members of all child units.'],
                'recursive' => [
                    'type' => 'boolean',
                    'description' => 'Saving the rule also re-evaluates existing assignments and recomputes their due dates.',
                ],
                'filters' => [
                    'type' => 'array',
                    'description' => 'Replaces the whole filter list. Items: {filtertype, field, operator, value, date}.',
                ],
                'targets' => [
                    'type' => 'array',
                    'description' => 'Replaces the whole target list. Items: {targettype, targetid, '
                        . 'completebeforenext}; completebeforenext applies to the whole chain.',
                ],
                'addtargets' => [
                    'type' => 'array',
                    'description' => 'Targets to add, same item shape as targets.',
                ],
                'removetargetids' => [
                    'type' => 'array',
                    'description' => 'Target ids to remove from the rule.',
                ],
                'messageids' => ['type' => 'array', 'description' => 'Replaces the whole list of message templates.'],
                'addmessageids' => ['type' => 'array', 'description' => 'Message template ids to add.'],
                'removemessageids' => ['type' => 'array', 'description' => 'Message template ids to remove.'],
                'requests' => [
                    'type' => 'object',
                    'description' => 'Self-service requests per request type, values not_allowed, supervisor or hr.',
                ],
                'isactive' => [
                    'type' => 'boolean',
                    'description' => 'Activate (true) or deactivate (false) the rule.',
                ],
                'enabled' => [
                    'type' => 'boolean',
                    'description' => 'Alias of isactive.',
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
            'intent' => 'Change one existing taskflow rule; only the given fields are touched.',
            'input_fields_for_prompt' => ['ruleid'],
            'anchor_fields' => ['ruleid'],
        ];
    }

    /**
     * Example input.
     *
     * @return array
     */
    public function get_example_input(): array {
        return ['ruleid' => 17, 'duration' => 120 * DAYSECS];
    }

    /**
     * Queue identity: writes to the same rule deduplicate.
     *
     * @param array $input
     * @return array
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
     * Preflight: capability, rule existence, catalogue validation, then confirmation.
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

        $prepared = $this->prepare($input);
        if (!empty($prepared['issues'])) {
            return $this->invalid($prepared['issues']);
        }
        if (empty($prepared['changed'])) {
            return $this->invalid([[
                'code' => self::ISSUE_NO_CHANGES,
                'severity' => 'needs_clarification',
                'field' => 'ruleid',
                'message' => $this->localized_string('agent_update_rule_nochanges', $ruleid, $lang),
            ]]);
        }

        $input['ruleid'] = $ruleid;
        return $this->confirmable($input, [[
            'code' => self::ISSUE_CONFIRM_REQUIRED,
            'severity' => 'needs_confirmation',
            'user_question' => $this->localized_string('agent_update_rule_confirm', (object)[
                'id' => $ruleid,
                'name' => $prepared['rule']['name'],
                'fields' => count($prepared['changed']),
            ], $lang),
        ]]);
    }

    /**
     * Tier-3 confirmation preview: old → new per changed field plus the propagation warning.
     *
     * @param array $input Prepared input.
     * @return array{title:string,summary:string,rows:array[]}|null
     */
    public function describe_proposed_action(array $input): ?array {
        $lang = $this->get_output_language($input);
        $prepared = $this->prepare($input);
        if (!empty($prepared['issues']) || empty($prepared['changed'])) {
            return null;
        }

        $builder = new taskflow_rule_builder($lang);
        $current = $prepared['current'];
        $rule = $prepared['rule'];
        $ruleid = (int)$rule['recordid'];

        $rows = [];
        foreach ($this->change_groups($prepared['changed']) as $field) {
            $rows[] = [
                'label' => $builder->field_label($field),
                'value' => $this->localized_string('agent_rule_change_row', (object)[
                    'old' => $builder->field_text($current, $field),
                    'new' => $builder->field_text($rule, $field),
                ], $lang),
            ];
        }
        $rows[] = [
            'label' => $this->localized_string('agent_rule_row_assignments', null, $lang),
            'value' => (string)$prepared['assignments'],
        ];
        $rows[] = [
            'label' => $this->localized_string('agent_rule_row_affected', null, $lang),
            'value' => $builder->dryrun_text($prepared['dryrun']),
        ];
        $rows[] = [
            'label' => $this->localized_string('agent_rule_row_warning', null, $lang),
            'value' => $this->localized_string(
                empty($rule['recursive']) ? 'agent_update_rule_warning' : 'agent_update_rule_warning_recursive',
                (object)['assignments' => (int)$prepared['assignments']],
                $lang
            ),
        ];

        $activationonly = $this->change_groups($prepared['changed']) === ['enabled'];
        $titlekey = $activationonly
            ? (empty($rule['enabled']) ? 'agent_update_rule_title_deactivate' : 'agent_update_rule_title_activate')
            : 'agent_update_rule_title';

        return [
            'title' => $this->localized_string($titlekey, (object)['id' => $ruleid, 'name' => $rule['name']], $lang),
            'summary' => $this->localized_string('agent_update_rule_summary', (object)[
                'fields' => count($this->change_groups($prepared['changed'])),
                'assignments' => (int)$prepared['assignments'],
                'task' => create_rule_skill::UPDATE_RULE_TASK,
            ], $lang),
            'rows' => $rows,
        ];
    }

    /**
     * Execute: merge, persist through the shared service and verify the stored document.
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
        if (empty($this->resolve_rule($ruleid))) {
            return $this->error_result(
                self::ISSUE_RULE_NOT_FOUND,
                $this->localized_string('agent_notfound_rule', $ruleid, $lang),
                ['links' => $this->links(taskflow_result_link_builder::dashboard_url(), ['rules'])]
            );
        }

        $prepared = $this->prepare($input);
        if (!empty($prepared['issues'])) {
            $first = (array)$prepared['issues'][0];
            return $this->error_result(
                (string)($first['code'] ?? taskflow_rule_builder::ISSUE_INVALID_VALUE),
                (string)($first['message'] ?? ''),
                ['links' => $this->links(taskflow_result_link_builder::edit_rule_url($ruleid), ['rules'])]
            );
        }

        (new rule_persistence_service())->persist($prepared['ruledata'], $userid);
        rules::reset_instances();
        unit_rules::reset_instances();

        $rule = $prepared['rule'];
        $expected = [
            'rulename' => (string)$rule['name'],
            'enabled' => (int)$rule['enabled'],
            'targets' => create_rule_skill::target_signature((array)$rule['targets']),
            'filters' => create_rule_skill::filter_signature((array)$rule['filters']),
            'messageids' => implode(',', array_map('intval', (array)$rule['messageids'])),
            'requests' => (string)json_encode($rule['requests']),
            'duedate' => $rule['duedatetype'] . ':' . (int)$rule['duration'] . ':' . (int)$rule['fixeddate'],
        ];

        $verified = $this->verified_result(
            $expected,
            fn(): ?array => $this->stored_signature($ruleid),
            [
                'resultid' => $ruleid,
                'links' => $this->links(
                    taskflow_result_link_builder::edit_rule_url($ruleid),
                    ['rules', 'rules_rule_step', 'rules_filters', 'rules_targets'],
                    ['dashboard' => taskflow_result_link_builder::dashboard_url()]
                ),
            ],
            $lang
        );
        $verified['debugmessage'] = $this->build_task_debug_message(self::TASK_NAME, $input, [
            'Rule id: ' . $ruleid,
            'Changed fields: ' . implode(', ', $prepared['changed']),
        ]);
        if ((string)$verified['status'] !== self::STATUS_EXECUTED) {
            return $verified;
        }

        return $this->decorate_result($verified, $ruleid, $prepared, $lang, $userid);
    }

    /**
     * Enrich the verified result with the diff, the rule payload and the queued effects.
     *
     * @param array $result
     * @param int $ruleid
     * @param array $prepared
     * @param string $lang
     * @param int $userid
     * @return array
     */
    private function decorate_result(array $result, int $ruleid, array $prepared, string $lang, int $userid): array {
        $builder = new taskflow_rule_builder($lang);
        $details = create_rule_skill::rule_details($ruleid, $lang, $userid);

        $changes = [];
        foreach ($this->change_groups($prepared['changed']) as $field) {
            $changes[] = [
                'field' => $field,
                'label' => $builder->field_label($field),
                'old' => $builder->field_text($prepared['current'], $field),
                'new' => $builder->field_text($prepared['rule'], $field),
            ];
        }

        $usermessage = $this->localized_string('agent_update_rule_done', (object)[
            'id' => $ruleid,
            'name' => (string)$prepared['rule']['name'],
            'fields' => count($changes),
            'assignments' => (int)$prepared['assignments'],
            'task' => create_rule_skill::UPDATE_RULE_TASK,
        ], $lang);

        $lines = [$usermessage, ''];
        foreach ($changes as $change) {
            $lines[] = $change['label'] . ': ' . $change['old'] . ' -> ' . $change['new'];
        }
        $lines[] = 'Existing assignments: ' . (int)$prepared['assignments'];
        $lines[] = 'Affected users (dry run): ' . $builder->dryrun_text($prepared['dryrun']);
        $lines[] = 'Queued (not done yet): ' . create_rule_skill::UPDATE_RULE_TASK
            . ' re-evaluates the assignments at the next cron run.';

        $result['rule'] = (array)($details['rule'] ?? []);
        $result['filters'] = (array)($details['filters'] ?? []);
        $result['targets'] = (array)($details['targets'] ?? []);
        $result['messages'] = (array)($details['messages'] ?? []);
        $result['requests'] = (array)($details['requests'] ?? []);
        $result['changes'] = $changes;
        $result['changed_fields'] = $this->change_groups($prepared['changed']);
        $result['assignments_total'] = (int)$prepared['assignments'];
        $result['affected_users'] = $prepared['dryrun'];
        $result['queued_effects'] = [[
            'task' => create_rule_skill::UPDATE_RULE_TASK,
            'status' => self::STATUS_QUEUED,
            'detail' => $this->localized_string('agent_rule_queued_update', (object)[
                'task' => create_rule_skill::UPDATE_RULE_TASK,
                'affected' => (int)$prepared['dryrun']['matched'],
            ], $lang),
        ]];
        $result['pending_update_rule_tasks'] = (int)($details['pending_update_rule_tasks'] ?? 0);
        $result['detail'] = $usermessage;
        $result['usermessage'] = $usermessage;
        $result['observation_full'] = implode("\n", $lines);
        $result['outputlang'] = $lang;
        if (!empty($details['preview']['type'])) {
            $result['preview'] = [
                'type' => (string)$details['preview']['type'],
                'data' => (array)($details['preview']['data'] ?? []),
                'payload' => ['ruleids' => [$ruleid]],
            ];
        }

        return $result;
    }

    /**
     * Merge the input into the stored rule and build everything preview and write need (memoized).
     *
     * @param array $input
     * @return array{current:array,rule:array,changed:string[],issues:array,ruledata:array,dryrun:array,assignments:int}
     */
    private function prepare(array $input): array {
        global $DB;

        $key = md5((string)json_encode($input));
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $lang = $this->get_output_language($input);
        $ruleid = (int)(taskflow_input_normalizer::to_int($input['ruleid'] ?? null) ?? 0);
        $builder = new taskflow_rule_builder($lang);
        $current = taskflow_rule_builder::from_stored($this->resolve_rule($ruleid));
        $normalized = $builder->normalize($input, $current);

        $changed = array_values(array_diff((array)$normalized['changed'], self::IGNORED_FIELDS));
        $prepared = [
            'current' => $current,
            'rule' => $normalized['rule'],
            'changed' => $changed,
            'issues' => $normalized['issues'],
            'ruledata' => [],
            'dryrun' => ['matched' => 0, 'scanned' => 0, 'members' => 0, 'capped' => false],
            'assignments' => (int)$DB->count_records('local_taskflow_assignment', ['ruleid' => $ruleid]),
        ];

        if (empty($normalized['issues'])) {
            $steps = $builder->build_steps($normalized['rule']);
            $prepared['ruledata'] = rule_persistence_service::build_from_steps($steps);
            $prepared['dryrun'] = $builder->affected_users(
                $normalized['rule'],
                (string)($prepared['ruledata']['rulejson'] ?? '')
            );
        }

        $this->memo[$key] = $prepared;
        return $prepared;
    }

    /**
     * Collapse the changed keys onto the fields the preview shows (due date and scope are one row).
     *
     * @param string[] $changed
     * @return string[]
     */
    private function change_groups(array $changed): array {
        $groups = [];
        foreach ($changed as $field) {
            if (in_array($field, ['duedatetype', 'duration', 'fixeddate'], true)) {
                $groups['duedatetype'] = 'duedatetype';
                continue;
            }
            if (in_array($field, ['ruletype', 'unitid', 'userid'], true)) {
                $groups['unitid'] = 'unitid';
                continue;
            }
            $groups[$field] = $field;
        }
        return array_values($groups);
    }

    /**
     * Verification signature read fresh from the database (null when the rule is gone).
     *
     * @param int $ruleid
     * @return array|null
     */
    private function stored_signature(int $ruleid): ?array {
        $resolved = $this->resolve_rule($ruleid);
        if (empty($resolved)) {
            return null;
        }
        $stored = taskflow_rule_builder::from_stored($resolved);
        return [
            'rulename' => (string)$stored['name'],
            'enabled' => (int)$stored['enabled'],
            'targets' => create_rule_skill::target_signature((array)$stored['targets']),
            'filters' => create_rule_skill::filter_signature((array)$stored['filters']),
            'messageids' => implode(',', array_map('intval', (array)$stored['messageids'])),
            'requests' => (string)json_encode($stored['requests']),
            'duedate' => $stored['duedatetype'] . ':' . (int)$stored['duration'] . ':' . (int)$stored['fixeddate'],
        ];
    }
}
