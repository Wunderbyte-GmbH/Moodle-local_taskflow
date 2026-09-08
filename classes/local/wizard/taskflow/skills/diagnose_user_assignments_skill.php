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

use local_taskflow\local\assignment_operators\filter_operator;
use local_taskflow\local\assignments\types\standard_assignment;
use local_taskflow\local\external_adapter\external_api_base;
use local_taskflow\local\filters\filter_factory;
use local_taskflow\local\rules\rules;
use local_taskflow\local\units\organisational_unit_factory;
use local_taskflow\local\units\unit_hierarchy;
use local_taskflow\local\wizard\engine\observation_time;
use local_taskflow\local\wizard\engine\skill_risk_class;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\taskflow_input_normalizer;
use local_taskflow\local\wizard\taskflow\taskflow_permission_resolver;
use local_taskflow\local\wizard\taskflow\taskflow_result_link_builder;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use local_taskflow\plugininfo\taskflowadapter;

/**
 * Read-only skill local_taskflow.diagnose_user_assignments (implementation plan §2 #13).
 *
 * Answers "why does this person (not) get an assignment from this rule?" by replaying the
 * gates of the assignment pipeline in the order they are evaluated at runtime (inventory
 * 02 §0.2): rule row and rule document, unit membership including inheritance through the
 * unit hierarchy, account state, every rule filter individually and as a whole through
 * filter_operator, the existing assignment and the adhoc tasks that would still change the
 * outcome. Every verdict is derived from engine/DB state; nothing is inferred from wording.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class diagnose_user_assignments_skill extends taskflow_skill_base {
    /** Skill name. */
    public const TASK_NAME = 'local_taskflow.diagnose_user_assignments';

    /** Verdict: the user already has an assignment for this rule. */
    public const VERDICT_HAS_ASSIGNMENT = 'has_assignment';
    /** Verdict: the user matches and would receive an assignment. */
    public const VERDICT_WOULD_GET = 'would_get';
    /** Verdict: at least one rule filter does not match. */
    public const VERDICT_BLOCKED_BY_FILTER = 'blocked_by_filter';
    /** Verdict: the user is not a member of the rule unit. */
    public const VERDICT_NOT_MEMBER = 'not_member';
    /** Verdict: the rule is inactive. */
    public const VERDICT_RULE_INACTIVE = 'rule_inactive';
    /** Verdict: the user account is suspended. */
    public const VERDICT_SUSPENDED = 'suspended';
    /** Verdict: the user is on long leave. */
    public const VERDICT_LONG_LEAVE = 'long_leave';

    /** Adhoc task classes (local_taskflow\task\*) that can still change the outcome. */
    public const PENDING_TASKS = ['update_rule', 'unit_updated'];

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
            'description' => 'Diagnose whether a rule produces an assignment for one person: unit membership '
                . '(including inheritance), rule state, every rule filter individually, account state, the '
                . 'existing assignment and pending adhoc tasks. Read-only.',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'Why does Anna Muster not get an assignment from rule 17?',
                'Does rule 17 apply to user 123?',
                'Which filter blocks anna.muster@example.org in rule 17?',
                'Check whether rule 17 reaches Bert Beispiel',
            ],
            'properties' => [
                'ruleid' => [
                    'type' => 'integer',
                    'description' => 'Id of the rule (find it with local_taskflow.search_rules).',
                    'required' => true,
                ],
                'userid' => [
                    'type' => 'integer',
                    'description' => 'User id of the person to diagnose.',
                    'required' => false,
                ],
                'userquery' => [
                    'type' => 'string',
                    'description' => 'User id, e-mail, username or name when the user id is unknown.',
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
            'intent' => 'Explain deterministically why a taskflow rule does or does not assign to one person.',
            'input_fields_for_prompt' => ['ruleid', 'userquery (or userid)'],
            'anchor_fields' => ['ruleid', 'userquery', 'userid'],
        ];
    }

    /**
     * Example input for the planner contract.
     *
     * @return array
     */
    public function get_example_input(): array {
        return ['ruleid' => 17, 'userquery' => 'anna.muster@example.org'];
    }

    /**
     * Structural check: ruleid and a user reference are required.
     *
     * @param array $input
     * @return array{valid:bool,errors:string[],ambiguities:string[]}
     */
    public function check_structure(array $input): array {
        $lang = $this->get_output_language($input);
        $errors = [];
        if ((taskflow_input_normalizer::to_int($input['ruleid'] ?? null) ?? 0) <= 0) {
            $errors[] = $this->localized_string('agent_invalid_ruleid', null, $lang);
        }
        $userid = taskflow_input_normalizer::to_int($input['userid'] ?? null) ?? 0;
        if ($userid <= 0 && trim((string)($input['userquery'] ?? '')) === '') {
            $errors[] = $this->localized_string('agent_userref_required', null, $lang);
        }
        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => []];
    }

    /**
     * Preflight: rule and user must exist, the acting user needs admin or supervisor scope.
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

        $targetuserid = $this->resolve_userid($input, 0);
        $user = $targetuserid > 0 ? \core_user::get_user($targetuserid, '*', IGNORE_MISSING) : null;
        if (!$user || !empty($user->deleted)) {
            return $this->invalid([$this->user_lookup_issue($input, $lang, $targetuserid)]);
        }

        if (!$this->may_diagnose($targetuserid, $userid)) {
            return $this->invalid([$this->scope_denied_issue($lang, ['field' => 'userid'])]);
        }

        $prepared = $input;
        $prepared['ruleid'] = $ruleid;
        $prepared['userid'] = $targetuserid;
        unset($prepared['userquery']);
        return $this->pass($prepared);
    }

    /**
     * Execute: run every gate of the assignment pipeline read-only.
     *
     * @param array $input Prepared input.
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        $lang = $this->get_output_language($input);
        $ruleid = taskflow_input_normalizer::to_int($input['ruleid'] ?? null) ?? 0;
        $targetuserid = taskflow_input_normalizer::to_int($input['userid'] ?? null) ?? 0;
        if ($targetuserid <= 0) {
            $targetuserid = $this->resolve_userid($input, 0);
        }
        $debug = $this->build_task_debug_message(self::TASK_NAME, $input);

        $rule = $this->resolve_rule($ruleid);
        if (empty($rule)) {
            return $this->error_result(
                self::ISSUE_RULE_NOT_FOUND,
                $this->localized_string('agent_notfound_rule', $ruleid, $lang),
                ['debugmessage' => $debug]
            );
        }
        $user = $targetuserid > 0 ? \core_user::get_user($targetuserid, '*', IGNORE_MISSING) : null;
        if (!$user || !empty($user->deleted)) {
            $issue = $this->user_lookup_issue($input, $lang, $targetuserid);
            return $this->error_result((string)$issue['code'], (string)$issue['message'], ['debugmessage' => $debug]);
        }
        if (!$this->may_diagnose($targetuserid, $userid)) {
            return $this->error_result(
                self::ISSUE_SCOPE_DENIED,
                $this->localized_string('agent_scope_denied', null, $lang),
                ['debugmessage' => $debug]
            );
        }

        $checks = [];
        $checks[] = $this->check_row(
            'agent_check_rule_exists',
            true,
            $this->localized_string('agent_detail_rule_found', (object)[
                'id' => $rule['id'],
                'name' => $rule['rulename'],
            ], $lang),
            $lang,
            taskflow_result_link_builder::edit_rule_url($ruleid)
        );
        $ruleactive = (bool)$rule['isactive'];
        $checks[] = $this->check_row('agent_check_rule_active', $ruleactive, '', $lang);
        $ruleenabled = !isset($rule['rule']['enabled']) || (int)$rule['rule']['enabled'] === 1;
        $checks[] = $this->check_row('agent_check_rule_enabled', $ruleenabled, '', $lang);

        $membership = $this->check_membership($rule, $targetuserid, $lang);
        $checks[] = $membership['row'];
        $checks[] = $this->check_row(
            'agent_check_inheritance_setting',
            null,
            $this->localized_string(
                'agent_detail_inheritance_setting',
                (string)get_config('local_taskflow', 'inheritance_option'),
                $lang
            ),
            $lang
        );

        $suspended = !empty($user->suspended);
        $checks[] = $this->check_row(
            'agent_check_user_active',
            !$suspended,
            $suspended ? $this->localized_string('agent_detail_suspended', null, $lang) : '',
            $lang
        );
        $longleave = $this->long_leave_state($targetuserid);
        $checks[] = $this->check_row(
            'agent_check_longleave',
            $longleave['field'] === '' ? null : !$longleave['onleave'],
            $longleave['field'] === ''
                ? $this->localized_string('agent_detail_longleave_unmapped', null, $lang)
                : $this->localized_string('agent_detail_longleave_field', (object)[
                    'field' => $longleave['field'],
                    'value' => $longleave['value'] === '' ? '-' : $longleave['value'],
                ], $lang),
            $lang
        );

        $filters = $this->evaluate_filters($rule, $targetuserid, $lang);
        foreach ($filters as $filter) {
            $checks[] = [
                'check' => $filter['label'],
                'passed' => $filter['result'],
                'detail' => $filter['note'],
            ];
        }
        if (empty($filters)) {
            $checks[] = $this->check_row(
                'agent_check_filters_overall',
                true,
                $this->localized_string('agent_detail_no_filters', null, $lang),
                $lang
            );
        } else {
            $checks[] = $this->check_row('agent_check_filters_overall', $this->rule_matches($rule, $targetuserid), '', $lang);
        }

        $existing = $this->existing_assignment($targetuserid, $ruleid, $lang);
        $checks[] = $this->check_row(
            'agent_check_existing_assignment',
            $existing === null ? null : true,
            $existing === null
                ? $this->localized_string('agent_detail_assignment_none', null, $lang)
                : $this->localized_string('agent_detail_assignment_exists', (object)[
                    'id' => $existing['id'],
                    'status' => $existing['statuslabel'],
                    'active' => get_string($existing['active'] ? 'yes' : 'no'),
                ], $lang),
            $lang,
            $existing === null ? '' : taskflow_result_link_builder::assignment_url($existing['id'])
        );

        $pending = $this->pending_tasks($ruleid, $rule['unitid']);
        $checks[] = [
            'check' => $this->localized_string('agent_check_pending_tasks', null, $lang),
            'passed' => empty($pending) ? true : null,
            'detail' => empty($pending)
                ? $this->localized_string('agent_detail_no_pending_tasks', null, $lang)
                : implode('; ', array_map(fn(array $task): string => $this->localized_string(
                    'agent_detail_pending_task',
                    (object)['name' => $task['name'], 'time' => $task['nextruntime_text']],
                    $lang
                ), $pending)),
        ];

        $verdict = $this->build_verdict(
            $existing,
            $ruleactive && $ruleenabled,
            $suspended,
            $longleave['onleave'],
            $membership['ismember'],
            $filters,
            $rule,
            $targetuserid
        );
        $verdictlabel = $this->localized_string('agent_verdict_' . $verdict, null, $lang);

        $links = $this->links(
            taskflow_result_link_builder::edit_rule_url($ruleid),
            ['rules_filters', 'assignments_due_dates', 'units_and_users'],
            $existing === null ? [] : ['assignment' => taskflow_result_link_builder::assignment_url($existing['id'])]
        );

        $usermessage = $this->localized_string('agent_diagnose_user_assignments_summary', (object)[
            'fullname' => fullname($user),
            'ruleid' => $ruleid,
            'rulename' => $rule['rulename'],
            'verdict' => $verdictlabel,
        ], $lang);

        $observation = [$usermessage, 'verdict=' . $verdict];
        foreach ($checks as $check) {
            $observation[] = sprintf(
                '[%s] %s%s',
                $check['passed'] === true ? 'ok' : ($check['passed'] === false ? 'fail' : 'info'),
                $check['check'],
                $check['detail'] !== '' ? ' — ' . $check['detail'] : ''
            );
        }
        foreach ($filters as $filter) {
            $observation[] = sprintf(
                'filter %s: %s %s "%s" => %s%s',
                $filter['filter'],
                $filter['field'],
                $filter['operator'],
                $filter['value'],
                $filter['result'] === null ? 'n/a' : ($filter['result'] ? 'true' : 'false'),
                $filter['note'] !== '' ? ' (' . $filter['note'] . ')' : ''
            );
        }

        return $this->base_result(self::STATUS_EXECUTED, [
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'observation_full' => implode("\n", $observation),
            'resultid' => $ruleid,
            'verdict' => $verdict,
            'verdict_label' => $verdictlabel,
            'user' => ['id' => $targetuserid, 'fullname' => fullname($user), 'suspended' => $suspended],
            'rule' => [
                'id' => $rule['id'],
                'rulename' => $rule['rulename'],
                'isactive' => $ruleactive,
                'enabled' => $ruleenabled,
                'unitid' => $rule['unitid'],
                'userid' => $rule['userid'],
            ],
            'checks' => $checks,
            'filters' => $filters,
            'membership' => $membership['data'],
            'existing_assignment' => $existing,
            'pending_tasks' => $pending,
            'links' => $links,
            'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, ['Verdict: ' . $verdict]),
            'preview' => [
                'type' => taskflow_preview_renderer_factory::TYPE_DIAGNOSTIC_CHECKLIST,
                'data' => [
                    'title' => $this->localized_string('agent_preview_diagnosis_title', (object)[
                        'subject' => fullname($user),
                        'object' => $this->localized_string('agent_preview_rule_heading', $ruleid, $lang),
                    ], $lang),
                    'rows' => $this->preview_rows($checks),
                    'verdict' => [
                        'code' => $verdict,
                        'label' => $verdictlabel,
                        'class' => $this->verdict_class($verdict),
                    ],
                    'links' => $links,
                    'ids' => ['userids' => [$targetuserid], 'ruleids' => [$ruleid]],
                ],
                'payload' => ['userids' => [$targetuserid], 'ruleids' => [$ruleid]],
            ],
        ]);
    }

    /**
     * Whether the acting user may diagnose the target user (admin or supervisor scope only).
     *
     * @param int $targetuserid
     * @param int $userid
     * @return bool
     */
    private function may_diagnose(int $targetuserid, int $userid): bool {
        $scope = $this->permissions()->scope_for_user($targetuserid, $userid);
        return in_array(
            $scope,
            [taskflow_permission_resolver::SCOPE_ADMIN, taskflow_permission_resolver::SCOPE_SUPERVISOR],
            true
        );
    }

    /**
     * Build one checklist row from a lang key.
     *
     * @param string $key Lang key of the check label.
     * @param bool|null $passed Null = informational row.
     * @param string $detail
     * @param string $lang
     * @param string $url
     * @return array{check:string,passed:bool|null,detail:string,url?:string}
     */
    private function check_row(string $key, ?bool $passed, string $detail, string $lang, string $url = ''): array {
        $row = [
            'check' => $this->localized_string($key, null, $lang),
            'passed' => $passed,
            'detail' => $detail,
        ];
        if ($url !== '') {
            $row['url'] = $url;
        }
        return $row;
    }

    /**
     * Membership of the user in the rule scope (unit, inherited unit or personal rule).
     *
     * @param array $rule
     * @param int $userid
     * @param string $lang
     * @return array{ismember:bool,row:array,data:array}
     */
    private function check_membership(array $rule, int $userid, string $lang): array {
        $unitid = (int)$rule['unitid'];
        $ruleuserid = (int)$rule['userid'];

        if ($unitid <= 0) {
            $ismember = $ruleuserid > 0 && $ruleuserid === $userid;
            $detail = $ruleuserid > 0
                ? ($ismember
                    ? $this->localized_string('agent_detail_personal_rule', null, $lang)
                    : $this->localized_string('agent_detail_personal_rule_other', $ruleuserid, $lang))
                : $this->localized_string('agent_detail_no_unit', null, $lang);
            return [
                'ismember' => $ismember,
                'row' => $this->check_row('agent_check_unit_membership', $ismember, $detail, $lang),
                'data' => ['unitid' => 0, 'unitname' => '', 'direct' => false, 'inherited' => false, 'via' => 0],
            ];
        }

        $unitname = $this->unit_name($unitid);
        if ($this->is_unit_member($unitid, $userid)) {
            return [
                'ismember' => true,
                'row' => $this->check_row(
                    'agent_check_unit_membership',
                    true,
                    $this->localized_string('agent_detail_member_direct', $unitname, $lang),
                    $lang
                ),
                'data' => ['unitid' => $unitid, 'unitname' => $unitname, 'direct' => true,
                    'inherited' => false, 'via' => $unitid],
            ];
        }

        $inheritance = !empty($rule['rule']['inheritance']);
        $recursive = !empty($rule['rule']['recursive']);
        $children = [];
        if ($inheritance || $recursive) {
            try {
                $children = array_map('intval', (new unit_hierarchy())->get_all_childerns((string)$unitid));
            } catch (\Throwable $e) {
                $children = [];
            }
        }
        foreach ($children as $childid) {
            if ($this->is_unit_member($childid, $userid)) {
                $childname = $this->unit_name($childid);
                return [
                    'ismember' => true,
                    'row' => $this->check_row(
                        'agent_check_unit_membership',
                        true,
                        $this->localized_string($inheritance
                            ? 'agent_detail_member_inherited' : 'agent_detail_member_recursive', $childname, $lang),
                        $lang
                    ),
                    'data' => ['unitid' => $unitid, 'unitname' => $unitname, 'direct' => false,
                        'inherited' => true, 'via' => $childid],
                ];
            }
        }

        return [
            'ismember' => false,
            'row' => $this->check_row(
                'agent_check_unit_membership',
                false,
                $this->localized_string('agent_detail_not_member', $unitname, $lang),
                $lang
            ),
            'data' => ['unitid' => $unitid, 'unitname' => $unitname, 'direct' => false,
                'inherited' => false, 'via' => 0],
        ];
    }

    /**
     * Whether the user is an active member of a unit (backend unit or cohort).
     *
     * @param int $unitid
     * @param int $userid
     * @return bool
     */
    private function is_unit_member(int $unitid, int $userid): bool {
        global $DB;

        if ($unitid <= 0 || $userid <= 0) {
            return false;
        }
        $record = $DB->get_record('local_taskflow_unit_members', ['unitid' => $unitid, 'userid' => $userid]);
        if ($record) {
            return (int)($record->active ?? 1) === 1;
        }
        try {
            $unit = organisational_unit_factory::instance($unitid);
        } catch (\Throwable $e) {
            return false;
        }
        return is_object($unit) && method_exists($unit, 'is_member') && (bool)$unit->is_member($userid);
    }

    /**
     * Name of an organisational unit ('' when unknown).
     *
     * @param int $unitid
     * @return string
     */
    private function unit_name(int $unitid): string {
        if ($unitid <= 0) {
            return '';
        }
        try {
            $unit = organisational_unit_factory::instance($unitid);
            return is_object($unit) && method_exists($unit, 'get_name') ? (string)$unit->get_name() : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Long leave state derived from the adapter-mapped profile field.
     *
     * @param int $userid
     * @return array{field:string,value:string,onleave:bool}
     */
    private function long_leave_state(int $userid): array {
        global $CFG;
        require_once($CFG->dirroot . '/user/profile/lib.php');

        $shortname = (string)external_api_base::return_shortname_for_functionname(
            taskflowadapter::TRANSLATOR_USER_LONG_LEAVE
        );
        if ($shortname === '') {
            return ['field' => '', 'value' => '', 'onleave' => false];
        }
        $record = profile_user_record($userid, false);
        $value = property_exists($record, $shortname) ? (string)$record->{$shortname} : '';
        return ['field' => $shortname, 'value' => $value, 'onleave' => $value !== '' && $value !== '0'];
    }

    /**
     * Evaluate every rule filter individually through filter_factory.
     *
     * @param array $rule
     * @param int $userid
     * @param string $lang
     * @return array<int,array<string,mixed>>
     */
    private function evaluate_filters(array $rule, int $userid, string $lang): array {
        $definitions = (array)($rule['rule']['filter'] ?? []);
        $instance = rules::instance((int)$rule['id']);
        $factory = new filter_factory();
        $filters = [];

        foreach ($definitions as $definition) {
            $definition = (object)$definition;
            $type = (string)($definition->filtertype ?? '');
            $field = (string)($definition->userprofilefield ?? ($definition->userfield ?? ''));
            $operator = (string)($definition->operator ?? '');
            $value = (string)($definition->value ?? '');
            $key = (string)($definition->key ?? '');
            $label = $this->localized_string('agent_check_filter', (object)[
                'field' => $field . ($key !== '' ? '.' . $key : ''),
                'operator' => $operator,
                'value' => $value,
            ], $lang);

            $filterinstance = null;
            try {
                $filterinstance = $factory->instance($definition);
            } catch (\Throwable $e) {
                $filterinstance = null;
            }
            if ($filterinstance === null) {
                $filters[] = [
                    'filter' => $type,
                    'field' => $field,
                    'key' => $key,
                    'operator' => $operator,
                    'value' => $value,
                    'result' => null,
                    'label' => $label,
                    'note' => $this->localized_string('agent_detail_filter_unsupported', $type, $lang),
                ];
                continue;
            }
            try {
                $result = (bool)$filterinstance->is_valid($instance, $userid);
                $note = '';
            } catch (\Throwable $e) {
                $result = null;
                $note = $this->localized_string('agent_detail_filter_error', $e->getMessage(), $lang);
            }
            $filters[] = [
                'filter' => $type,
                'field' => $field,
                'key' => $key,
                'operator' => $operator,
                'value' => $value,
                'result' => $result,
                'label' => $label,
                'note' => $note,
            ];
        }

        return $filters;
    }

    /**
     * Overall filter verdict of the runtime gate (filter_operator).
     *
     * @param array $rule
     * @param int $userid
     * @return bool
     */
    private function rule_matches(array $rule, int $userid): bool {
        try {
            return (bool)(new filter_operator($userid))->is_rule_active_for_user(rules::instance((int)$rule['id']));
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Newest assignment of the user for the rule, or null.
     *
     * @param int $userid
     * @param int $ruleid
     * @param string $lang
     * @return array{id:int,status:int,statuslabel:string,active:bool,duedate:int,duedate_text:string}|null
     */
    private function existing_assignment(int $userid, int $ruleid, string $lang): ?array {
        try {
            $record = standard_assignment::get_assignment_by_userid_ruleid(
                (object)['userid' => $userid, 'ruleid' => $ruleid]
            );
        } catch (\Throwable $e) {
            $record = false;
        }
        if (!is_object($record) || empty($record->id)) {
            return null;
        }
        $status = (int)($record->status ?? 0);
        $duedate = (int)($record->duedate ?? 0);
        return [
            'id' => (int)$record->id,
            'status' => $status,
            'statuslabel' => $this->status_label($status, $lang),
            'active' => !empty($record->active),
            'duedate' => $duedate,
            'duedate_text' => $this->format_time($duedate),
        ];
    }

    /**
     * Pending adhoc tasks that would still change the outcome for this rule or unit.
     *
     * @param int $ruleid
     * @param int $unitid
     * @return array<int,array<string,mixed>>
     */
    private function pending_tasks(int $ruleid, int $unitid): array {
        global $DB;

        $classnames = array_map(
            static fn(string $name): string => '\\local_taskflow\\task\\' . $name,
            self::PENDING_TASKS
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
            $customdata = is_array($customdata) ? $customdata : [];
            $matchesrule = $ruleid > 0 && (int)($customdata['ruleid'] ?? ($customdata['objectid'] ?? 0)) === $ruleid;
            $matchesunit = $unitid > 0 && (int)($customdata['unitid'] ?? (($customdata['other'] ?? [])['unitid'] ?? 0))
                === $unitid;
            if (!$matchesrule && !$matchesunit) {
                continue;
            }
            $tasks[] = [
                'id' => (int)$record->id,
                'classname' => (string)$record->classname,
                'name' => ltrim(substr((string)$record->classname, strrpos((string)$record->classname, '\\')), '\\'),
                'nextruntime' => (int)$record->nextruntime,
                'nextruntime_text' => $this->format_time((int)$record->nextruntime),
                'started' => !empty($record->timestarted),
                'customdata' => $customdata,
            ];
        }
        return $tasks;
    }

    /**
     * Deterministic verdict from the collected facts, in the order the pipeline evaluates them.
     *
     * @param array|null $existing
     * @param bool $ruleactive
     * @param bool $suspended
     * @param bool $longleave
     * @param bool $ismember
     * @param array $filters
     * @param array $rule
     * @param int $userid
     * @return string
     */
    private function build_verdict(
        ?array $existing,
        bool $ruleactive,
        bool $suspended,
        bool $longleave,
        bool $ismember,
        array $filters,
        array $rule,
        int $userid
    ): string {
        if ($existing !== null && $existing['active']) {
            return self::VERDICT_HAS_ASSIGNMENT;
        }
        if (!$ruleactive) {
            return self::VERDICT_RULE_INACTIVE;
        }
        if ($suspended) {
            return self::VERDICT_SUSPENDED;
        }
        if (!$ismember) {
            return self::VERDICT_NOT_MEMBER;
        }
        foreach ($filters as $filter) {
            if ($filter['result'] === false) {
                return self::VERDICT_BLOCKED_BY_FILTER;
            }
        }
        if (!$this->rule_matches($rule, $userid)) {
            return self::VERDICT_BLOCKED_BY_FILTER;
        }
        if ($longleave) {
            return self::VERDICT_LONG_LEAVE;
        }
        if ($existing !== null) {
            return self::VERDICT_HAS_ASSIGNMENT;
        }
        return self::VERDICT_WOULD_GET;
    }

    /**
     * Badge class of a verdict (ok = assignment exists or would be created).
     *
     * @param string $verdict
     * @return string
     */
    private function verdict_class(string $verdict): string {
        if (in_array($verdict, [self::VERDICT_HAS_ASSIGNMENT, self::VERDICT_WOULD_GET], true)) {
            return 'ok';
        }
        if ($verdict === self::VERDICT_LONG_LEAVE) {
            return 'warn';
        }
        return 'fail';
    }

    /**
     * Turn the checks into checklist preview rows.
     *
     * @param array $checks
     * @return array<int,array<string,mixed>>
     */
    private function preview_rows(array $checks): array {
        $rows = [];
        foreach ($checks as $check) {
            $rows[] = [
                'status' => $check['passed'] === true ? 'ok' : ($check['passed'] === false ? 'fail' : 'warn'),
                'check' => $check['check'],
                'detail' => $check['detail'],
                'url' => (string)($check['url'] ?? ''),
            ];
        }
        return $rows;
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
