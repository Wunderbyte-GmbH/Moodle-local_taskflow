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

namespace local_taskflow\local\wizard\taskflow;

use local_taskflow\form\filters\filter as filterform;
use local_taskflow\form\filters\types\user_field as userfieldfilterform;
use local_taskflow\form\filters\types\user_profile_field as userprofilefieldfilterform;
use local_taskflow\form\messages\messages as messagesform;
use local_taskflow\form\requests\requests as requestsform;
use local_taskflow\form\rules\rule as ruleform;
use local_taskflow\form\targets\target as targetform;
use local_taskflow\local\actions\targets\targets_factory;
use local_taskflow\local\assignment_operators\filter_operator;
use local_taskflow\local\operators\string_compare_operators;
use local_taskflow\local\requests\request_receivers\receiver_facade;
use local_taskflow\local\requests\request_types\requests_manager;
use local_taskflow\local\units\organisational_unit_factory;
use local_taskflow\local\units\organisational_units_factory;
use local_taskflow\local\units\unit_hierarchy;
use local_taskflow\taskflow_stringmanager;

/**
 * Validation, step assembly and dry run for the rule-writing agent skills (#18/#19).
 *
 * Everything this class validates against comes from the classes that define the option in the
 * rule form (string_compare_operators, form\filters\types\*, form\targets\types\*,
 * requests_manager, receiver_facade, organisational_units_factory) — the very sources
 * local_taskflow.list_rule_properties reports, so the agent can never write a value the UI would
 * refuse. build_steps() produces the step array the multistep form submits, which
 * rule_persistence_service::build_from_steps() turns into the ruledata/rulejson document; no JSON
 * is hand-built anywhere.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class taskflow_rule_builder {
    /** Rule type: applies to an organisational unit. */
    public const RULETYPE_UNIT = 'unit';
    /** Rule type: applies to one person. */
    public const RULETYPE_USER = 'user';

    /** Due date model: duration after assignment. */
    public const DUEDATE_DURATION = 'duration';
    /** Due date model: one fixed date. */
    public const DUEDATE_FIXEDDATE = 'fixeddate';

    /** Request receiver token: request type disabled for this rule. */
    public const RECEIVER_NOT_ALLOWED = 'not_allowed';
    /** Request receiver token: the assignee's supervisor decides. */
    public const RECEIVER_SUPERVISOR = 'supervisor';
    /** Request receiver token: HR decides. */
    public const RECEIVER_HR = 'hr';

    /** Issue code: a field carries a value the rule form would not accept. */
    public const ISSUE_INVALID_VALUE = 'TASKFLOW_INVALID_VALUE';
    /** Issue code: a mandatory field is missing. */
    public const ISSUE_MISSING_VALUE = 'TASKFLOW_MISSING_VALUE';
    /** Issue code: a referenced target (course, booking option, competency) does not exist. */
    public const ISSUE_TARGET_NOT_FOUND = 'TASKFLOW_TARGET_NOT_FOUND';
    /** Issue code: a referenced message template does not exist. */
    public const ISSUE_MESSAGE_NOT_FOUND = 'TASKFLOW_MESSAGE_NOT_FOUND';
    /** Issue code: the organisational unit does not exist. */
    public const ISSUE_UNIT_NOT_FOUND = 'TASKFLOW_UNIT_NOT_FOUND';

    /** Maximum number of unit members evaluated in the affected-users dry run. */
    public const DRYRUN_MEMBER_CAP = 500;

    /** @var string Output language of the messages ('' = current language). */
    private string $lang;

    /**
     * Constructor.
     *
     * @param string $lang Output language ('' = current language).
     */
    public function __construct(string $lang = '') {
        $this->lang = trim($lang);
    }

    /**
     * Comparison operator keys offered by the filter step.
     *
     * @return string[]
     */
    public static function operator_keys(): array {
        return array_map('strval', array_keys((new string_compare_operators())->get_operator_keys_and_values()));
    }

    /**
     * Filter type keys the filter step can persist (one class per type in form\filters\types).
     *
     * @return string[]
     */
    public static function filter_type_keys(): array {
        return self::class_basenames(__DIR__ . '/../../../form/filters/types', 'local_taskflow\\form\\filters\\types\\');
    }

    /**
     * Target type keys the target step offers (bookingoption only when mod_booking is installed).
     *
     * @return string[]
     */
    public static function target_type_keys(): array {
        $types = self::class_basenames(__DIR__ . '/../../../form/targets/types', 'local_taskflow\\form\\targets\\types\\');
        if (!class_exists('mod_booking\\booking')) {
            $types = array_values(array_filter($types, static fn(string $type): bool => $type !== 'bookingoption'));
        }
        return $types;
    }

    /**
     * Request type keys that are enabled site wide (requests_manager).
     *
     * @return string[]
     */
    public static function request_type_keys(): array {
        return array_map('strval', array_keys((new requests_manager())->get_active_request_types()));
    }

    /**
     * Receiver token => value stored in actions[0].requests (receiver ids from receiver_facade).
     *
     * @return array<string,string>
     */
    public static function receiver_tokens(): array {
        $tokens = [self::RECEIVER_NOT_ALLOWED => self::RECEIVER_NOT_ALLOWED];
        foreach (receiver_facade::get_request_receivers() as $id => $receiver) {
            $classname = get_class($receiver);
            $token = strpos($classname, 'hr_receiver') !== false ? self::RECEIVER_HR : self::RECEIVER_SUPERVISOR;
            $tokens[$token] = (string)$id;
        }
        return $tokens;
    }

    /**
     * Organisational units (id => name) exactly as the rule form offers them.
     *
     * @return array<int,string>
     */
    public static function units(): array {
        try {
            return (array)organisational_units_factory::instance()->get_units();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Normalized rule document of an existing rule (input of an update).
     *
     * @param array $resolved Result of taskflow_skill_base::resolve_rule().
     * @return array
     */
    public static function from_stored(array $resolved): array {
        $document = (array)($resolved['rule'] ?? []);
        $actions = array_values(array_filter((array)($document['actions'] ?? []), 'is_array'));
        $action = empty($actions) ? [] : (array)end($actions);

        $filters = [];
        foreach ((array)($document['filter'] ?? []) as $filter) {
            if (!is_array($filter)) {
                continue;
            }
            $filters[] = [
                'filtertype' => (string)($filter['filtertype'] ?? 'user_profile_field'),
                'field' => (string)($filter['userprofilefield'] ?? ($filter['userfield'] ?? '')),
                'operator' => (string)($filter['operator'] ?? ''),
                'value' => (string)($filter['value'] ?? ''),
                'date' => (int)($filter['date'] ?? 0),
            ];
        }

        $targets = [];
        foreach ((array)($action['targets'] ?? []) as $target) {
            if (!is_array($target)) {
                continue;
            }
            $targets[] = [
                'targettype' => (string)($target['targettype'] ?? ''),
                'targetid' => (int)($target['targetid'] ?? 0),
                'completebeforenext' => ($target['completebeforenext'] ?? 0) == '1' ? 1 : 0,
            ];
        }

        $messageids = [];
        foreach ((array)($action['messages'] ?? []) as $message) {
            $id = is_array($message) ? (int)($message['messageid'] ?? 0) : (int)$message;
            if ($id > 0 && !in_array($id, $messageids, true)) {
                $messageids[] = $id;
            }
        }

        $tokens = array_flip(self::receiver_tokens());
        $requests = [];
        foreach ((array)($action['requests'] ?? []) as $key => $value) {
            $type = (string)preg_replace('/^receiver_/', '', (string)$key);
            $requests[$type] = (string)($tokens[(string)$value] ?? self::RECEIVER_NOT_ALLOWED);
        }

        $userid = (int)($resolved['userid'] ?? 0);
        $unitid = (int)($resolved['unitid'] ?? 0);

        return [
            'recordid' => (int)($resolved['id'] ?? 0),
            'name' => (string)($resolved['rulename'] ?? ($document['name'] ?? '')),
            'description' => (string)($document['description'] ?? ''),
            'ruletype' => $userid > 0 && $unitid <= 0 ? self::RULETYPE_USER : self::RULETYPE_UNIT,
            'unitid' => $unitid,
            'userid' => $userid,
            'enabled' => empty($resolved['isactive']) ? 0 : 1,
            'recursive' => ($document['recursive'] ?? 0) == '1' ? 1 : 0,
            'inheritance' => ($document['inheritance'] ?? 0) == '1' ? 1 : 0,
            'cyclicvalidation' => ($document['cyclicvalidation'] ?? 0) == '1' ? 1 : 0,
            'cyclicduration' => (int)($document['cyclicduration'] ?? 0),
            'activationdelay' => (int)($document['activationdelay'] ?? 0),
            'duedatetype' => (string)($document['duedatetype'] ?? self::DUEDATE_DURATION),
            'duration' => (int)($document['duration'] ?? 0),
            'fixeddate' => (int)($document['fixeddate'] ?? 0),
            'extensionperiod' => (int)($document['extensionperiod'] ?? 0),
            'timecreated' => (int)($document['timecreated'] ?? 0),
            'filters' => $filters,
            'targets' => $targets,
            'messageids' => $messageids,
            'requests' => $requests,
        ];
    }

    /**
     * Default document of a brand new rule (defaults of form\rules\rule).
     *
     * @return array
     */
    public static function defaults(): array {
        $requests = [];
        foreach (self::request_type_keys() as $type) {
            $requests[$type] = self::RECEIVER_NOT_ALLOWED;
        }
        return [
            'recordid' => 0,
            'name' => '',
            'description' => '',
            'ruletype' => self::RULETYPE_UNIT,
            'unitid' => 0,
            'userid' => 0,
            'enabled' => 1,
            'recursive' => 0,
            'inheritance' => 0,
            'cyclicvalidation' => 0,
            'cyclicduration' => YEARSECS,
            'activationdelay' => 0,
            'duedatetype' => self::DUEDATE_DURATION,
            'duration' => 4 * WEEKSECS,
            'fixeddate' => 0,
            'extensionperiod' => 4 * WEEKSECS,
            'timecreated' => 0,
            'filters' => [],
            'targets' => [],
            'messageids' => [],
            'requests' => $requests,
        ];
    }

    /**
     * Merge the skill input into a rule document and validate every value against the catalogue.
     *
     * Only keys present in $input change; everything else keeps the value of $current (update) or
     * the form default (create). Returns the merged document plus a list of preflight issues.
     *
     * @param array $input Skill input.
     * @param array $current Current document (defaults() for a new rule).
     * @return array{rule:array,issues:array<int,array<string,mixed>>,changed:string[]}
     */
    public function normalize(array $input, array $current): array {
        $rule = $current;
        $issues = [];

        $this->merge_scalars($input, $rule, $issues);
        $this->merge_scope($input, $rule, $issues);
        $this->merge_duedate($input, $rule, $issues);
        $this->merge_filters($input, $rule, $issues);
        $this->merge_targets($input, $rule, $issues);
        $this->merge_messages($input, $rule, $issues);
        $this->merge_requests($input, $rule, $issues);

        if (trim((string)$rule['name']) === '') {
            $issues[] = $this->missing_issue('name');
        }

        $changed = [];
        foreach ($rule as $key => $value) {
            if (json_encode($value) !== json_encode($current[$key] ?? null)) {
                $changed[] = (string)$key;
            }
        }

        return ['rule' => $rule, 'issues' => $issues, 'changed' => $changed];
    }

    /**
     * Build the multistep-form steps of a normalized rule document.
     *
     * Step 1 is the rule step, then filter, target, messages and requests — the order and the
     * element names of the real form; taskflow_rule_form_step forwards each step to the real class.
     *
     * @param array $rule Normalized document (normalize()['rule']).
     * @return array<int,array<string,mixed>>
     */
    public function build_steps(array $rule): array {
        $steps = [];

        $steps[1] = [
            'formclass' => taskflow_rule_form_step::class,
            taskflow_rule_form_step::DELEGATE_KEY => ruleform::class,
            'stepidentifier' => 'rule',
            'recordid' => (int)$rule['recordid'] > 0 ? (int)$rule['recordid'] : null,
            'name' => (string)$rule['name'],
            'description' => (string)$rule['description'],
            'ruletype' => (string)$rule['ruletype'],
            'enabled' => (int)$rule['enabled'],
            'recursive' => (int)$rule['recursive'],
            'inheritance' => (int)$rule['inheritance'],
            'unitid' => (int)$rule['unitid'],
            'userid' => (int)$rule['userid'],
            'cyclicvalidation' => (int)$rule['cyclicvalidation'],
            'cyclicduration' => (int)$rule['cyclicduration'],
            'activationdelay' => (int)$rule['activationdelay'],
            'duedatetype' => (string)$rule['duedatetype'],
            'duration' => (int)$rule['duration'],
            'fixeddate' => (int)$rule['fixeddate'],
            'extensionperiod' => (int)$rule['extensionperiod'],
            // Known defect D-22 of unit_rule::get_data(): the expression is inverted, so a non-empty
            // value here is replaced by "now". Passing the stored value reproduces the form exactly.
            'timecreated' => (int)$rule['timecreated'],
        ];

        $filterstep = [
            'formclass' => taskflow_rule_form_step::class,
            taskflow_rule_form_step::DELEGATE_KEY => filterform::class,
            'stepidentifier' => 'filter',
            'filtertype' => [],
        ];
        foreach ((array)$rule['filters'] as $filter) {
            $type = (string)$filter['filtertype'];
            $filterstep['filtertype'][] = $type;
            $filterstep[$type . '_' . ($type === 'user_field' ? 'userfield' : 'userprofilefield')][] = (string)$filter['field'];
            $filterstep[$type . '_operator'][] = (string)$filter['operator'];
            $filterstep[$type . '_value'][] = (string)$filter['value'];
            $filterstep[$type . '_date'][] = (int)$filter['date'];
        }
        $steps[2] = $filterstep;

        $targetstep = [
            'formclass' => taskflow_rule_form_step::class,
            taskflow_rule_form_step::DELEGATE_KEY => targetform::class,
            'stepidentifier' => 'target',
            'targettype' => [],
            'completebeforenext' => [],
        ];
        foreach ((array)$rule['targets'] as $target) {
            $type = (string)$target['targettype'];
            $targetstep['targettype'][] = $type;
            $targetstep[$type . '_targetid'][] = (int)$target['targetid'];
            $targetstep['completebeforenext'][] = (int)$target['completebeforenext'];
        }
        $steps[3] = $targetstep;

        $steps[4] = [
            'formclass' => taskflow_rule_form_step::class,
            taskflow_rule_form_step::DELEGATE_KEY => messagesform::class,
            'stepidentifier' => 'messages',
            'messageids' => array_map('intval', (array)$rule['messageids']),
        ];

        $requeststep = [
            'formclass' => taskflow_rule_form_step::class,
            taskflow_rule_form_step::DELEGATE_KEY => requestsform::class,
            'stepidentifier' => 'requests',
        ];
        $tokens = self::receiver_tokens();
        foreach ((array)$rule['requests'] as $type => $token) {
            $requeststep['receiver_' . $type] = (string)($tokens[(string)$token] ?? self::RECEIVER_NOT_ALLOWED);
        }
        $steps[5] = $requeststep;

        return $steps;
    }

    /**
     * Dry run: how many people the rule would currently apply to (read-only).
     *
     * Members are evaluated with local\assignment_operators\filter_operator — the same code the
     * assignment engine uses — against a stand-in for the proposed rule. At most $cap members are
     * evaluated; the result says whether the scan was capped.
     *
     * @param array $rule Normalized document.
     * @param string $rulejson The encoded rulejson produced by the form path.
     * @param int $cap Maximum number of members to evaluate.
     * @return array{matched:int,scanned:int,members:int,capped:bool}
     */
    public function affected_users(array $rule, string $rulejson, int $cap = self::DRYRUN_MEMBER_CAP): array {
        $cap = max(1, $cap);
        $members = $this->candidate_members($rule);
        $scanlist = array_slice($members, 0, $cap);

        $candidate = new taskflow_rule_candidate(
            (int)$rule['recordid'],
            (int)$rule['unitid'],
            (int)$rule['enabled'],
            $rulejson
        );

        $matched = 0;
        foreach ($scanlist as $memberid) {
            try {
                if ((new filter_operator((int)$memberid))->is_rule_active_for_user($candidate)) {
                    $matched++;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return [
            'matched' => $matched,
            'scanned' => count($scanlist),
            'members' => count($members),
            'capped' => count($members) > count($scanlist),
        ];
    }

    /**
     * Preview rows shared by the rule-writing skills (target group, due date, filters, targets, ...).
     *
     * Text only — the confirmation renderer escapes everything and shows no links.
     *
     * @param array $rule Normalized document.
     * @return array<int,array{label:string,value:string}>
     */
    public function preview_rows(array $rule): array {
        $rows = [];
        $rows[] = ['label' => $this->str('agent_rule_row_targetgroup'), 'value' => $this->scope_text($rule)];
        $rows[] = ['label' => $this->str('agent_rule_row_duedate'), 'value' => $this->duedate_text($rule)];
        if ((int)$rule['extensionperiod'] > 0) {
            $rows[] = ['label' => $this->str('extensionperiod'), 'value' => format_time((int)$rule['extensionperiod'])];
        }
        if (!empty($rule['cyclicvalidation'])) {
            $rows[] = [
                'label' => $this->str('cyclicvalidation'),
                'value' => $this->str('agent_preview_cyclic_every', format_time((int)$rule['cyclicduration'])),
            ];
        }
        if ((int)$rule['activationdelay'] > 0) {
            $rows[] = ['label' => $this->str('activationdelay'), 'value' => format_time((int)$rule['activationdelay'])];
        }
        $rows[] = [
            'label' => $this->str('agent_rule_row_filters'),
            'value' => $this->filter_text((array)$rule['filters']) ?: $this->str('agent_preview_none'),
        ];
        $rows[] = [
            'label' => $this->str('agent_rule_row_targets'),
            'value' => $this->target_text((array)$rule['targets']) ?: $this->str('agent_preview_none'),
        ];
        $rows[] = [
            'label' => $this->str('agent_rule_row_messages'),
            'value' => $this->message_text((array)$rule['messageids']) ?: $this->str('agent_preview_none'),
        ];
        $rows[] = ['label' => $this->str('agent_rule_row_requests'), 'value' => $this->request_text((array)$rule['requests'])];
        return $rows;
    }

    /**
     * Readable target group of the rule.
     *
     * @param array $rule
     * @return string
     */
    public function scope_text(array $rule): string {
        if ((string)$rule['ruletype'] === self::RULETYPE_USER) {
            return $this->str('agent_rule_scope_user', (int)$rule['userid']);
        }
        $units = self::units();
        $name = (string)($units[(int)$rule['unitid']] ?? ('#' . (int)$rule['unitid']));
        $flags = [];
        if (!empty($rule['inheritance'])) {
            $flags[] = $this->str('inheritance');
        }
        if (!empty($rule['recursive'])) {
            $flags[] = $this->str('recursive');
        }
        return $name . (empty($flags) ? '' : ' (' . implode(', ', $flags) . ')');
    }

    /**
     * Readable due-date model.
     *
     * @param array $rule
     * @return string
     */
    public function duedate_text(array $rule): string {
        if ((string)$rule['duedatetype'] === self::DUEDATE_FIXEDDATE) {
            return $this->str('agent_preview_duedate_fixed', userdate((int)$rule['fixeddate']));
        }
        return $this->str('agent_preview_duedate_duration', format_time((int)$rule['duration']));
    }

    /**
     * Readable filter list ('' when the rule has no filter).
     *
     * @param array $filters
     * @return string
     */
    public function filter_text(array $filters): string {
        $labels = (new string_compare_operators())->get_operator_keys_and_values();
        $parts = [];
        foreach ($filters as $filter) {
            $operator = (string)$filter['operator'];
            $value = (string)$filter['value'] !== '' ? (string)$filter['value'] : userdate((int)$filter['date']);
            $parts[] = $filter['field'] . ' ' . (string)($labels[$operator] ?? $operator) . ' ' . $value;
        }
        return implode('; ', $parts);
    }

    /**
     * Readable target list with resolved names ('' when the rule has no target).
     *
     * @param array $targets
     * @return string
     */
    public function target_text(array $targets): string {
        $parts = [];
        foreach ($targets as $target) {
            $name = self::target_name((string)$target['targettype'], (int)$target['targetid']);
            $parts[] = $target['targettype'] . ' ' . ($name !== '' ? $name : '#' . (int)$target['targetid']);
        }
        return implode('; ', $parts);
    }

    /**
     * Readable message-template list ('' when the rule has none).
     *
     * @param array $messageids
     * @return string
     */
    public function message_text(array $messageids): string {
        global $DB;

        if (empty($messageids)) {
            return '';
        }
        $records = $DB->get_records_list('local_taskflow_messages', 'id', $messageids, '', 'id, name');
        $parts = [];
        foreach ($messageids as $id) {
            $parts[] = isset($records[$id]) ? (string)$records[$id]->name : ('#' . (int)$id);
        }
        return implode('; ', $parts);
    }

    /**
     * Readable self-service request settings.
     *
     * @param array $requests
     * @return string
     */
    public function request_text(array $requests): string {
        $labels = [
            self::RECEIVER_NOT_ALLOWED => 'agent_preview_request_not_allowed',
            self::RECEIVER_SUPERVISOR => 'agent_preview_request_supervisor',
            self::RECEIVER_HR => 'agent_preview_request_hr',
        ];
        $parts = [];
        foreach ($requests as $type => $token) {
            $parts[] = $this->str((string)$type . '_title') . ': '
                . $this->str((string)($labels[(string)$token] ?? 'agent_preview_request_not_allowed'));
        }
        return empty($parts) ? $this->str('agent_preview_none') : implode('; ', $parts);
    }

    /**
     * Readable dry-run result including the scan cap.
     *
     * @param array $dryrun affected_users() result.
     * @return string
     */
    public function dryrun_text(array $dryrun): string {
        $text = $this->str('agent_rule_affected_users', (object)[
            'matched' => (int)$dryrun['matched'],
            'scanned' => (int)$dryrun['scanned'],
        ]);
        if (!empty($dryrun['capped'])) {
            $text .= ' ' . $this->str('agent_rule_affected_users_capped', (object)[
                'scanned' => (int)$dryrun['scanned'],
                'members' => (int)$dryrun['members'],
            ]);
        }
        return $text;
    }

    /**
     * Readable value of one normalized rule field (used for the old → new rows of an update).
     *
     * @param array $rule Normalized document.
     * @param string $field
     * @return string
     */
    public function field_text(array $rule, string $field): string {
        switch ($field) {
            case 'filters':
                return $this->filter_text((array)$rule['filters']) ?: $this->str('agent_preview_none');
            case 'targets':
                return $this->target_text((array)$rule['targets']) ?: $this->str('agent_preview_none');
            case 'messageids':
                return $this->message_text((array)$rule['messageids']) ?: $this->str('agent_preview_none');
            case 'requests':
                return $this->request_text((array)$rule['requests']);
            case 'duedatetype':
            case 'duration':
            case 'fixeddate':
                return $this->duedate_text($rule);
            case 'unitid':
            case 'userid':
            case 'ruletype':
            case 'inheritance':
            case 'recursive':
                return $this->scope_text($rule);
            case 'cyclicduration':
            case 'activationdelay':
            case 'extensionperiod':
                return format_time((int)$rule[$field]);
            case 'cyclicvalidation':
            case 'enabled':
                return empty($rule[$field]) ? get_string('no') : get_string('yes');
            default:
                return (string)($rule[$field] ?? '');
        }
    }

    /**
     * Localized label of a normalized rule field.
     *
     * @param string $field
     * @return string
     */
    public function field_label(string $field): string {
        $map = [
            'name' => 'name',
            'description' => 'description',
            'ruletype' => 'agent_rule_row_targetgroup',
            'unitid' => 'agent_rule_row_targetgroup',
            'userid' => 'agent_rule_row_targetgroup',
            'inheritance' => 'inheritance',
            'recursive' => 'recursive',
            'enabled' => 'agent_rule_row_enabled',
            'duedatetype' => 'agent_rule_row_duedate',
            'duration' => 'agent_rule_row_duedate',
            'fixeddate' => 'agent_rule_row_duedate',
            'extensionperiod' => 'extensionperiod',
            'cyclicvalidation' => 'cyclicvalidation',
            'cyclicduration' => 'cyclicduration',
            'activationdelay' => 'activationdelay',
            'filters' => 'agent_rule_row_filters',
            'targets' => 'agent_rule_row_targets',
            'messageids' => 'agent_rule_row_messages',
            'requests' => 'agent_rule_row_requests',
        ];
        $key = (string)($map[$field] ?? $field);
        if ($key === 'name' || $key === 'description') {
            return get_string($key, 'moodle', null, $this->lang === '' ? null : $this->lang);
        }
        return $this->str($key);
    }

    /**
     * Localized name of a target ('' when it does not exist).
     *
     * @param string $type
     * @param int $targetid
     * @return string
     */
    public static function target_name(string $type, int $targetid): string {
        if ($type === '' || $targetid <= 0) {
            return '';
        }
        try {
            return (string)targets_factory::get_name($type, $targetid);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Users the rule would be evaluated for: the person of a user rule, else the unit members.
     *
     * @param array $rule
     * @return int[]
     */
    private function candidate_members(array $rule): array {
        if ((string)$rule['ruletype'] === self::RULETYPE_USER) {
            return (int)$rule['userid'] > 0 ? [(int)$rule['userid']] : [];
        }

        $unitids = [(int)$rule['unitid']];
        if (!empty($rule['inheritance'])) {
            try {
                $children = (new unit_hierarchy())->get_all_childerns((int)$rule['unitid']);
                $unitids = array_merge($unitids, array_map('intval', (array)$children));
            } catch (\Throwable $e) {
                $unitids = [(int)$rule['unitid']];
            }
        }

        $members = [];
        foreach (array_unique($unitids) as $unitid) {
            if ($unitid <= 0) {
                continue;
            }
            try {
                $unit = organisational_unit_factory::instance($unitid);
            } catch (\Throwable $e) {
                continue;
            }
            if (!is_object($unit) || !method_exists($unit, 'get_members')) {
                continue;
            }
            foreach ((array)$unit->get_members() as $memberid) {
                $members[(int)$memberid] = (int)$memberid;
            }
        }
        return array_values($members);
    }

    /**
     * Merge and validate the scalar rule-step fields.
     *
     * @param array $input
     * @param array $rule
     * @param array $issues
     * @return void
     */
    private function merge_scalars(array $input, array &$rule, array &$issues): void {
        if (array_key_exists('name', $input)) {
            $rule['name'] = trim((string)$input['name']);
        }
        if (array_key_exists('description', $input)) {
            $rule['description'] = trim((string)$input['description']);
        }
        foreach (['enabled', 'isactive'] as $key) {
            if (array_key_exists($key, $input)) {
                $rule['enabled'] = taskflow_input_normalizer::to_bool($input[$key]) ? 1 : 0;
            }
        }
        foreach (['recursive', 'inheritance', 'cyclicvalidation'] as $key) {
            if (array_key_exists($key, $input)) {
                $rule[$key] = taskflow_input_normalizer::to_bool($input[$key]) ? 1 : 0;
            }
        }
        foreach (['cyclicduration', 'activationdelay', 'extensionperiod'] as $key) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $seconds = $this->to_seconds($input[$key], $key, $issues);
            if ($seconds !== null) {
                $rule[$key] = $seconds;
            }
        }
    }

    /**
     * Merge and validate the rule scope (unit rule or personal rule).
     *
     * @param array $input
     * @param array $rule
     * @param array $issues
     * @return void
     */
    private function merge_scope(array $input, array &$rule, array &$issues): void {
        global $DB;

        if (array_key_exists('ruletype', $input)) {
            $ruletype = strtolower(trim((string)$input['ruletype']));
            if (!in_array($ruletype, [self::RULETYPE_UNIT, self::RULETYPE_USER], true)) {
                $issues[] = $this->invalid_issue('ruletype', (string)$input['ruletype'], [
                    self::RULETYPE_UNIT,
                    self::RULETYPE_USER,
                ]);
            } else {
                $rule['ruletype'] = $ruletype;
            }
        }

        $units = self::units();
        if (array_key_exists('unitid', $input)) {
            $unitid = (int)(taskflow_input_normalizer::to_int($input['unitid']) ?? 0);
            if ($unitid > 0 && !array_key_exists($unitid, $units)) {
                $issues[] = [
                    'code' => self::ISSUE_UNIT_NOT_FOUND,
                    'severity' => 'needs_clarification',
                    'field' => 'unitid',
                    'message' => $this->str('agent_rule_unit_notfound', (object)[
                        'id' => $unitid,
                        'valid' => $this->join(array_keys($units)),
                    ]),
                ];
            } else {
                $rule['unitid'] = $unitid;
            }
        }

        if (array_key_exists('userid', $input)) {
            $userid = (int)(taskflow_input_normalizer::to_int($input['userid']) ?? 0);
            if ($userid > 0 && !$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
                $issues[] = [
                    'code' => taskflow_skill_base::ISSUE_USER_NOT_FOUND,
                    'severity' => 'needs_clarification',
                    'field' => 'userid',
                    'message' => $this->str('agent_user_notfound', $userid),
                ];
            } else {
                $rule['userid'] = $userid;
            }
        }

        if ((string)$rule['ruletype'] === self::RULETYPE_USER) {
            $rule['unitid'] = 0;
            if ((int)$rule['userid'] <= 0) {
                $issues[] = $this->missing_issue('userid');
            }
        } else {
            $rule['userid'] = 0;
            if ((int)$rule['unitid'] <= 0) {
                $issues[] = $this->missing_issue('unitid');
            }
        }
    }

    /**
     * Merge and validate the due-date model.
     *
     * @param array $input
     * @param array $rule
     * @param array $issues
     * @return void
     */
    private function merge_duedate(array $input, array &$rule, array &$issues): void {
        if (array_key_exists('duedatetype', $input)) {
            $type = strtolower(trim((string)$input['duedatetype']));
            if (!in_array($type, [self::DUEDATE_DURATION, self::DUEDATE_FIXEDDATE], true)) {
                $issues[] = $this->invalid_issue('duedatetype', (string)$input['duedatetype'], [
                    self::DUEDATE_DURATION,
                    self::DUEDATE_FIXEDDATE,
                ]);
            } else {
                $rule['duedatetype'] = $type;
            }
        }
        if (array_key_exists('duration', $input)) {
            $seconds = $this->to_seconds($input['duration'], 'duration', $issues);
            if ($seconds !== null) {
                $rule['duration'] = $seconds;
            }
        }
        if (array_key_exists('fixeddate', $input)) {
            $timestamp = $this->to_timestamp($input['fixeddate'], 'fixeddate', $issues);
            if ($timestamp !== null) {
                $rule['fixeddate'] = $timestamp;
            }
        }

        if ((string)$rule['duedatetype'] === self::DUEDATE_DURATION && (int)$rule['duration'] <= 0) {
            $issues[] = $this->missing_issue('duration');
        }
        if ((string)$rule['duedatetype'] === self::DUEDATE_FIXEDDATE && (int)$rule['fixeddate'] <= 0) {
            $issues[] = $this->missing_issue('fixeddate');
        }
    }

    /**
     * Merge and validate the filter list.
     *
     * @param array $input
     * @param array $rule
     * @param array $issues
     * @return void
     */
    private function merge_filters(array $input, array &$rule, array &$issues): void {
        if (!array_key_exists('filters', $input)) {
            return;
        }
        $types = self::filter_type_keys();
        $operators = self::operator_keys();
        $profilefields = array_map('strval', array_keys((array)userprofilefieldfilterform::get_userprofilefields()));
        $userfields = array_map('strval', array_keys((array)userfieldfilterform::get_userfields()));

        $filters = [];
        foreach ((array)$input['filters'] as $index => $raw) {
            $raw = (array)$raw;
            $type = trim((string)($raw['filtertype'] ?? 'user_profile_field'));
            if (!in_array($type, $types, true)) {
                $issues[] = $this->invalid_issue('filters[' . $index . '].filtertype', $type, $types);
                continue;
            }
            $operator = trim((string)($raw['operator'] ?? ''));
            if (!in_array($operator, $operators, true)) {
                $issues[] = $this->invalid_issue('filters[' . $index . '].operator', $operator, $operators);
                continue;
            }
            $field = trim((string)($raw['field'] ?? ''));
            $allowed = $type === 'user_field' ? $userfields : $profilefields;
            if (!in_array($field, $allowed, true)) {
                $issues[] = $this->invalid_issue('filters[' . $index . '].field', $field, $allowed);
                continue;
            }
            $date = 0;
            if (isset($raw['date'])) {
                $date = (int)($this->to_timestamp($raw['date'], 'filters[' . $index . '].date', $issues) ?? 0);
            }
            $filters[] = [
                'filtertype' => $type,
                'field' => $field,
                'operator' => $operator,
                'value' => (string)($raw['value'] ?? ''),
                'date' => $date,
            ];
        }
        $rule['filters'] = $filters;
    }

    /**
     * Merge and validate targets (set, add, remove).
     *
     * @param array $input
     * @param array $rule
     * @param array $issues
     * @return void
     */
    private function merge_targets(array $input, array &$rule, array &$issues): void {
        $targets = (array)$rule['targets'];
        if (array_key_exists('targets', $input)) {
            $targets = $this->validate_targets((array)$input['targets'], 'targets', $issues);
        }
        if (array_key_exists('addtargets', $input)) {
            $targets = array_merge($targets, $this->validate_targets((array)$input['addtargets'], 'addtargets', $issues));
        }
        if (array_key_exists('removetargetids', $input)) {
            $remove = array_map('intval', (array)(taskflow_input_normalizer::to_list($input['removetargetids']) ?? []));
            $targets = array_values(array_filter(
                $targets,
                static fn(array $target): bool => !in_array((int)$target['targetid'], $remove, true)
            ));
        }

        $unique = [];
        foreach ($targets as $target) {
            $unique[$target['targettype'] . ':' . $target['targetid']] = $target;
        }
        $unique = array_values($unique);

        // The target step class form\targets\target::get_target_data() reads 'completebeforenext' from a local copy of the
        // step and therefore hands the flag of the FIRST repeat to every target. The form has the same
        // behaviour, so the flag is effectively rule wide; normalizing it here keeps preview, stored
        // document and post-mutation verification in agreement instead of silently diverging.
        $chainflag = empty($unique) ? 0 : (int)$unique[0]['completebeforenext'];
        foreach ($unique as $index => $target) {
            $unique[$index]['completebeforenext'] = $chainflag;
        }

        $rule['targets'] = $unique;
    }

    /**
     * Validate a list of target rows.
     *
     * @param array $rows
     * @param string $field
     * @param array $issues
     * @return array<int,array<string,mixed>>
     */
    private function validate_targets(array $rows, string $field, array &$issues): array {
        $types = self::target_type_keys();
        $targets = [];
        foreach ($rows as $index => $raw) {
            $raw = (array)$raw;
            $type = trim((string)($raw['targettype'] ?? ''));
            if (!in_array($type, $types, true)) {
                $issues[] = $this->invalid_issue($field . '[' . $index . '].targettype', $type, $types);
                continue;
            }
            $targetid = (int)(taskflow_input_normalizer::to_int($raw['targetid'] ?? null) ?? 0);
            if ($targetid <= 0 || self::target_name($type, $targetid) === '') {
                $issues[] = [
                    'code' => self::ISSUE_TARGET_NOT_FOUND,
                    'severity' => 'needs_clarification',
                    'field' => $field . '[' . $index . '].targetid',
                    'message' => $this->str('agent_rule_target_notfound', (object)['type' => $type, 'id' => $targetid]),
                ];
                continue;
            }
            $targets[] = [
                'targettype' => $type,
                'targetid' => $targetid,
                'completebeforenext' => taskflow_input_normalizer::to_bool($raw['completebeforenext'] ?? false) ? 1 : 0,
            ];
        }
        return $targets;
    }

    /**
     * Merge and validate message template references (set, add, remove).
     *
     * @param array $input
     * @param array $rule
     * @param array $issues
     * @return void
     */
    private function merge_messages(array $input, array &$rule, array &$issues): void {
        global $DB;

        $ids = array_map('intval', (array)$rule['messageids']);
        if (array_key_exists('messageids', $input)) {
            $ids = array_map('intval', (array)(taskflow_input_normalizer::to_list($input['messageids']) ?? []));
        }
        if (array_key_exists('addmessageids', $input)) {
            $added = taskflow_input_normalizer::to_list($input['addmessageids']) ?? [];
            $ids = array_merge($ids, array_map('intval', (array)$added));
        }
        if (array_key_exists('removemessageids', $input)) {
            $remove = array_map('intval', (array)(taskflow_input_normalizer::to_list($input['removemessageids']) ?? []));
            $ids = array_values(array_filter($ids, static fn(int $id): bool => !in_array($id, $remove, true)));
        }

        $ids = array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
        $existing = empty($ids) ? [] : $DB->get_records_list('local_taskflow_messages', 'id', $ids, '', 'id');
        $valid = [];
        foreach ($ids as $id) {
            if (!isset($existing[$id])) {
                $issues[] = [
                    'code' => self::ISSUE_MESSAGE_NOT_FOUND,
                    'severity' => 'needs_clarification',
                    'field' => 'messageids',
                    'message' => $this->str('agent_notfound_message', $id),
                ];
                continue;
            }
            $valid[] = $id;
        }
        $rule['messageids'] = $valid;
    }

    /**
     * Merge and validate the self-service request settings.
     *
     * @param array $input
     * @param array $rule
     * @param array $issues
     * @return void
     */
    private function merge_requests(array $input, array &$rule, array &$issues): void {
        $active = self::request_type_keys();
        $tokens = array_keys(self::receiver_tokens());

        $requests = [];
        foreach ($active as $type) {
            $requests[$type] = (string)($rule['requests'][$type] ?? self::RECEIVER_NOT_ALLOWED);
        }

        foreach ((array)($input['requests'] ?? []) as $type => $token) {
            $type = (string)$type;
            if (!in_array($type, $active, true)) {
                $issues[] = $this->invalid_issue('requests.' . $type, $type, $active);
                continue;
            }
            $token = strtolower(trim((string)$token));
            if (!in_array($token, $tokens, true)) {
                $issues[] = $this->invalid_issue('requests.' . $type, $token, $tokens);
                continue;
            }
            $requests[$type] = $token;
        }
        $rule['requests'] = $requests;
    }

    /**
     * Non-negative number of seconds, or null when the value is not numeric.
     *
     * @param mixed $value
     * @param string $field
     * @param array $issues
     * @return int|null
     */
    private function to_seconds($value, string $field, array &$issues): ?int {
        $seconds = taskflow_input_normalizer::to_int($value);
        if ($seconds === null || $seconds < 0) {
            $issues[] = [
                'code' => self::ISSUE_INVALID_VALUE,
                'severity' => 'needs_clarification',
                'field' => $field,
                'message' => $this->str('agent_rule_invalid_seconds', (object)[
                    'field' => $field,
                    'value' => is_scalar($value) ? (string)$value : gettype($value),
                ]),
            ];
            return null;
        }
        return $seconds;
    }

    /**
     * Unix timestamp from an integer or a date string, or null when unparsable.
     *
     * @param mixed $value
     * @param string $field
     * @param array $issues
     * @return int|null
     */
    private function to_timestamp($value, string $field, array &$issues): ?int {
        if (is_int($value) || (is_string($value) && preg_match('/^\d+$/', trim($value)))) {
            return (int)$value;
        }
        $parsed = is_string($value) ? strtotime(trim($value)) : false;
        if ($parsed === false) {
            $issues[] = [
                'code' => self::ISSUE_INVALID_VALUE,
                'severity' => 'needs_clarification',
                'field' => $field,
                'message' => $this->str('agent_date_invalid', is_scalar($value) ? (string)$value : gettype($value)),
            ];
            return null;
        }
        return (int)$parsed;
    }

    /**
     * Issue for a value the rule form would not accept.
     *
     * @param string $field
     * @param string $value
     * @param array $valid
     * @return array<string,mixed>
     */
    private function invalid_issue(string $field, string $value, array $valid): array {
        return [
            'code' => self::ISSUE_INVALID_VALUE,
            'severity' => 'needs_clarification',
            'field' => $field,
            'message' => $this->str('agent_rule_invalid_value', (object)[
                'field' => $field,
                'value' => $value,
                'valid' => $this->join($valid),
            ]),
        ];
    }

    /**
     * Issue for a missing mandatory value.
     *
     * @param string $field
     * @return array<string,mixed>
     */
    private function missing_issue(string $field): array {
        return [
            'code' => self::ISSUE_MISSING_VALUE,
            'severity' => 'needs_clarification',
            'field' => $field,
            'message' => $this->str('agent_rule_missing_value', $field),
        ];
    }

    /**
     * Comma separated list of allowed values.
     *
     * @param array $values
     * @return string
     */
    private function join(array $values): string {
        return implode(', ', array_map('strval', $values));
    }

    /**
     * Localized string (adapter override aware).
     *
     * @param string $identifier
     * @param mixed $a
     * @return string
     */
    private function str(string $identifier, $a = null): string {
        return taskflow_stringmanager::get_string($identifier, $a, $this->lang === '' ? null : $this->lang);
    }

    /**
     * Class base names of a types directory (the same glob the forms use).
     *
     * @param string $dir
     * @param string $prefix
     * @return string[]
     */
    private static function class_basenames(string $dir, string $prefix): array {
        $names = [];
        foreach (glob(rtrim($dir, '/') . '/*.php') ?: [] as $file) {
            $basename = basename($file, '.php');
            if (class_exists($prefix . $basename)) {
                $names[] = $basename;
            }
        }
        sort($names);
        return $names;
    }
}
