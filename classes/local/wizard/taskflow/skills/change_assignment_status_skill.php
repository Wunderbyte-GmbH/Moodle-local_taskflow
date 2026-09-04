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

use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\assignments\assignment;
use local_taskflow\local\assignments\assignment_manual_update_service;
use local_taskflow\local\assignments\status\assignment_status;
use local_taskflow\local\history\history;
use local_taskflow\local\wizard\engine\queue_identity_provider_interface;
use local_taskflow\local\wizard\engine\skill_risk_class;
use local_taskflow\local\wizard\taskflow\taskflow_input_normalizer;
use local_taskflow\local\wizard\taskflow\taskflow_result_link_builder;
use local_taskflow\local\wizard\taskflow\taskflow_settings_catalog;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;

/**
 * Mutating skill local_taskflow.change_assignment_status (implementation plan §2 #22).
 *
 * Sets the status of one assignment through assignment_manual_update_service::apply(), which
 * writes the history entry (manual change) and persists the record; the skill itself contains
 * no business logic. The target status is resolved against assignment_status_facade::
 * get_userchoices() minus the statuses the active adapter excludes, so an adapter that forbids
 * a status can never be bypassed. After the write the assignment is re-read (singleton
 * destroyed first) and the stored status compared with the requested one: only a verified
 * change yields status 'executed' (plan §3.4).
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class change_assignment_status_skill extends taskflow_skill_base implements queue_identity_provider_interface {
    /** Skill name. */
    public const TASK_NAME = 'local_taskflow.change_assignment_status';

    /** Issue code: the requested status is not a valid user choice. */
    public const ISSUE_STATUS_UNKNOWN = 'TASKFLOW_STATUS_UNKNOWN';

    /** Issue code: the requested status is excluded by the active adapter. */
    public const ISSUE_STATUS_EXCLUDED = 'TASKFLOW_STATUS_EXCLUDED';

    /** Issue code: the change reason is missing or unknown. */
    public const ISSUE_REASON_UNKNOWN = 'TASKFLOW_CHANGEREASON_UNKNOWN';

    /** Issue code: the status change awaits the user's confirmation. */
    public const ISSUE_CONFIRM_REQUIRED = 'TASKFLOW_STATUS_CHANGE_CONFIRM_REQUIRED';

    /** Number of history entries carried in the result preview. */
    private const PREVIEW_HISTORY_LIMIT = 5;

    /**
     * Canonical change reason names exposed to the model => assignment_status constant.
     *
     * @var array<string,int>
     */
    private const CHANGE_REASONS = [
        'sickness' => assignment_status::CHANGEREASON_SICKNESS,
        'holidays' => assignment_status::CHANGEREASON_HOLIDAYS,
        'other' => assignment_status::CHANGEREASON_OTHER,
    ];

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(false, skill_risk_class::R1);
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
            'description' => 'Change the status of one taskflow assignment (for example to paused or completed) '
                . 'with a change reason and an optional comment. Writes a history entry of type manual change.',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'Set assignment 4711 to paused because of sickness',
                'Mark assignment 4711 as completed, reason other, comment "evidence accepted"',
            ],
            'properties' => [
                'assignmentid' => [
                    'type' => 'integer',
                    'description' => 'Id of the assignment (find it with local_taskflow.search_assignments).',
                    'required' => true,
                ],
                'status' => [
                    'type' => 'string',
                    'description' => 'Target status: either the numeric status id or the status name. '
                        . 'Only statuses offered by the site are accepted; '
                        . 'local_taskflow.list_rule_properties lists them.',
                    'required' => true,
                ],
                'reason' => [
                    'type' => 'string',
                    'description' => 'Reason of the change: one of ' . implode(', ', array_keys(self::CHANGE_REASONS))
                        . ' (or the numeric reason id).',
                    'required' => true,
                ],
                'comment' => [
                    'type' => 'string',
                    'description' => 'Free text stored with the history entry.',
                    'required' => false,
                ],
                'keepchanges' => [
                    'type' => 'boolean',
                    'description' => 'Protect the manual change against the next data import.',
                    'required' => false,
                ],
            ],
            'required' => ['assignmentid', 'status', 'reason'],
        ];
    }

    /**
     * Prompt metadata.
     *
     * @return array<string,mixed>
     */
    protected function prompt_meta(): array {
        return [
            'intent' => 'Change the status of one identified taskflow assignment.',
            'input_fields_for_prompt' => ['assignmentid', 'status', 'reason'],
            'anchor_fields' => ['assignmentid'],
        ];
    }

    /**
     * Example input for the planner contract.
     *
     * @return array
     */
    public function get_example_input(): array {
        return ['assignmentid' => 4711, 'status' => 'paused', 'reason' => 'sickness', 'comment' => 'Sick leave'];
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
                'assignmentid' => taskflow_input_normalizer::to_int($input['assignmentid'] ?? null) ?? 0,
            ],
            'change' => [
                'status' => trim((string)($input['status'] ?? '')),
                'reason' => trim((string)($input['reason'] ?? '')),
            ],
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
        if ((taskflow_input_normalizer::to_int($input['assignmentid'] ?? null) ?? 0) <= 0) {
            $errors[] = $this->localized_string('agent_assignmentid_required', null, $lang);
        }
        if (trim((string)($input['status'] ?? '')) === '') {
            $errors[] = $this->localized_string('agent_change_status_required', $this->status_choice_list($lang), $lang);
        }
        if (trim((string)($input['reason'] ?? '')) === '') {
            $errors[] = $this->localized_string('agent_change_status_reason_required', $this->reason_list(), $lang);
        }
        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => []];
    }

    /**
     * Preflight: scope, status and reason resolution, then confirmation.
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
                    'message' => (string)$error,
                ];
            }
            return $this->invalid($issues);
        }

        $assignmentid = (int)taskflow_input_normalizer::to_int($input['assignmentid']);
        $assignment = $this->resolve_assignment(['assignmentid' => $assignmentid]);
        if ($assignment === null) {
            return $this->invalid([
                $this->not_found_issue(
                    self::ISSUE_ASSIGNMENT_NOT_FOUND,
                    $this->localized_string('agent_notfound_assignment', $assignmentid, $lang),
                    ['field' => 'assignmentid']
                ),
            ]);
        }
        if (!$this->permissions()->can_edit_assignment($assignmentid, $userid)) {
            return $this->invalid([$this->scope_denied_issue($lang, ['field' => 'assignmentid'])]);
        }

        $statusid = $this->resolve_status((string)$input['status']);
        if ($statusid === null) {
            return $this->invalid([[
                'code' => self::ISSUE_STATUS_UNKNOWN,
                'severity' => 'needs_clarification',
                'field' => 'status',
                'message' => $this->localized_string('agent_status_unknown', (object)[
                    'value' => trim((string)$input['status']),
                    'known' => $this->status_choice_list($lang),
                ], $lang),
            ]]);
        }
        if (assignment_status_facade::check_excluded((string)$statusid)) {
            return $this->invalid([[
                'code' => self::ISSUE_STATUS_EXCLUDED,
                'severity' => 'needs_clarification',
                'field' => 'status',
                'message' => $this->localized_string('agent_change_status_excluded', (object)[
                    'adapter' => taskflow_settings_catalog::active_adapter(),
                    'status' => $this->status_label($statusid, $lang),
                    'known' => $this->status_choice_list($lang),
                ], $lang),
            ]]);
        }

        $reasonid = $this->resolve_reason((string)$input['reason']);
        if ($reasonid === null) {
            return $this->invalid([[
                'code' => self::ISSUE_REASON_UNKNOWN,
                'severity' => 'needs_clarification',
                'field' => 'reason',
                'message' => $this->localized_string('agent_change_status_reason_unknown', (object)[
                    'value' => trim((string)$input['reason']),
                    'known' => $this->reason_list(),
                ], $lang),
            ]]);
        }

        $prepared = $input;
        $prepared['assignmentid'] = $assignmentid;
        $prepared['status'] = $statusid;
        $prepared['reason'] = $reasonid;
        $prepared['comment'] = trim((string)($input['comment'] ?? ''));
        $keepchanges = taskflow_input_normalizer::to_bool($input['keepchanges'] ?? null);
        if ($keepchanges !== null) {
            $prepared['keepchanges'] = $keepchanges;
        } else {
            unset($prepared['keepchanges']);
        }

        return $this->confirmable($prepared, [[
            'code' => self::ISSUE_CONFIRM_REQUIRED,
            'severity' => 'needs_confirmation',
            'user_question' => $this->localized_string('agent_change_status_confirm', (object)[
                'id' => $assignmentid,
                'fullname' => (string)$assignment->fullname,
                'from' => $this->status_label((int)$assignment->status, $lang),
                'to' => $this->status_label($statusid, $lang),
            ], $lang),
        ]]);
    }

    /**
     * Tier-3 confirmation preview: verb + object, effect in one sentence, meaningful rows only.
     *
     * @param array $input Prepared input.
     * @return array{title:string,summary:string,rows:array}|null
     */
    public function describe_proposed_action(array $input): ?array {
        $lang = $this->get_output_language($input);
        $assignmentid = taskflow_input_normalizer::to_int($input['assignmentid'] ?? null) ?? 0;
        $assignment = $this->resolve_assignment(['assignmentid' => $assignmentid]);
        if ($assignment === null) {
            return null;
        }
        $statusid = $this->resolve_status((string)($input['status'] ?? ''));
        if ($statusid === null) {
            return null;
        }
        $from = $this->status_label((int)$assignment->status, $lang);
        $to = $this->status_label($statusid, $lang);

        $rows = [
            [
                'label' => $this->localized_string('fullname', null, $lang),
                'value' => (string)$assignment->fullname,
            ],
            [
                'label' => $this->localized_string('rule', null, $lang),
                'value' => (string)$assignment->name,
            ],
            [
                'label' => $this->localized_string('status', null, $lang),
                'value' => $from . ' → ' . $to,
            ],
        ];
        $reasonid = $this->resolve_reason((string)($input['reason'] ?? ''));
        if ($reasonid !== null) {
            $reasons = assignment_status::get_all_changereasons();
            $rows[] = [
                'label' => $this->localized_string('changereason', null, $lang),
                'value' => (string)($reasons[$reasonid] ?? $reasonid),
            ];
        }
        $comment = trim((string)($input['comment'] ?? ''));
        if ($comment !== '') {
            $rows[] = ['label' => $this->localized_string('comment', null, $lang), 'value' => $comment];
        }
        if (taskflow_input_normalizer::to_bool($input['keepchanges'] ?? null)) {
            $rows[] = [
                'label' => $this->localized_string('keepchangesonimport', null, $lang),
                'value' => get_string('yes'),
            ];
        }
        $adapter = taskflow_settings_catalog::active_adapter();
        if ($adapter !== 'standard') {
            $rows[] = [
                'label' => $this->localized_string('agent_change_status_row_warning', null, $lang),
                'value' => $this->localized_string('agent_change_status_warning_adapter', $adapter, $lang),
            ];
        }

        return [
            'title' => $this->localized_string('agent_change_status_title', (object)[
                'id' => (int)$assignment->id,
                'fullname' => (string)$assignment->fullname,
            ], $lang),
            'summary' => $this->localized_string('agent_change_status_summary', (object)[
                'from' => $from,
                'to' => $to,
            ], $lang),
            'rows' => $rows,
        ];
    }

    /**
     * Execute: apply the status change through the service and verify the stored record.
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

        $assignment = $this->resolve_assignment(['assignmentid' => $assignmentid]);
        if ($assignment === null) {
            return $this->error_result(
                self::ISSUE_ASSIGNMENT_NOT_FOUND,
                $this->localized_string('agent_notfound_assignment', $assignmentid, $lang),
                ['debugmessage' => $debug]
            );
        }
        if (!$this->permissions()->can_edit_assignment($assignmentid, $userid)) {
            return $this->error_result(
                self::ISSUE_SCOPE_DENIED,
                $this->localized_string('agent_scope_denied', null, $lang),
                ['debugmessage' => $debug]
            );
        }
        $statusid = $this->resolve_status((string)($input['status'] ?? ''));
        if ($statusid === null || assignment_status_facade::check_excluded((string)$statusid)) {
            return $this->error_result(
                $statusid === null ? self::ISSUE_STATUS_UNKNOWN : self::ISSUE_STATUS_EXCLUDED,
                $this->localized_string('agent_status_unknown', (object)[
                    'value' => trim((string)($input['status'] ?? '')),
                    'known' => $this->status_choice_list($lang),
                ], $lang),
                ['debugmessage' => $debug]
            );
        }
        $reasonid = $this->resolve_reason((string)($input['reason'] ?? ''));
        if ($reasonid === null) {
            return $this->error_result(
                self::ISSUE_REASON_UNKNOWN,
                $this->localized_string('agent_change_status_reason_unknown', (object)[
                    'value' => trim((string)($input['reason'] ?? '')),
                    'known' => $this->reason_list(),
                ], $lang),
                ['debugmessage' => $debug]
            );
        }

        $oldstatus = (int)$assignment->status;
        $oldlabel = $this->status_label($oldstatus, $lang);
        $newlabel = $this->status_label($statusid, $lang);
        $comment = trim((string)($input['comment'] ?? ''));
        $changes = [
            'status' => $statusid,
            'respectexcluded' => true,
            'change_reason' => $reasonid,
            'userid' => (int)$assignment->userid,
        ];
        $keepchanges = taskflow_input_normalizer::to_bool($input['keepchanges'] ?? null);
        if ($keepchanges !== null) {
            $changes['keepchanges'] = $keepchanges;
        }

        try {
            (new assignment_manual_update_service())->apply($assignmentid, $changes, $userid, $comment);
        } catch (\Throwable $e) {
            return $this->error_result(
                self::ISSUE_VERIFICATION_FAILED,
                $e->getMessage(),
                ['debugmessage' => $debug]
            );
        }

        $expected = ['status' => $statusid];
        if ($keepchanges !== null) {
            $expected['keepchanges'] = (int)$keepchanges;
        }
        $change = $this->localized_string('agent_change_status_change', (object)[
            'from' => $oldlabel,
            'to' => $newlabel,
        ], $lang);
        $links = $this->links(
            taskflow_result_link_builder::assignment_url($assignmentid),
            ['assignments_edit', 'assignments_status_lifecycle', 'assignments_history'],
            ['edit' => taskflow_result_link_builder::edit_assignment_url($assignmentid)]
        );
        $usermessage = $this->localized_string('agent_change_status_result', (object)[
            'id' => $assignmentid,
            'fullname' => (string)$assignment->fullname,
            'from' => $oldlabel,
            'to' => $newlabel,
        ], $lang);

        $fresh = $this->fresh_assignment($assignmentid);
        $historyid = $this->latest_manual_history_id($assignmentid);
        $details = $this->assignment_details($assignmentid, $contextid, $userid, $lang, $change);

        $observation = [
            $usermessage,
            'assignmentid=' . $assignmentid . ', status_before=' . $oldstatus . ', status_after='
                . (int)($fresh['status'] ?? 0) . ', change_reason=' . $reasonid
                . ', keepchanges=' . (int)($fresh['keepchanges'] ?? 0),
            'history_entry_id=' . $historyid . ' (' . history::TYPE_MANUAL_CHANGE . ')',
            'adapter=' . taskflow_settings_catalog::active_adapter(),
        ];
        if ($comment !== '') {
            $observation[] = 'comment=' . $comment;
        }

        return $this->verified_result(
            $expected,
            fn(): array => $fresh,
            [
                'detail' => $usermessage,
                'usermessage' => $usermessage,
                'observation_full' => implode("\n", $observation),
                'resultid' => $assignmentid,
                'assignment' => $details['assignment'] ?? [],
                'status_before' => $oldstatus,
                'status_after' => (int)($fresh['status'] ?? 0),
                'change' => $change,
                'history_entry_id' => $historyid,
                'links' => $links,
                'debugmessage' => $debug,
                'preview' => $details['preview'],
            ],
            $lang
        );
    }

    /**
     * Fresh read of the assignment record after invalidating the singleton (plan §3.4).
     *
     * @param int $assignmentid
     * @return array<string,mixed>
     */
    private function fresh_assignment(int $assignmentid): array {
        assignment::destroy_instance($assignmentid);
        $instance = assignment::get_instance($assignmentid);
        if (empty($instance->id)) {
            return [];
        }
        return (array)$instance->return_class_data();
    }

    /**
     * Id of the newest manual-change history entry of the assignment (0 when none).
     *
     * @param int $assignmentid
     * @return int
     */
    private function latest_manual_history_id(int $assignmentid): int {
        global $DB;

        $records = $DB->get_records(
            'local_taskflow_history',
            ['assignmentid' => $assignmentid, 'type' => history::TYPE_MANUAL_CHANGE],
            'id DESC',
            'id',
            0,
            1
        );
        $record = reset($records);
        return $record ? (int)$record->id : 0;
    }

    /**
     * Assignment payload and preview block, reused from the read-only details skill.
     *
     * @param int $assignmentid
     * @param int $contextid
     * @param int $userid
     * @param string $lang
     * @param string $change Change line shown in the preview card.
     * @return array{assignment:array,preview:array|null}
     */
    private function assignment_details(
        int $assignmentid,
        int $contextid,
        int $userid,
        string $lang,
        string $change
    ): array {
        try {
            $details = (new get_assignment_details_skill())->execute([
                'assignmentid' => $assignmentid,
                'historylimit' => self::PREVIEW_HISTORY_LIMIT,
                'outputlang' => $lang,
            ], $contextid, $userid);
        } catch (\Throwable $e) {
            return ['assignment' => [], 'preview' => null];
        }
        $preview = is_array($details['preview'] ?? null) ? (array)$details['preview'] : null;
        if ($preview !== null && $change !== '') {
            $preview['data']['assignment']['change'] = $change;
        }
        return [
            'assignment' => is_array($details['assignment'] ?? null) ? (array)$details['assignment'] : [],
            'preview' => $preview,
        ];
    }

    /**
     * Resolve a status id or status name against the site's user choices.
     *
     * @param string $value
     * @return int|null Null when the value names no selectable status.
     */
    private function resolve_status(string $value): ?int {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $choices = assignment_status_facade::get_userchoices();
        if (preg_match('/^-?\d+$/', $value)) {
            $id = (int)$value;
            return array_key_exists($id, $choices) ? $id : null;
        }
        $needle = \core_text::strtolower($value);
        $labels = assignment_status_facade::get_all_labels();
        foreach ($choices as $id => $name) {
            if (\core_text::strtolower((string)$name) === $needle) {
                return (int)$id;
            }
            $label = (string)($labels[$id] ?? '');
            if ($label !== '' && \core_text::strtolower($label) === $needle) {
                return (int)$id;
            }
        }
        return null;
    }

    /**
     * Resolve a change reason id or canonical reason name.
     *
     * @param string $value
     * @return int|null
     */
    private function resolve_reason(string $value): ?int {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $reasons = assignment_status::get_all_changereasons();
        if (preg_match('/^\d+$/', $value)) {
            $id = (int)$value;
            return array_key_exists($id, $reasons) ? $id : null;
        }
        $needle = \core_text::strtolower($value);
        foreach (self::CHANGE_REASONS as $name => $id) {
            if ($name === $needle) {
                return $id;
            }
        }
        foreach ($reasons as $id => $label) {
            if (\core_text::strtolower((string)$label) === $needle) {
                return (int)$id;
            }
        }
        return null;
    }

    /**
     * Comma separated list of the selectable, non-excluded statuses.
     *
     * @param string $lang
     * @return string
     */
    private function status_choice_list(string $lang): string {
        $names = [];
        foreach (assignment_status_facade::get_userchoices() as $id => $name) {
            if (assignment_status_facade::check_excluded((string)$id)) {
                continue;
            }
            $names[] = $this->status_label((int)$id, $lang) . ' (' . (int)$id . ')';
        }
        return implode(', ', $names);
    }

    /**
     * Comma separated list of the change reasons (canonical name plus label).
     *
     * @return string
     */
    private function reason_list(): string {
        $reasons = assignment_status::get_all_changereasons();
        $names = [];
        foreach (self::CHANGE_REASONS as $name => $id) {
            $names[] = $name . ' (' . (string)($reasons[$id] ?? $id) . ')';
        }
        return implode(', ', $names);
    }
}
