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
use local_taskflow\local\history\history;
use local_taskflow\local\wizard\engine\queue_identity_provider_interface;
use local_taskflow\local\wizard\engine\skill_risk_class;
use local_taskflow\local\wizard\taskflow\taskflow_input_normalizer;
use local_taskflow\local\wizard\taskflow\taskflow_result_link_builder;
use local_taskflow\local\wizard\taskflow\taskflow_settings_catalog;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;

/**
 * Mutating skill local_taskflow.extend_assignment_duedate (implementation plan §2 #23).
 *
 * Moves the due date of one assignment, either as a plain manual change
 * (assignment_manual_update_service::apply()) or as the supervisor decision on an extension
 * request (grant_extension() / deny_extension()). All writing, status transitions and history
 * entries live in the service; the skill only resolves the target date, enforces the scope and
 * the adapter's extension limit and verifies the stored record afterwards (plan §3.4).
 *
 * The extension limit is adapter driven: it applies only while the active adapter has
 * usingprolongedstate switched on, because only then does the prolonged state (and with it the
 * prolongedcounter) carry the extension semantics.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class extend_assignment_duedate_skill extends taskflow_skill_base implements queue_identity_provider_interface {
    /** Skill name. */
    public const TASK_NAME = 'local_taskflow.extend_assignment_duedate';

    /** Decision: grant the extension. */
    public const DECISION_GRANT = 'grant';

    /** Decision: deny the extension. */
    public const DECISION_DENY = 'deny';

    /** Maximum number of granted extensions while the adapter uses the prolonged state. */
    public const EXTENSION_LIMIT = 2;

    /** Issue code: neither or both of newduedate and extenddays were supplied. */
    public const ISSUE_DATE_INPUT = 'TASKFLOW_DUEDATE_INPUT_INVALID';

    /** Issue code: the resulting due date does not lie in the future. */
    public const ISSUE_DATE_NOT_FUTURE = 'TASKFLOW_DUEDATE_NOT_IN_FUTURE';

    /** Issue code: the adapter's extension limit is reached. */
    public const ISSUE_LIMIT_REACHED = 'TASKFLOW_EXTENSION_LIMIT_REACHED';

    /** Issue code: the decision value is unknown. */
    public const ISSUE_DECISION_UNKNOWN = 'TASKFLOW_DECISION_UNKNOWN';

    /** Issue code: the due date change awaits the user's confirmation. */
    public const ISSUE_CONFIRM_REQUIRED = 'TASKFLOW_DUEDATE_CHANGE_CONFIRM_REQUIRED';

    /** Number of history entries carried in the result preview. */
    private const PREVIEW_HISTORY_LIMIT = 5;

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
            'description' => 'Move the due date of one taskflow assignment, or decide on an extension request '
                . '(grant or deny). Writes a history entry and, where the adapter uses the prolonged state, '
                . 'sets the assignment to prolonged.',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'Extend assignment 4711 by 14 days',
                'Move the due date of assignment 4711 to 2026-10-31',
                'Deny the extension request for assignment 4711',
            ],
            'properties' => [
                'assignmentid' => [
                    'type' => 'integer',
                    'description' => 'Id of the assignment (find it with local_taskflow.search_assignments).',
                    'required' => true,
                ],
                'newduedate' => [
                    'type' => 'string',
                    'description' => 'New due date as ISO 8601 date/time or Unix timestamp. '
                        . 'Mutually exclusive with extenddays.',
                    'required' => false,
                ],
                'extenddays' => [
                    'type' => 'integer',
                    'description' => 'Number of days the due date is moved forward. Mutually exclusive with newduedate.',
                    'required' => false,
                ],
                'decision' => [
                    'type' => 'string',
                    'description' => 'Decision on an extension request: "' . self::DECISION_GRANT . '" or "'
                        . self::DECISION_DENY . '". Omit for a plain due date change.',
                    'required' => false,
                ],
                'comment' => [
                    'type' => 'string',
                    'description' => 'Free text stored with the history entry.',
                    'required' => false,
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
            'intent' => 'Move the due date of one identified assignment or decide on its extension request.',
            'input_fields_for_prompt' => ['assignmentid', 'newduedate', 'extenddays', 'decision'],
            'anchor_fields' => ['assignmentid'],
        ];
    }

    /**
     * Example input for the planner contract.
     *
     * @return array
     */
    public function get_example_input(): array {
        return ['assignmentid' => 4711, 'extenddays' => 14, 'decision' => self::DECISION_GRANT];
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
                'newduedate' => trim((string)($input['newduedate'] ?? '')),
                'extenddays' => taskflow_input_normalizer::to_int($input['extenddays'] ?? null) ?? 0,
                'decision' => \core_text::strtolower(trim((string)($input['decision'] ?? ''))),
            ],
        ];
    }

    /**
     * Structural check: assignment id plus exactly one date source (unless the extension is denied).
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
        $hasdate = trim((string)($input['newduedate'] ?? '')) !== '';
        $hasdays = (taskflow_input_normalizer::to_int($input['extenddays'] ?? null) ?? 0) !== 0;
        if ($hasdate && $hasdays) {
            $errors[] = $this->localized_string('agent_extend_duedate_date_conflict', null, $lang);
        }
        $decision = $this->resolve_decision($input);
        if (!$hasdate && !$hasdays && $decision !== self::DECISION_DENY) {
            $errors[] = $this->localized_string('agent_extend_duedate_date_required', null, $lang);
        }
        if ($decision === null && trim((string)($input['decision'] ?? '')) !== '') {
            $errors[] = $this->localized_string('agent_extend_duedate_decision_unknown', (object)[
                'value' => trim((string)$input['decision']),
                'known' => self::DECISION_GRANT . ', ' . self::DECISION_DENY,
            ], $lang);
        }
        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => []];
    }

    /**
     * Preflight: scope, target date, adapter extension limit, then confirmation.
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

        $decision = $this->resolve_decision($input);
        $counter = (int)($assignment->prolongedcounter ?? 0);
        if ($this->limit_active() && $decision !== self::DECISION_DENY && $counter >= self::EXTENSION_LIMIT) {
            return $this->invalid([[
                'code' => self::ISSUE_LIMIT_REACHED,
                'severity' => 'needs_clarification',
                'field' => 'assignmentid',
                'prolongedcounter' => $counter,
                'limit' => self::EXTENSION_LIMIT,
                'message' => $this->localized_string('agent_extend_duedate_limit', (object)[
                    'adapter' => taskflow_settings_catalog::active_adapter(),
                    'counter' => $counter,
                    'limit' => self::EXTENSION_LIMIT,
                ], $lang),
            ]]);
        }

        $newduedate = 0;
        if ($decision !== self::DECISION_DENY) {
            $newduedate = $this->target_duedate($input, (int)($assignment->duedate ?? 0));
            if ($newduedate === null) {
                return $this->invalid([[
                    'code' => self::ISSUE_DATE_INPUT,
                    'severity' => 'needs_clarification',
                    'field' => 'newduedate',
                    'message' => $this->localized_string(
                        'agent_date_invalid',
                        trim((string)($input['newduedate'] ?? '')),
                        $lang
                    ),
                ]]);
            }
            if ($newduedate <= time()) {
                return $this->invalid([[
                    'code' => self::ISSUE_DATE_NOT_FUTURE,
                    'severity' => 'needs_clarification',
                    'field' => 'newduedate',
                    'message' => $this->localized_string(
                        'agent_extend_duedate_not_future',
                        $this->format_date($newduedate),
                        $lang
                    ),
                ]]);
            }
        }

        $prepared = $input;
        $prepared['assignmentid'] = $assignmentid;
        $prepared['newduedate'] = $newduedate;
        $prepared['comment'] = trim((string)($input['comment'] ?? ''));
        unset($prepared['extenddays']);
        if ($decision !== null) {
            $prepared['decision'] = $decision;
        } else {
            unset($prepared['decision']);
        }

        $question = $decision === self::DECISION_DENY
            ? $this->localized_string('agent_extend_duedate_confirm_deny', (object)[
                'id' => $assignmentid,
                'fullname' => (string)$assignment->fullname,
            ], $lang)
            : $this->localized_string('agent_extend_duedate_confirm', (object)[
                'id' => $assignmentid,
                'fullname' => (string)$assignment->fullname,
                'from' => $this->format_date((int)($assignment->duedate ?? 0)),
                'to' => $this->format_date($newduedate),
            ], $lang);

        return $this->confirmable($prepared, [[
            'code' => self::ISSUE_CONFIRM_REQUIRED,
            'severity' => 'needs_confirmation',
            'user_question' => $question,
        ]]);
    }

    /**
     * Tier-3 confirmation preview: old and new due date, difference, resulting status, counter.
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
        $decision = $this->resolve_decision($input);
        $olddue = (int)($assignment->duedate ?? 0);
        $newdue = $decision === self::DECISION_DENY ? $olddue : ($this->target_duedate($input, $olddue) ?? 0);
        if ($decision !== self::DECISION_DENY && $newdue <= 0) {
            return null;
        }
        $counter = (int)($assignment->prolongedcounter ?? 0);
        $newcounter = $counter + 1;
        $statuslabel = $this->status_label($this->expected_status($assignment, $decision, $newdue), $lang);

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
                'label' => $this->localized_string('duedate', null, $lang),
                'value' => $decision === self::DECISION_DENY
                    ? $this->format_date($olddue)
                    : $this->format_date($olddue) . ' → ' . $this->format_date($newdue),
            ],
        ];
        if ($decision !== self::DECISION_DENY) {
            $rows[] = [
                'label' => $this->localized_string('agent_extend_duedate_row_days', null, $lang),
                'value' => (string)$this->day_difference($olddue, $newdue),
            ];
        }
        if ($decision !== null) {
            $rows[] = [
                'label' => $this->localized_string('agent_extend_duedate_row_decision', null, $lang),
                'value' => $this->localized_string(
                    $decision === self::DECISION_DENY
                        ? 'agent_extend_duedate_decision_deny'
                        : 'agent_extend_duedate_decision_grant',
                    null,
                    $lang
                ),
            ];
        }
        $rows[] = [
            'label' => $this->localized_string('agent_extend_duedate_row_status', null, $lang),
            'value' => $statuslabel,
        ];
        $rows[] = [
            'label' => $this->localized_string('agent_extend_duedate_row_counter', null, $lang),
            'value' => $counter . ' → ' . $newcounter,
        ];
        $comment = trim((string)($input['comment'] ?? ''));
        if ($comment !== '') {
            $rows[] = ['label' => $this->localized_string('comment', null, $lang), 'value' => $comment];
        }
        if ($this->limit_active()) {
            $rows[] = [
                'label' => $this->localized_string('agent_extend_duedate_row_warning', null, $lang),
                'value' => $this->localized_string('agent_extend_duedate_warning_limit', (object)[
                    'counter' => $newcounter,
                    'limit' => self::EXTENSION_LIMIT,
                ], $lang),
            ];
        }
        $adapter = taskflow_settings_catalog::active_adapter();
        if ($adapter !== 'standard') {
            $rows[] = [
                'label' => $this->localized_string('agent_extend_duedate_row_warning', null, $lang),
                'value' => $this->localized_string('agent_extend_duedate_warning_adapter', $adapter, $lang),
            ];
        }

        $summary = $decision === self::DECISION_DENY
            ? $this->localized_string('agent_extend_duedate_summary_deny', (object)[
                'duedate' => $this->format_date($olddue),
                'counter' => $newcounter,
            ], $lang)
            : $this->localized_string('agent_extend_duedate_summary', (object)[
                'days' => $this->day_difference($olddue, $newdue),
                'newdate' => $this->format_date($newdue),
                'status' => $statuslabel,
                'counter' => $newcounter,
            ], $lang);

        return [
            'title' => $this->localized_string('agent_extend_duedate_title', (object)[
                'id' => (int)$assignment->id,
                'fullname' => (string)$assignment->fullname,
            ], $lang),
            'summary' => $summary,
            'rows' => $rows,
        ];
    }

    /**
     * Execute: hand the decision to the service and verify the stored due date and status.
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

        $decision = $this->resolve_decision($input);
        $olddue = (int)($assignment->duedate ?? 0);
        $oldcounter = (int)($assignment->prolongedcounter ?? 0);
        $comment = trim((string)($input['comment'] ?? ''));

        if ($this->limit_active() && $decision !== self::DECISION_DENY && $oldcounter >= self::EXTENSION_LIMIT) {
            return $this->error_result(
                self::ISSUE_LIMIT_REACHED,
                $this->localized_string('agent_extend_duedate_limit', (object)[
                    'adapter' => taskflow_settings_catalog::active_adapter(),
                    'counter' => $oldcounter,
                    'limit' => self::EXTENSION_LIMIT,
                ], $lang),
                ['debugmessage' => $debug]
            );
        }

        $newdue = $decision === self::DECISION_DENY ? $olddue : ($this->target_duedate($input, $olddue) ?? 0);
        if ($decision !== self::DECISION_DENY && $newdue <= time()) {
            return $this->error_result(
                self::ISSUE_DATE_NOT_FUTURE,
                $this->localized_string('agent_extend_duedate_not_future', $this->format_date($newdue), $lang),
                ['debugmessage' => $debug]
            );
        }

        $service = new assignment_manual_update_service();
        try {
            if ($decision === self::DECISION_DENY) {
                $service->deny_extension($assignmentid, $userid, $comment);
            } else if ($decision === self::DECISION_GRANT) {
                $service->grant_extension($assignmentid, $newdue, $userid, $comment);
            } else {
                $service->apply(
                    $assignmentid,
                    [
                        'duedate' => $newdue,
                        'respectexcluded' => true,
                        'userid' => (int)$assignment->userid,
                    ],
                    $userid,
                    $comment
                );
            }
        } catch (\Throwable $e) {
            return $this->error_result(self::ISSUE_VERIFICATION_FAILED, $e->getMessage(), ['debugmessage' => $debug]);
        }

        $fresh = $this->fresh_assignment($assignmentid);
        $expected = ['duedate' => $newdue];
        if ($decision === self::DECISION_DENY) {
            $expected['prolongedcounter'] = $oldcounter + 1;
        } else if ($decision === self::DECISION_GRANT) {
            $expected['status'] = $this->expected_status($assignment, $decision, $newdue);
        }

        $change = $decision === self::DECISION_DENY
            ? $this->localized_string('agent_extend_duedate_decision_deny', null, $lang)
            : $this->localized_string('agent_extend_duedate_change', (object)[
                'from' => $this->format_date($olddue),
                'to' => $this->format_date($newdue),
            ], $lang);
        $links = $this->links(
            taskflow_result_link_builder::assignment_url($assignmentid),
            ['assignments_due_dates', 'assignments_edit', 'assignments_history'],
            ['edit' => taskflow_result_link_builder::edit_assignment_url($assignmentid)]
        );
        $usermessage = $decision === self::DECISION_DENY
            ? $this->localized_string('agent_extend_duedate_result_deny', (object)[
                'id' => $assignmentid,
                'fullname' => (string)$assignment->fullname,
                'counter' => (int)($fresh['prolongedcounter'] ?? 0),
            ], $lang)
            : $this->localized_string('agent_extend_duedate_result', (object)[
                'id' => $assignmentid,
                'fullname' => (string)$assignment->fullname,
                'from' => $this->format_date($olddue),
                'to' => $this->format_date($newdue),
                'status' => $this->status_label((int)($fresh['status'] ?? 0), $lang),
            ], $lang);

        $details = $this->assignment_details($assignmentid, $contextid, $userid, $lang, $change);
        $observation = [
            $usermessage,
            'assignmentid=' . $assignmentid . ', duedate_before=' . $olddue . ', duedate_after='
                . (int)($fresh['duedate'] ?? 0) . ', status_after=' . (int)($fresh['status'] ?? 0)
                . ', prolongedcounter=' . $oldcounter . '→' . (int)($fresh['prolongedcounter'] ?? 0),
            'decision=' . ($decision ?? '-') . ', history_type='
                . ($decision === self::DECISION_DENY ? history::TYPE_REQUEST_DECLINED
                    : ($decision === self::DECISION_GRANT ? history::TYPE_REQUEST_CONFIRMED
                        : history::TYPE_MANUAL_CHANGE)),
            'adapter=' . taskflow_settings_catalog::active_adapter()
                . ', extension_limit_active=' . ($this->limit_active() ? '1' : '0'),
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
                'duedate_before' => $olddue,
                'duedate_after' => (int)($fresh['duedate'] ?? 0),
                'prolongedcounter' => (int)($fresh['prolongedcounter'] ?? 0),
                'decision' => $decision ?? '',
                'change' => $change,
                'links' => $links,
                'debugmessage' => $debug,
                'preview' => $details['preview'],
            ],
            $lang
        );
    }

    /**
     * Normalized decision value, or null when none (or an unknown one) was supplied.
     *
     * @param array $input
     * @return string|null
     */
    private function resolve_decision(array $input): ?string {
        $value = \core_text::strtolower(trim((string)($input['decision'] ?? '')));
        if ($value === self::DECISION_GRANT || $value === self::DECISION_DENY) {
            return $value;
        }
        return null;
    }

    /**
     * Target due date from newduedate or extenddays, or null when the date cannot be read.
     *
     * extenddays follows the adapter form semantics: a due date in the future is extended from
     * itself, a due date in the past from the end of today.
     *
     * @param array $input
     * @param int $currentduedate
     * @return int|null
     */
    private function target_duedate(array $input, int $currentduedate): ?int {
        $raw = trim((string)($input['newduedate'] ?? ''));
        if ($raw !== '') {
            if (preg_match('/^\d+$/', $raw)) {
                return (int)$raw;
            }
            $timestamp = strtotime($raw);
            return $timestamp === false ? null : (int)$timestamp;
        }
        $days = taskflow_input_normalizer::to_int($input['extenddays'] ?? null) ?? 0;
        if ($days === 0) {
            return null;
        }
        $base = $currentduedate > time() ? $currentduedate : (int)strtotime('today 23:59');
        return $base + $days * DAYSECS;
    }

    /**
     * Status the assignment is expected to carry after the change.
     *
     * @param \stdClass $assignment Assignment data before the change.
     * @param string|null $decision
     * @param int $newduedate
     * @return int
     */
    private function expected_status(\stdClass $assignment, ?string $decision, int $newduedate): int {
        $current = (int)($assignment->status ?? 0);
        if ($decision === self::DECISION_DENY) {
            return $current;
        }
        $prolonged = assignment_status_facade::get_status_identifier('prolonged');
        if (assignment_status_facade::check_excluded((string)$prolonged)) {
            return $current;
        }
        if ($decision === self::DECISION_GRANT) {
            return $prolonged;
        }
        $overdue = assignment_status_facade::get_status_identifier('overdue');
        return ($current === $overdue && $newduedate > time()) ? $prolonged : $current;
    }

    /**
     * Whether the adapter's extension limit applies (usingprolongedstate switched on).
     *
     * @return bool
     */
    private function limit_active(): bool {
        $component = 'taskflowadapter_' . taskflow_settings_catalog::active_adapter();
        return !empty(get_config($component, 'usingprolongedstate'));
    }

    /**
     * Whole days between two timestamps.
     *
     * @param int $from
     * @param int $to
     * @return int
     */
    private function day_difference(int $from, int $to): int {
        if ($from <= 0 || $to <= 0) {
            return 0;
        }
        return (int)round(($to - $from) / DAYSECS);
    }

    /**
     * Date text ('-' when unset).
     *
     * @param int $timestamp
     * @return string
     */
    private function format_date(int $timestamp): string {
        if ($timestamp <= 0) {
            return '-';
        }
        return userdate($timestamp, get_string('strftimedatetime', 'langconfig'));
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
}
