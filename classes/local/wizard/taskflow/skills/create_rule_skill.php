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
use local_taskflow\local\wizard\taskflow\taskflow_result_link_builder;
use local_taskflow\local\wizard\taskflow\taskflow_rule_builder;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;

/**
 * Skill local_taskflow.create_rule: create a taskflow rule (implementation plan §2 #18).
 *
 * Mutating, R2, capability local/taskflow:createrules. Every value is validated against the same
 * catalogue local_taskflow.list_rule_properties reports (operators, filter types, target types,
 * request receivers, units) and every referenced object must exist, so a broken rule can never
 * reach the database. The rule document itself is produced by the shared form path
 * (taskflow_rule_builder::build_steps() → rule_persistence_service::build_from_steps() →
 * form\rules\types\unit_rule::get_data()) and written by rule_persistence_service::persist(),
 * exactly like the multistep form. The assignments are created asynchronously by the adhoc task
 * local_taskflow\task\update_rule, which is reported as a queued effect, never as finished work.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_rule_skill extends taskflow_skill_base implements queue_identity_provider_interface {
    /** Skill name. */
    public const TASK_NAME = 'local_taskflow.create_rule';

    /** Native capability required to write rules. */
    public const CAPABILITY = 'local/taskflow:createrules';

    /** Adhoc task that turns a saved rule into assignments. */
    public const UPDATE_RULE_TASK = 'local_taskflow\task\update_rule';

    /** Issue code: the proposed rule needs an explicit confirmation. */
    public const ISSUE_CONFIRM_REQUIRED = 'TASKFLOW_CREATE_RULE_CONFIRM_REQUIRED';

    /** Queue task family for deduplication. */
    public const QUEUE_TASK_FAMILY = 'taskflow_rule_write';

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
            'description' => 'Create a new taskflow rule that assigns courses, booking options or '
            . 'competencies to the members of an '
                . 'organisational unit or to one person. It defines the due date model (duration or fixed date), '
                . 'optional profile-field filters, message templates and self-service request settings. The rule is '
                . 'written immediately; the assignments themselves are created by an adhoc task at the next cron run. '
                . 'Use list_rule_properties first when unsure which operators, filter fields or target types exist.',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'Create a rule for the administration unit: everyone has to finish the data protection course in 90 days',
                'Add a taskflow rule that assigns the fire safety booking option to unit 12 every year',
                'Set up a personal rule for user 123 with a fixed due date of 31 December',
                'Create a rule with a filter on the contract profile field',
            ],
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Name of the rule.',
                    'required' => true,
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Free-text description shown in the rules dashboard.',
                ],
                'ruletype' => [
                    'type' => 'string',
                    'description' => 'unit = rule for an organisational unit, user = personal rule for one person.',
                    'enum' => [taskflow_rule_builder::RULETYPE_UNIT, taskflow_rule_builder::RULETYPE_USER],
                    'required' => true,
                ],
                'unitid' => [
                    'type' => 'integer',
                    'description' => 'Organisational unit (cohort) the rule applies to. Required for ruletype unit.',
                ],
                'userid' => [
                    'type' => 'integer',
                    'description' => 'Moodle user id the personal rule applies to. Required for ruletype user.',
                ],
                'duedatetype' => [
                    'type' => 'string',
                    'description' => 'duration = due date is computed from the assignment date, '
                        . 'fixeddate = one fixed date for everybody.',
                    'enum' => [taskflow_rule_builder::DUEDATE_DURATION, taskflow_rule_builder::DUEDATE_FIXEDDATE],
                    'required' => true,
                ],
                'duration' => [
                    'type' => 'integer',
                    'description' => 'Seconds between assignment and due date (duedatetype duration).',
                ],
                'fixeddate' => [
                    'type' => 'string',
                    'description' => 'Fixed due date as unix timestamp or a date string (duedatetype fixeddate).',
                ],
                'extensionperiod' => [
                    'type' => 'integer',
                    'description' => 'Seconds an overdue assignment may be prolonged by.',
                ],
                'activationdelay' => [
                    'type' => 'integer',
                    'description' => 'Seconds the assignment stays planned before it becomes active.',
                ],
                'cyclicvalidation' => [
                    'type' => 'boolean',
                    'description' => 'The rule must be repeated after cyclicduration.',
                ],
                'cyclicduration' => [
                    'type' => 'integer',
                    'description' => 'Seconds after completion until the assignment is repeated.',
                ],
                'inheritance' => [
                    'type' => 'boolean',
                    'description' => 'Also apply the rule to the members of all child units.',
                ],
                'recursive' => [
                    'type' => 'boolean',
                    'description' => 'Saving the rule also re-evaluates existing assignments (due dates included).',
                ],
                'filters' => [
                    'type' => 'array',
                    'description' => 'Conditions every person must fulfil (AND). Items: '
                        . '{filtertype, field, operator, value, date}.',
                ],
                'targets' => [
                    'type' => 'array',
                    'description' => 'What has to be completed. Items: {targettype, targetid, completebeforenext}. '
                        . 'completebeforenext is stored for the whole chain: the flag of the first target '
                        . 'applies to all of them (same behaviour as the rule form).',
                ],
                'messageids' => [
                    'type' => 'array',
                    'description' => 'Ids of message templates (local_taskflow_messages) attached to the rule.',
                ],
                'requests' => [
                    'type' => 'object',
                    'description' => 'Self-service requests per request type, values not_allowed, supervisor or hr.',
                ],
                'enabled' => [
                    'type' => 'boolean',
                    'description' => 'Whether the rule is active right away (default yes).',
                ],
            ],
            'required' => ['name', 'ruletype', 'duedatetype'],
        ];
    }

    /**
     * Prompt metadata.
     *
     * @return array<string,mixed>
     */
    protected function prompt_meta(): array {
        return [
            'intent' => 'Create a new taskflow rule and queue the assignment roll-out.',
            'input_fields_for_prompt' => ['name', 'ruletype', 'duedatetype'],
            'anchor_fields' => ['name', 'unitid', 'userid'],
        ];
    }

    /**
     * Example input.
     *
     * @return array
     */
    public function get_example_input(): array {
        return [
            'name' => 'Data protection basics',
            'ruletype' => taskflow_rule_builder::RULETYPE_UNIT,
            'unitid' => 12,
            'duedatetype' => taskflow_rule_builder::DUEDATE_DURATION,
            'duration' => 90 * DAYSECS,
            'targets' => [['targettype' => 'moodlecourse', 'targetid' => 31]],
        ];
    }

    /**
     * Queue identity: rule writes for the same name and scope deduplicate.
     *
     * @param array $input
     * @return array
     */
    public function build_queue_business_identity(array $input): array {
        return [
            'task_family' => self::QUEUE_TASK_FAMILY,
            'skill' => self::TASK_NAME,
            'rulename' => trim((string)($input['name'] ?? '')),
            'unitid' => (int)($input['unitid'] ?? 0),
            'userid' => (int)($input['userid'] ?? 0),
        ];
    }

    /**
     * Structural validation (no DB access).
     *
     * @param array $input
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>}
     */
    public function check_structure(array $input): array {
        $lang = $this->get_output_language($input);
        $errors = [];
        foreach (['name', 'ruletype', 'duedatetype'] as $field) {
            if (trim((string)($input[$field] ?? '')) === '') {
                $errors[] = $this->localized_string('agent_rule_missing_value', $field, $lang);
            }
        }
        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => []];
    }

    /**
     * Preflight: capability, catalogue validation, then a confirmation with the full preview.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array{status:string,prepared_input:array,issues:array}
     */
    protected function run_preflight(array $input, int $contextid, int $userid): array {
        $lang = $this->get_output_language($input);
        if (!has_capability(self::CAPABILITY, context_system::instance(), $userid)) {
            return $this->invalid([$this->scope_denied_issue($lang, ['field' => 'name'])]);
        }

        $structure = $this->check_structure($input);
        if (!($structure['valid'] ?? false)) {
            $issues = [];
            foreach ((array)($structure['errors'] ?? []) as $error) {
                $issues[] = [
                    'code' => taskflow_rule_builder::ISSUE_MISSING_VALUE,
                    'severity' => 'needs_clarification',
                    'field' => 'name',
                    'message' => (string)$error,
                ];
            }
            return $this->invalid($issues);
        }

        $prepared = $this->prepare($input);
        if (!empty($prepared['issues'])) {
            return $this->invalid($prepared['issues']);
        }

        return $this->confirmable($input, [[
            'code' => self::ISSUE_CONFIRM_REQUIRED,
            'severity' => 'needs_confirmation',
            'user_question' => $this->localized_string('agent_create_rule_confirm', (object)[
                'name' => $prepared['rule']['name'],
                'affected' => (int)$prepared['dryrun']['matched'],
            ], $lang),
        ]]);
    }

    /**
     * Tier-3 confirmation preview: what the rule does and how many people it would reach.
     *
     * @param array $input Prepared input.
     * @return array{title:string,summary:string,rows:array[]}|null
     */
    public function describe_proposed_action(array $input): ?array {
        $lang = $this->get_output_language($input);
        $prepared = $this->prepare($input);
        if (!empty($prepared['issues'])) {
            return null;
        }
        $builder = new taskflow_rule_builder($lang);
        $rule = $prepared['rule'];
        $dryrun = $prepared['dryrun'];

        $rows = $builder->preview_rows($rule);
        $rows[] = [
            'label' => $this->localized_string('agent_rule_row_enabled', null, $lang),
            'value' => $rule['enabled'] ? get_string('yes') : get_string('no'),
        ];
        $rows[] = [
            'label' => $this->localized_string('agent_rule_row_affected', null, $lang),
            'value' => $builder->dryrun_text($dryrun),
        ];

        return [
            'title' => $this->localized_string('agent_create_rule_title', (object)[
                'name' => $rule['name'],
                'scope' => $builder->scope_text($rule),
            ], $lang),
            'summary' => $this->localized_string('agent_create_rule_summary', (object)[
                'name' => $rule['name'],
                'affected' => (int)$dryrun['matched'],
                'duedate' => $builder->duedate_text($rule),
                'task' => self::UPDATE_RULE_TASK,
            ], $lang),
            'rows' => $rows,
        ];
    }

    /**
     * Execute: persist through the shared service and verify the stored document.
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

        $prepared = $this->prepare($input);
        if (!empty($prepared['issues'])) {
            $first = (array)$prepared['issues'][0];
            return $this->error_result(
                (string)($first['code'] ?? taskflow_rule_builder::ISSUE_INVALID_VALUE),
                (string)($first['message'] ?? ''),
                ['links' => $this->links(null, ['rules'])]
            );
        }

        $ruleid = (new rule_persistence_service())->persist($prepared['ruledata'], $userid);
        rules::reset_instances();
        unit_rules::reset_instances();

        $rule = $prepared['rule'];
        $expected = [
            'rulename' => $rule['name'],
            'targets' => self::target_signature($rule['targets']),
            'filters' => self::filter_signature($rule['filters']),
        ];

        $verified = $this->verified_result(
            $expected,
            fn(): ?array => self::read_back_signature($this->resolve_rule((int)$ruleid)),
            [
                'resultid' => (int)$ruleid,
                'links' => $this->links(
                    taskflow_result_link_builder::edit_rule_url((int)$ruleid),
                    ['rules', 'rules_rule_step', 'rules_filters', 'rules_targets'],
                    ['dashboard' => taskflow_result_link_builder::dashboard_url()]
                ),
            ],
            $lang
        );
        $verified['debugmessage'] = $this->build_task_debug_message(self::TASK_NAME, $input, [
            'Rule id: ' . $ruleid,
            'Targets: ' . count($rule['targets']) . ', filters: ' . count($rule['filters']),
            'Dry run matched: ' . (int)$prepared['dryrun']['matched'] . ' of ' . (int)$prepared['dryrun']['scanned'],
        ]);
        if ((string)$verified['status'] !== self::STATUS_EXECUTED) {
            return $verified;
        }

        return $this->decorate_result($verified, (int)$ruleid, $prepared, $lang, $userid);
    }

    /**
     * Enrich the verified result with the rule payload, the preview and the queued effects.
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
        $rule = $prepared['rule'];
        $dryrun = $prepared['dryrun'];
        $details = self::rule_details($ruleid, $lang, $userid);

        $usermessage = $this->localized_string('agent_create_rule_done', (object)[
            'id' => $ruleid,
            'name' => $rule['name'],
            'targets' => count($rule['targets']),
            'affected' => (int)$dryrun['matched'],
            'task' => self::UPDATE_RULE_TASK,
        ], $lang);

        $observation = $usermessage . "\n\n" . implode("\n", [
            'Rule id: ' . $ruleid,
            'Target group: ' . $builder->scope_text($rule),
            'Due date: ' . $builder->duedate_text($rule),
            'Filters: ' . ($builder->filter_text($rule['filters']) ?: '-'),
            'Targets: ' . ($builder->target_text($rule['targets']) ?: '-'),
            'Messages: ' . ($builder->message_text($rule['messageids']) ?: '-'),
            'Requests: ' . $builder->request_text($rule['requests']),
            'Affected users (dry run): ' . $builder->dryrun_text($dryrun),
            'Queued (not done yet): ' . self::UPDATE_RULE_TASK . ' creates the assignments at the next cron run.',
        ]);

        $result['rule'] = (array)($details['rule'] ?? []);
        $result['filters'] = (array)($details['filters'] ?? []);
        $result['targets'] = (array)($details['targets'] ?? []);
        $result['messages'] = (array)($details['messages'] ?? []);
        $result['requests'] = (array)($details['requests'] ?? []);
        $result['affected_users'] = $dryrun;
        $result['queued_effects'] = [[
            'task' => self::UPDATE_RULE_TASK,
            'status' => self::STATUS_QUEUED,
            'detail' => $this->localized_string('agent_rule_queued_update', (object)[
                'task' => self::UPDATE_RULE_TASK,
                'affected' => (int)$dryrun['matched'],
            ], $lang),
        ]];
        $result['pending_update_rule_tasks'] = (int)($details['pending_update_rule_tasks'] ?? 0);
        $result['detail'] = $usermessage;
        $result['usermessage'] = $usermessage;
        $result['observation_full'] = $observation;
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
     * Validate the input and build everything the preview and the write need (memoized).
     *
     * @param array $input
     * @return array{rule:array,issues:array,steps:array,ruledata:array,dryrun:array}
     */
    private function prepare(array $input): array {
        $key = md5((string)json_encode($input));
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $builder = new taskflow_rule_builder($this->get_output_language($input));
        $normalized = $builder->normalize($input, taskflow_rule_builder::defaults());
        $prepared = [
            'rule' => $normalized['rule'],
            'issues' => $normalized['issues'],
            'steps' => [],
            'ruledata' => [],
            'dryrun' => ['matched' => 0, 'scanned' => 0, 'members' => 0, 'capped' => false],
        ];

        if (empty($normalized['issues'])) {
            $prepared['steps'] = $builder->build_steps($normalized['rule']);
            $prepared['ruledata'] = rule_persistence_service::build_from_steps($prepared['steps']);
            $prepared['dryrun'] = $builder->affected_users(
                $normalized['rule'],
                (string)($prepared['ruledata']['rulejson'] ?? '')
            );
        }

        $this->memo[$key] = $prepared;
        return $prepared;
    }

    /**
     * Verification signature of a freshly read rule (null when the rule is gone).
     *
     * @param array $resolved taskflow_skill_base::resolve_rule() result.
     * @return array|null
     */
    public static function read_back_signature(array $resolved): ?array {
        if (empty($resolved)) {
            return null;
        }
        $stored = taskflow_rule_builder::from_stored($resolved);
        return [
            'rulename' => (string)$stored['name'],
            'targets' => self::target_signature((array)$stored['targets']),
            'filters' => self::filter_signature((array)$stored['filters']),
        ];
    }

    /**
     * Comparable signature of a target list.
     *
     * @param array $targets
     * @return string
     */
    public static function target_signature(array $targets): string {
        $parts = [];
        foreach ($targets as $target) {
            $parts[] = $target['targettype'] . ':' . (int)$target['targetid'] . ':' . (int)$target['completebeforenext'];
        }
        sort($parts);
        return implode('|', $parts);
    }

    /**
     * Comparable signature of a filter list.
     *
     * @param array $filters
     * @return string
     */
    public static function filter_signature(array $filters): string {
        $parts = [];
        foreach ($filters as $filter) {
            $parts[] = $filter['filtertype'] . ':' . $filter['field'] . ':' . $filter['operator'] . ':' . $filter['value'];
        }
        sort($parts);
        return implode('|', $parts);
    }

    /**
     * Detail payload of the stored rule, reusing local_taskflow.get_rule_details ([] on failure).
     *
     * @param int $ruleid
     * @param string $lang
     * @param int $userid
     * @return array
     */
    public static function rule_details(int $ruleid, string $lang, int $userid): array {
        try {
            $details = (new get_rule_details_skill())->execute(
                ['ruleid' => $ruleid, 'outputlang' => $lang],
                (int)context_system::instance()->id,
                $userid
            );
        } catch (\Throwable $e) {
            return [];
        }
        return (string)($details['status'] ?? '') === taskflow_skill_base::STATUS_EXECUTED ? $details : [];
    }
}
