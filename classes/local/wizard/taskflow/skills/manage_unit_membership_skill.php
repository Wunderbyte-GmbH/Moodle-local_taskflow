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

use local_taskflow\local\units\organisational_unit_factory;
use local_taskflow\local\units\organisational_units_factory;
use local_taskflow\local\units\unit_hierarchy;
use local_taskflow\local\wizard\engine\queue_identity_provider_interface;
use local_taskflow\local\wizard\engine\skill_risk_class;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\taskflow_input_normalizer;
use local_taskflow\local\wizard\taskflow\taskflow_result_link_builder;
use local_taskflow\local\wizard\taskflow\taskflow_settings_catalog;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;

/**
 * Mutating skill local_taskflow.manage_unit_membership (implementation plan §2 #32).
 *
 * Adds one user to an organisational unit or removes the user from it. The write goes through
 * the backend object organisational_unit_factory::instance($unitid)->add_member() /
 * ->delete_member(); which backend that is depends on the setting
 * local_taskflow/organisational_unit_option: 'unit' writes a row of
 * local_taskflow_unit_members, 'cohort' calls cohort_add_member() / cohort_remove_member()
 * and therefore emits the core cohort events.
 *
 * The membership itself is verified with is_member() right after the write. Everything the
 * membership causes downstream — the assignments the rules of the unit create or drop — runs
 * asynchronously through the taskflow event pipeline and the cron, so the result reports that
 * part as 'queued' (plan §3.4) and never claims that assignments already exist. The cohort
 * backend only starts that pipeline when the setting local_taskflow/cohortenrollment is on
 * (observer::cohort_member_added()); the unit backend is driven by the adapter import, which
 * is why the preview also warns that a nightly import of an external adapter can revert a
 * manual membership change.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manage_unit_membership_skill extends taskflow_skill_base implements queue_identity_provider_interface {
    /** Skill name. */
    public const TASK_NAME = 'local_taskflow.manage_unit_membership';

    /** Native capability: changing the organisation is an administrative operation. */
    public const CAP_SITECONFIG = 'moodle/site:config';

    /** Action value: add the user to the unit. */
    public const ACTION_ADD = 'add';

    /** Action value: remove the user from the unit. */
    public const ACTION_REMOVE = 'remove';

    /** Backend name: own unit tables. */
    public const BACKEND_UNIT = 'unit';

    /** Backend name: core cohorts. */
    public const BACKEND_COHORT = 'cohort';

    /** Issue code: the unit does not exist. */
    public const ISSUE_UNIT_NOT_FOUND = 'TASKFLOW_UNIT_NOT_FOUND';

    /** Issue code: the action value is missing or unknown. */
    public const ISSUE_ACTION_UNKNOWN = 'TASKFLOW_MEMBERSHIP_ACTION_UNKNOWN';

    /** Issue code: the requested state is already the current state. */
    public const ISSUE_NO_CHANGE = 'TASKFLOW_MEMBERSHIP_UNCHANGED';

    /** Issue code: the backend refused the write. */
    public const ISSUE_WRITE_FAILED = 'TASKFLOW_MEMBERSHIP_WRITE_FAILED';

    /** Issue code: the membership change awaits the user's confirmation. */
    public const ISSUE_CONFIRM = 'TASKFLOW_MEMBERSHIP_CONFIRM_REQUIRED';

    /** Maximum number of rule names named in preview and observation. */
    private const MAX_RULES = 20;

    /**
     * Constructor: mutating, R2, admin capability.
     */
    public function __construct() {
        parent::__construct(false, skill_risk_class::R2, [self::CAP_SITECONFIG]);
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
            'description' => 'Add ONE user to an organisational unit or remove the user from it. The rules '
                . 'attached to the unit create assignments for new members and drop them for leaving members; '
                . 'that part runs asynchronously. Use local_taskflow.list_units to find the unit id.',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'Add user 123 to unit 7',
                'Remove Anna Muster from the unit Administration',
                'Put anna.muster@example.org into organisational unit 3',
            ],
            'properties' => [
                'userid' => [
                    'type' => 'integer',
                    'description' => 'Id of the user. Alternative to userquery.',
                    'required' => false,
                ],
                'userquery' => [
                    'type' => 'string',
                    'description' => 'Name, e-mail or username of the user; must match exactly one person.',
                    'required' => false,
                ],
                'unitid' => [
                    'type' => 'integer',
                    'description' => 'Id of the organisational unit (see local_taskflow.list_units).',
                    'required' => true,
                ],
                'action' => [
                    'type' => 'string',
                    'description' => 'Either "' . self::ACTION_ADD . '" or "' . self::ACTION_REMOVE . '".',
                    'required' => true,
                ],
            ],
            'required' => ['unitid', 'action'],
        ];
    }

    /**
     * Prompt metadata.
     *
     * @return array<string,mixed>
     */
    protected function prompt_meta(): array {
        return [
            'intent' => 'Add one user to an organisational unit or remove the user from it.',
            'input_fields_for_prompt' => ['unitid', 'action', 'userid or userquery'],
            'anchor_fields' => ['userid', 'userquery'],
        ];
    }

    /**
     * Example input for the planner contract.
     *
     * @return array
     */
    public function get_example_input(): array {
        return ['userid' => 123, 'unitid' => 7, 'action' => self::ACTION_ADD];
    }

    /**
     * Queue business identity for deduplication.
     *
     * @param array $input
     * @return array<string,mixed>
     */
    public function build_queue_business_identity(array $input): array {
        return [
            'task_family' => self::TASK_NAME,
            'target' => [
                'unitid' => taskflow_input_normalizer::to_int($input['unitid'] ?? null) ?? 0,
                'userid' => taskflow_input_normalizer::to_int($input['userid'] ?? null) ?? 0,
                'userquery' => trim((string)($input['userquery'] ?? '')),
            ],
            'change' => ['action' => \core_text::strtolower(trim((string)($input['action'] ?? '')))],
        ];
    }

    /**
     * Structural check.
     *
     * @param array $input
     * @return array{valid:bool,errors:string[],ambiguities:string[]}
     */
    public function check_structure(array $input): array {
        $lang = $this->get_output_language($input);
        $errors = [];
        if ((taskflow_input_normalizer::to_int($input['unitid'] ?? null) ?? 0) <= 0) {
            $errors[] = $this->localized_string('agent_manage_unit_membership_unitid_required', null, $lang);
        }
        if ($this->resolve_action($input) === null) {
            $errors[] = $this->localized_string('agent_manage_unit_membership_action_required', null, $lang);
        }
        if (
            (taskflow_input_normalizer::to_int($input['userid'] ?? null) ?? 0) <= 0
            && trim((string)($input['userquery'] ?? '')) === ''
        ) {
            $errors[] = $this->localized_string('agent_manage_unit_membership_user_required', null, $lang);
        }
        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => []];
    }

    /**
     * Preflight: resolve user and unit, reject a no-op, then confirm.
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
                    'code' => $this->resolve_action($input) === null ? self::ISSUE_ACTION_UNKNOWN : 'VALIDATION_ERROR',
                    'severity' => 'needs_clarification',
                    'message' => (string)$error,
                ];
            }
            return $this->invalid($issues);
        }

        $action = (string)$this->resolve_action($input);
        $unitid = (int)taskflow_input_normalizer::to_int($input['unitid']);
        $units = $this->all_units();
        if (!array_key_exists($unitid, $units)) {
            return $this->invalid([
                $this->not_found_issue(
                    self::ISSUE_UNIT_NOT_FOUND,
                    $this->localized_string('agent_unit_notfound', (string)$unitid, $lang),
                    ['field' => 'unitid']
                ),
            ]);
        }

        $targetuserid = $this->resolve_userid($input, 0);
        if ($targetuserid <= 0) {
            $query = trim((string)($input['userquery'] ?? ''));
            $candidates = $query === '' ? [] : $this->search_user_candidates($query, 5);
            return $this->invalid([[
                'code' => count($candidates) > 1 ? self::ISSUE_USER_AMBIGUOUS : self::ISSUE_USER_NOT_FOUND,
                'severity' => 'needs_clarification',
                'field' => 'userquery',
                'message' => $this->localized_string(
                    count($candidates) > 1 ? 'agent_user_ambiguous' : 'agent_user_notfound',
                    $query,
                    $lang
                ),
            ]]);
        }

        $ismember = $this->is_member($unitid, $targetuserid);
        if (($action === self::ACTION_ADD) === $ismember) {
            return $this->invalid([[
                'code' => self::ISSUE_NO_CHANGE,
                'severity' => 'needs_clarification',
                'field' => 'action',
                'message' => $this->localized_string(
                    $ismember
                        ? 'agent_manage_unit_membership_already_member'
                        : 'agent_manage_unit_membership_not_member',
                    (object)[
                        'fullname' => $this->fullname_of($targetuserid),
                        'unit' => (string)$units[$unitid],
                        'unitid' => $unitid,
                    ],
                    $lang
                ),
            ]]);
        }

        $prepared = $input;
        $prepared['unitid'] = $unitid;
        $prepared['userid'] = $targetuserid;
        $prepared['action'] = $action;
        unset($prepared['userquery']);

        return $this->confirmable($prepared, [[
            'code' => self::ISSUE_CONFIRM,
            'severity' => 'needs_confirmation',
            'user_question' => $this->localized_string('agent_manage_unit_membership_confirm_' . $action, (object)[
                'fullname' => $this->fullname_of($targetuserid),
                'unit' => (string)$units[$unitid],
                'unitid' => $unitid,
            ], $lang),
        ]]);
    }

    /**
     * Tier-3 confirmation preview: person, unit with its path, action, affected rules, warning.
     *
     * @param array $input Prepared input.
     * @return array{title:string,summary:string,rows:array}|null
     */
    public function describe_proposed_action(array $input): ?array {
        $lang = $this->get_output_language($input);
        $action = $this->resolve_action($input);
        $unitid = taskflow_input_normalizer::to_int($input['unitid'] ?? null) ?? 0;
        $targetuserid = taskflow_input_normalizer::to_int($input['userid'] ?? null) ?? 0;
        $units = $this->all_units();
        if ($action === null || $unitid <= 0 || $targetuserid <= 0 || !array_key_exists($unitid, $units)) {
            return null;
        }

        $rules = $this->rules_of_unit($unitid);
        $rows = [
            [
                'label' => $this->localized_string('fullname', null, $lang),
                'value' => $this->fullname_of($targetuserid) . ' (' . $targetuserid . ')',
            ],
            [
                'label' => $this->localized_string('unit', null, $lang),
                'value' => (string)$units[$unitid] . ' (#' . $unitid . ') · '
                    . $this->unit_path_text($unitid, $units),
            ],
            [
                'label' => $this->localized_string('agent_manage_unit_membership_row_action', null, $lang),
                'value' => $this->action_label($action, $lang),
            ],
            [
                'label' => $this->localized_string('agent_manage_unit_membership_row_rules', null, $lang),
                'value' => empty($rules)
                    ? $this->localized_string('agent_preview_none', null, $lang)
                    : implode(', ', array_column($rules, 'name')),
            ],
            [
                'label' => $this->localized_string('agent_manage_unit_membership_row_effect', null, $lang),
                'value' => $this->effect_text($action, $unitid, $targetuserid, $rules, $lang),
            ],
            [
                'label' => $this->localized_string('agent_preview_warning', null, $lang),
                'value' => $this->import_warning($lang),
            ],
        ];

        return [
            'title' => $this->localized_string('agent_manage_unit_membership_title_' . $action, (object)[
                'fullname' => $this->fullname_of($targetuserid),
                'unit' => (string)$units[$unitid],
            ], $lang),
            'summary' => $this->localized_string('agent_manage_unit_membership_summary_' . $action, (object)[
                'fullname' => $this->fullname_of($targetuserid),
                'unit' => (string)$units[$unitid],
                'rules' => count($rules),
            ], $lang),
            'rows' => $rows,
        ];
    }

    /**
     * Execute: write through the backend object, verify the membership, report the async part.
     *
     * @param array $input Prepared input.
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        $lang = $this->get_output_language($input);
        $debug = $this->build_task_debug_message(self::TASK_NAME, $input);
        $links = $this->links(taskflow_result_link_builder::dashboard_url(), ['units_and_users', 'adapters']);

        $action = $this->resolve_action($input);
        if ($action === null) {
            return $this->error_result(
                self::ISSUE_ACTION_UNKNOWN,
                $this->localized_string('agent_manage_unit_membership_action_required', null, $lang),
                ['links' => $links, 'debugmessage' => $debug]
            );
        }
        $unitid = taskflow_input_normalizer::to_int($input['unitid'] ?? null) ?? 0;
        $units = $this->all_units();
        if ($unitid <= 0 || !array_key_exists($unitid, $units)) {
            return $this->error_result(
                self::ISSUE_UNIT_NOT_FOUND,
                $this->localized_string('agent_unit_notfound', (string)$unitid, $lang),
                ['links' => $links, 'debugmessage' => $debug]
            );
        }
        $targetuserid = $this->resolve_userid($input, 0);
        if ($targetuserid <= 0) {
            return $this->error_result(
                self::ISSUE_USER_NOT_FOUND,
                $this->localized_string('agent_user_notfound', trim((string)($input['userquery'] ?? '')), $lang),
                ['links' => $links, 'debugmessage' => $debug]
            );
        }

        $unit = $this->unit_instance($unitid);
        if ($unit === null) {
            return $this->error_result(
                self::ISSUE_UNIT_NOT_FOUND,
                $this->localized_string('agent_unit_notfound', (string)$unitid, $lang),
                ['links' => $links, 'debugmessage' => $debug]
            );
        }

        $rules = $this->rules_of_unit($unitid);
        $assignmentsbefore = $this->assignment_count($targetuserid, $rules);
        try {
            if ($action === self::ACTION_ADD) {
                $unit->add_member($targetuserid);
            } else {
                $unit->delete_member($targetuserid);
            }
        } catch (\Throwable $e) {
            return $this->error_result(
                self::ISSUE_WRITE_FAILED,
                $this->localized_string('agent_manage_unit_membership_failed', $e->getMessage(), $lang),
                ['links' => $links, 'resultid' => $unitid, 'debugmessage' => $debug]
            );
        }

        $expectedmember = $action === self::ACTION_ADD;
        $fullname = $this->fullname_of($targetuserid);
        $unitname = (string)$units[$unitid];
        $usermessage = $this->localized_string('agent_manage_unit_membership_result_' . $action, (object)[
            'fullname' => $fullname,
            'unit' => $unitname,
            'unitid' => $unitid,
        ], $lang);
        $pending = $this->effect_text($action, $unitid, $targetuserid, $rules, $lang);

        $observation = [
            $usermessage,
            'userid=' . $targetuserid . ', unitid=' . $unitid . ', action=' . $action
                . ', backend=' . $this->backend(),
            'rules_on_unit=' . count($rules) . (empty($rules) ? '' : ' (' . implode(', ', array_column($rules, 'name')) . ')'),
            'assignments_for_these_rules_before=' . $assignmentsbefore
                . ', after=' . $this->assignment_count($targetuserid, $rules),
            'pipeline=' . $pending,
            'adapter=' . taskflow_settings_catalog::active_adapter(),
        ];

        $result = $this->verified_result(
            ['ismember' => $expectedmember ? 1 : 0],
            fn(): array => ['ismember' => $this->is_member($unitid, $targetuserid) ? 1 : 0],
            [
                'detail' => $usermessage,
                'usermessage' => $usermessage,
                'observation_full' => implode("\n", $observation),
                'resultid' => $unitid,
                'unitid' => $unitid,
                'unitname' => $unitname,
                'userid' => $targetuserid,
                'fullname' => $fullname,
                'action' => $action,
                'backend' => $this->backend(),
                'ismember' => $this->is_member($unitid, $targetuserid),
                'rules' => $rules,
                'assignments_before' => $assignmentsbefore,
                'assignments_after' => $this->assignment_count($targetuserid, $rules),
                'pending' => $pending,
                'warning' => $this->import_warning($lang),
                'links' => $links,
                'outputlang' => $lang,
                'debugmessage' => $debug,
                'preview' => [
                    'type' => taskflow_preview_renderer_factory::TYPE_UNITS_TREE,
                    'data' => [
                        'units' => [$this->unit_row($unitid, $units, count($rules))],
                        'backend' => $this->backend(),
                        'total' => 1,
                        'parentid' => 0,
                        'query' => '',
                    ],
                    'payload' => ['unitids' => [$unitid], 'userids' => [$targetuserid]],
                ],
            ],
            $lang
        );

        // The membership itself is verified; the assignments the rules create or drop are
        // produced asynchronously, so the result must not claim more than 'queued' (plan §3.4).
        if ($result['status'] === self::STATUS_EXECUTED && !empty($rules)) {
            $result['status'] = self::STATUS_QUEUED;
            $result['pending_tasks'] = [$pending];
            $result['detail'] = $usermessage . ' ' . $pending;
            $result['usermessage'] = $usermessage . ' ' . $pending;
        }

        return $result;
    }

    /**
     * Canonical action value of the input, or null when it names none.
     *
     * @param array $input
     * @return string|null
     */
    private function resolve_action(array $input): ?string {
        $value = \core_text::strtolower(trim((string)($input['action'] ?? '')));
        return in_array($value, [self::ACTION_ADD, self::ACTION_REMOVE], true) ? $value : null;
    }

    /**
     * Localized label of an action value.
     *
     * @param string $action
     * @param string $lang
     * @return string
     */
    private function action_label(string $action, string $lang): string {
        return $this->localized_string('agent_manage_unit_membership_action_' . $action, null, $lang);
    }

    /**
     * Active unit backend from the plugin setting.
     *
     * @return string unit|cohort
     */
    private function backend(): string {
        $option = \core_text::strtolower(trim((string)get_config('local_taskflow', 'organisational_unit_option')));
        return $option === self::BACKEND_COHORT ? self::BACKEND_COHORT : self::BACKEND_UNIT;
    }

    /**
     * All units of the active backend: id => display name.
     *
     * @return array<int,string>
     */
    private function all_units(): array {
        try {
            $units = (array)organisational_units_factory::instance()->get_units();
        } catch (\Throwable $e) {
            return [];
        }
        $normalized = [];
        foreach ($units as $unitid => $name) {
            $normalized[(int)$unitid] = (string)$name;
        }
        return $normalized;
    }

    /**
     * Backend object of one unit, or null when it cannot be instantiated.
     *
     * @param int $unitid
     * @return object|null
     */
    private function unit_instance(int $unitid) {
        try {
            $unit = organisational_unit_factory::instance($unitid);
        } catch (\Throwable $e) {
            return null;
        }
        return is_object($unit) ? $unit : null;
    }

    /**
     * Whether the user currently is a member of the unit (backend answer).
     *
     * @param int $unitid
     * @param int $targetuserid
     * @return bool
     */
    private function is_member(int $unitid, int $targetuserid): bool {
        $unit = $this->unit_instance($unitid);
        if ($unit === null || !method_exists($unit, 'is_member')) {
            return false;
        }
        try {
            return (bool)$unit->is_member($targetuserid);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Hierarchy entries (depth and path) by unit id.
     *
     * @return array<int,array{depth:int,pathtoou:string}>
     */
    private function hierarchy(): array {
        try {
            $hierarchy = (array)(new unit_hierarchy())->get();
        } catch (\Throwable $e) {
            return [];
        }
        $normalized = [];
        foreach ($hierarchy as $unitid => $entry) {
            $entry = (array)$entry;
            $normalized[(int)$unitid] = [
                'depth' => (int)($entry['depth'] ?? 1),
                'pathtoou' => (string)($entry['pathtoou'] ?? (string)$unitid),
            ];
        }
        return $normalized;
    }

    /**
     * Unit row for the units-tree preview.
     *
     * @param int $unitid
     * @param array<int,string> $units
     * @param int $rulescount
     * @return array<string,mixed>
     */
    private function unit_row(int $unitid, array $units, int $rulescount): array {
        $entry = $this->hierarchy()[$unitid] ?? [];
        $unit = $this->unit_instance($unitid);
        $members = 0;
        if ($unit !== null && method_exists($unit, 'count_members')) {
            try {
                $members = (int)$unit->count_members();
            } catch (\Throwable $e) {
                $members = 0;
            }
        }
        return [
            'id' => $unitid,
            'name' => (string)($units[$unitid] ?? ''),
            'parentid' => 0,
            'depth' => (int)($entry['depth'] ?? 1),
            'path' => (string)($entry['pathtoou'] ?? (string)$unitid),
            'members' => $members,
            'rules_count' => $rulescount,
        ];
    }

    /**
     * Readable path of a unit ('root › ... › unit'), falling back to the raw id path.
     *
     * @param int $unitid
     * @param array<int,string> $units
     * @return string
     */
    private function unit_path_text(int $unitid, array $units): string {
        $path = (string)($this->hierarchy()[$unitid]['pathtoou'] ?? '');
        $segments = array_values(array_map('intval', array_filter(explode('/', $path), 'strlen')));
        if (empty($segments)) {
            return (string)($units[$unitid] ?? '');
        }
        $names = [];
        foreach ($segments as $segment) {
            $names[] = (string)($units[$segment] ?? ('#' . $segment));
        }
        return implode(' › ', $names);
    }

    /**
     * Rules attached to one unit.
     *
     * @param int $unitid
     * @return array<int,array{id:int,name:string,isactive:int}>
     */
    private function rules_of_unit(int $unitid): array {
        global $DB;

        if ($unitid <= 0) {
            return [];
        }
        $records = $DB->get_records(
            'local_taskflow_rules',
            ['unitid' => $unitid],
            'id ASC',
            'id, rulename, isactive',
            0,
            self::MAX_RULES
        );
        $rules = [];
        foreach ($records as $record) {
            $rules[] = [
                'id' => (int)$record->id,
                'name' => (string)$record->rulename,
                'isactive' => (int)$record->isactive,
            ];
        }
        return $rules;
    }

    /**
     * Number of assignments the user currently has for the given rules.
     *
     * @param int $targetuserid
     * @param array<int,array{id:int,name:string,isactive:int}> $rules
     * @return int
     */
    private function assignment_count(int $targetuserid, array $rules): int {
        global $DB;

        $ruleids = array_column($rules, 'id');
        if ($targetuserid <= 0 || empty($ruleids)) {
            return 0;
        }
        [$insql, $params] = $DB->get_in_or_equal($ruleids, SQL_PARAMS_NAMED, 'rid');
        $params['userid'] = $targetuserid;
        return (int)$DB->count_records_select(
            'local_taskflow_assignment',
            "userid = :userid AND ruleid {$insql}",
            $params
        );
    }

    /**
     * What the membership change causes and when it becomes visible.
     *
     * @param string $action
     * @param int $unitid
     * @param int $targetuserid
     * @param array<int,array{id:int,name:string,isactive:int}> $rules
     * @param string $lang
     * @return string
     */
    private function effect_text(string $action, int $unitid, int $targetuserid, array $rules, string $lang): string {
        if (empty($rules)) {
            return $this->localized_string('agent_manage_unit_membership_effect_norules', null, $lang);
        }
        $key = $action === self::ACTION_ADD
            ? 'agent_manage_unit_membership_effect_add'
            : 'agent_manage_unit_membership_effect_remove';
        $effect = $this->localized_string($key, (object)[
            'rules' => count($rules),
            'existing' => $this->assignment_count($targetuserid, $rules),
        ], $lang);

        if ($this->backend() === self::BACKEND_COHORT && empty(get_config('local_taskflow', 'cohortenrollment'))) {
            // The observer cohort_member_added() only starts the pipeline when this setting is on.
            $effect .= ' ' . $this->localized_string('agent_manage_unit_membership_effect_nopipeline', null, $lang);
        }
        return $effect;
    }

    /**
     * Warning that an external adapter import can revert a manual membership change.
     *
     * @param string $lang
     * @return string
     */
    private function import_warning(string $lang): string {
        return $this->localized_string(
            'agent_manage_unit_membership_warning_import',
            taskflow_settings_catalog::active_adapter(),
            $lang
        );
    }

    /**
     * Full name of a user id ('' when unknown).
     *
     * @param int $userid
     * @return string
     */
    private function fullname_of(int $userid): string {
        if ($userid <= 0) {
            return '';
        }
        $user = \core_user::get_user($userid, '*', IGNORE_MISSING);
        return $user ? fullname($user) : '';
    }
}
