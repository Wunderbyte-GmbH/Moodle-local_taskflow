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
use local_taskflow\local\requests;
use local_taskflow\local\requests\request_treatment_service;
use local_taskflow\local\wizard\engine\queue_identity_provider_interface;
use local_taskflow\local\wizard\engine\skill_risk_class;
use local_taskflow\local\wizard\taskflow\taskflow_input_normalizer;
use local_taskflow\local\wizard\taskflow\taskflow_result_link_builder;
use local_taskflow\local\wizard\taskflow\taskflow_settings_catalog;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use stdClass;

/**
 * Mutating skill local_taskflow.review_evidence (implementation plan §2 #29).
 *
 * Approves or rejects one uploaded competency evidence (a row of
 * local_taskflow_assgin_comp). The whole business logic lives in
 * local_taskflow\local\requests\request_treatment_service::apply_evidence_status(), the very
 * method local_taskflow\form\userevidence::process_set_status() calls, so the agent and the UI
 * write exactly the same data: the evidence status, the competency (set on approval, removed
 * on rejection) and the treated state of the belonging request.
 *
 * Capability note (ticket T-D1 of the implementation plan): the plugin does NOT have an own
 * capability for reviewing evidence. The status form is offered only to holders of
 * local/taskflow:editmessages (userevidence::set_data_for_dynamic_submission() switches
 * statusmode to 'setstatus' behind exactly that capability), while
 * local/taskflow:uploaduserevidence only governs looking at somebody else's evidence
 * (check_access_for_dynamic_submission(), statusmode 'view'). This skill therefore requires
 * local/taskflow:editmessages and nothing else, and says so in the description and in the
 * confirmation preview, so an administrator sees the odd gating instead of being surprised by it.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class review_evidence_skill extends taskflow_skill_base implements queue_identity_provider_interface {
    /** Skill name. */
    public const TASK_NAME = 'local_taskflow.review_evidence';

    /** Capability the plugin uses to gate the evidence status form (see class doc / T-D1). */
    public const CAPABILITY = 'local/taskflow:editmessages';

    /** Capability that only governs viewing somebody else's evidence, never the decision. */
    public const CAPABILITY_VIEW = 'local/taskflow:uploaduserevidence';

    /** Table holding one uploaded evidence per assignment and competency. */
    public const TABLE = 'local_taskflow_assgin_comp';

    /** Decision value: approve the evidence. */
    public const DECISION_APPROVE = 'approve';

    /** Decision value: reject the evidence. */
    public const DECISION_REJECT = 'reject';

    /** Evidence status written on approval. */
    public const STATUS_APPROVED = 'approved';

    /** Evidence status written on rejection. */
    public const STATUS_REJECTED = 'rejected';

    /** Issue code: the evidence row does not exist. */
    public const ISSUE_EVIDENCE_NOT_FOUND = 'TASKFLOW_EVIDENCE_NOT_FOUND';

    /** Issue code: the evidence is not identified (neither id nor assignment plus competency). */
    public const ISSUE_EVIDENCE_UNIDENTIFIED = 'TASKFLOW_EVIDENCE_UNIDENTIFIED';

    /** Issue code: the decision value is missing or unknown. */
    public const ISSUE_DECISION_UNKNOWN = 'TASKFLOW_EVIDENCE_DECISION_UNKNOWN';

    /** Issue code: no request belongs to the evidence, so the plugin cannot treat it. */
    public const ISSUE_REQUEST_MISSING = 'TASKFLOW_EVIDENCE_REQUEST_MISSING';

    /** Issue code: the decision awaits the user's confirmation. */
    public const ISSUE_CONFIRM = 'TASKFLOW_EVIDENCE_CONFIRM_REQUIRED';

    /** Number of history entries carried in the result preview. */
    private const PREVIEW_HISTORY_LIMIT = 5;

    /**
     * Constructor: mutating, R2, hard capability gate (see class doc).
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
            'description' => 'Approve or reject ONE uploaded competency evidence of a taskflow assignment. '
                . 'Approving sets the competency for the user and confirms the belonging request, rejecting '
                . 'removes the competency and declines the request. Identify the evidence either by its id '
                . '(assgincompid) or by the pair assignmentid plus competencyid. Note: the plugin gates the '
                . 'evidence decision with the capability local/taskflow:editmessages, not with an own review '
                . 'capability, and local/taskflow:uploaduserevidence is not sufficient.',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'Approve the evidence 88 until the end of next year',
                'Reject the uploaded evidence for competency 5 of assignment 4711',
                'Accept the certificate that Anna uploaded for assignment 4711',
            ],
            'properties' => [
                'assgincompid' => [
                    'type' => 'integer',
                    'description' => 'Id of the uploaded evidence (row of local_taskflow_assgin_comp). '
                        . 'Alternative to assignmentid plus competencyid.',
                    'required' => false,
                ],
                'assignmentid' => [
                    'type' => 'integer',
                    'description' => 'Id of the assignment the evidence belongs to (needs competencyid).',
                    'required' => false,
                ],
                'competencyid' => [
                    'type' => 'integer',
                    'description' => 'Id of the competency the evidence was uploaded for (needs assignmentid).',
                    'required' => false,
                ],
                'decision' => [
                    'type' => 'string',
                    'description' => 'Either "' . self::DECISION_APPROVE . '" or "' . self::DECISION_REJECT . '".',
                    'required' => true,
                ],
                'validuntil' => [
                    'type' => 'string',
                    'description' => 'Date until which the approved evidence stays valid (ISO 8601 date or Unix '
                        . 'timestamp). Only meaningful together with the decision "' . self::DECISION_APPROVE . '".',
                    'required' => false,
                ],
                'comment' => [
                    'type' => 'string',
                    'description' => 'Free text explaining the decision; it is reported back, not stored on the '
                        . 'evidence record.',
                    'required' => false,
                ],
            ],
            'required' => ['decision'],
        ];
    }

    /**
     * Prompt metadata.
     *
     * @return array<string,mixed>
     */
    protected function prompt_meta(): array {
        return [
            'intent' => 'Approve or reject one uploaded competency evidence of a taskflow assignment.',
            'input_fields_for_prompt' => ['decision', 'assgincompid or assignmentid + competencyid'],
            'anchor_fields' => ['assgincompid', 'assignmentid'],
        ];
    }

    /**
     * Example input for the planner contract.
     *
     * @return array
     */
    public function get_example_input(): array {
        return ['assgincompid' => 88, 'decision' => self::DECISION_APPROVE, 'validuntil' => '2027-12-31'];
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
                'assgincompid' => taskflow_input_normalizer::to_int($input['assgincompid'] ?? null) ?? 0,
                'assignmentid' => taskflow_input_normalizer::to_int($input['assignmentid'] ?? null) ?? 0,
                'competencyid' => taskflow_input_normalizer::to_int($input['competencyid'] ?? null) ?? 0,
            ],
            'change' => ['decision' => strtolower(trim((string)($input['decision'] ?? '')))],
        ];
    }

    /**
     * Structural check: decision plus one of the two identification variants.
     *
     * @param array $input
     * @return array{valid:bool,errors:string[],ambiguities:string[]}
     */
    public function check_structure(array $input): array {
        $lang = $this->get_output_language($input);
        $errors = [];
        if ($this->resolve_decision($input) === null) {
            $errors[] = $this->localized_string('agent_review_evidence_decision_required', null, $lang);
        }
        $byid = (taskflow_input_normalizer::to_int($input['assgincompid'] ?? null) ?? 0) > 0;
        $bypair = (taskflow_input_normalizer::to_int($input['assignmentid'] ?? null) ?? 0) > 0
            && (taskflow_input_normalizer::to_int($input['competencyid'] ?? null) ?? 0) > 0;
        if (!$byid && !$bypair) {
            $errors[] = $this->localized_string('agent_review_evidence_target_required', null, $lang);
        }
        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => []];
    }

    /**
     * Preflight: identify the evidence, check the scope and the belonging request, then confirm.
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
                    'code' => $this->resolve_decision($input) === null
                        ? self::ISSUE_DECISION_UNKNOWN
                        : self::ISSUE_EVIDENCE_UNIDENTIFIED,
                    'severity' => 'needs_clarification',
                    'message' => (string)$error,
                ];
            }
            return $this->invalid($issues);
        }

        if (!$this->may_review($userid)) {
            return $this->invalid([$this->scope_denied_issue($lang, ['field' => 'assgincompid'])]);
        }

        $evidence = $this->find_evidence($input);
        if ($evidence === null) {
            return $this->invalid([
                $this->not_found_issue(
                    self::ISSUE_EVIDENCE_NOT_FOUND,
                    $this->localized_string('agent_review_evidence_notfound', (object)[
                        'id' => taskflow_input_normalizer::to_int($input['assgincompid'] ?? null) ?? 0,
                        'assignmentid' => taskflow_input_normalizer::to_int($input['assignmentid'] ?? null) ?? 0,
                        'competencyid' => taskflow_input_normalizer::to_int($input['competencyid'] ?? null) ?? 0,
                    ], $lang),
                    ['field' => 'assgincompid']
                ),
            ]);
        }

        $requestid = $this->request_id_of($evidence);
        if ($requestid <= 0) {
            // The service apply_evidence_status() hands the request id to requests::treat_request(int $id):
            // without a request the plugin cannot treat the evidence at all, so nothing is written.
            return $this->invalid([[
                'code' => self::ISSUE_REQUEST_MISSING,
                'severity' => 'needs_clarification',
                'field' => 'assgincompid',
                'message' => $this->localized_string(
                    'agent_review_evidence_request_missing',
                    (int)$evidence->id,
                    $lang
                ),
            ]]);
        }

        $decision = (string)$this->resolve_decision($input);
        $prepared = $input;
        $prepared['assgincompid'] = (int)$evidence->id;
        $prepared['assignmentid'] = (int)$evidence->assignmentid;
        $prepared['competencyid'] = (int)$evidence->competencyid;
        $prepared['decision'] = $decision;
        $prepared['comment'] = trim((string)($input['comment'] ?? ''));
        $validuntil = $this->to_timestamp($input['validuntil'] ?? null);
        if ($validuntil > 0 && $decision === self::DECISION_APPROVE) {
            $prepared['validuntil'] = $validuntil;
        } else {
            unset($prepared['validuntil']);
        }

        return $this->confirmable($prepared, [[
            'code' => self::ISSUE_CONFIRM,
            'severity' => 'needs_confirmation',
            'user_question' => $this->localized_string('agent_review_evidence_confirm', (object)[
                'id' => (int)$evidence->id,
                'decision' => $this->decision_label($decision, $lang),
                'fullname' => $this->fullname_of((int)$evidence->userid),
                'assignmentid' => (int)$evidence->assignmentid,
            ], $lang),
        ]]);
    }

    /**
     * Tier-3 confirmation preview: evidence, decision, valid until and the capability note.
     *
     * @param array $input Prepared input.
     * @return array{title:string,summary:string,rows:array}|null
     */
    public function describe_proposed_action(array $input): ?array {
        $lang = $this->get_output_language($input);
        $evidence = $this->find_evidence($input);
        $decision = $this->resolve_decision($input);
        if ($evidence === null || $decision === null) {
            return null;
        }

        $rows = [
            [
                'label' => $this->localized_string('fullname', null, $lang),
                'value' => $this->fullname_of((int)$evidence->userid),
            ],
            [
                'label' => $this->localized_string('assignment', null, $lang),
                'value' => '#' . (int)$evidence->assignmentid . ' ' . $this->rulename_of((int)$evidence->assignmentid),
            ],
            [
                'label' => $this->localized_string('agent_review_evidence_row_evidence', null, $lang),
                'value' => $this->evidence_text($evidence, $lang),
            ],
            [
                'label' => $this->localized_string('agent_review_evidence_row_decision', null, $lang),
                'value' => $this->decision_label($decision, $lang) . ' ('
                    . $this->evidence_status_for($decision) . ')',
            ],
        ];

        $validuntil = $this->to_timestamp($input['validuntil'] ?? null);
        if ($validuntil > 0 && $decision === self::DECISION_APPROVE) {
            $rows[] = [
                'label' => $this->localized_string('agent_review_evidence_row_validuntil', null, $lang),
                'value' => userdate($validuntil, get_string('strftimedate', 'langconfig')),
            ];
        }
        $comment = trim((string)($input['comment'] ?? ''));
        if ($comment !== '') {
            $rows[] = ['label' => $this->localized_string('comment', null, $lang), 'value' => $comment];
        }
        $rows[] = [
            'label' => $this->localized_string('agent_review_evidence_row_capability', null, $lang),
            'value' => $this->localized_string('agent_review_evidence_capability_note', (object)[
                'capability' => self::CAPABILITY,
                'viewcapability' => self::CAPABILITY_VIEW,
            ], $lang),
        ];
        $adapter = taskflow_settings_catalog::active_adapter();
        if ($adapter !== 'standard') {
            $rows[] = [
                'label' => $this->localized_string('agent_preview_warning', null, $lang),
                'value' => $this->localized_string('agent_change_status_warning_adapter', $adapter, $lang),
            ];
        }

        return [
            'title' => $this->localized_string('agent_review_evidence_title', (object)[
                'id' => (int)$evidence->id,
                'fullname' => $this->fullname_of((int)$evidence->userid),
            ], $lang),
            'summary' => $this->localized_string('agent_review_evidence_summary_' . $decision, (object)[
                'assignmentid' => (int)$evidence->assignmentid,
            ], $lang),
            'rows' => $rows,
        ];
    }

    /**
     * Execute: hand the decision to the request_treatment_service and verify evidence and request.
     *
     * @param array $input Prepared input.
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        global $DB;

        $lang = $this->get_output_language($input);
        $links = $this->links(taskflow_result_link_builder::dashboard_url(), ['competencies_and_certificates']);
        $debug = $this->build_task_debug_message(self::TASK_NAME, $input);

        if (!$this->may_review($userid)) {
            return $this->error_result(
                self::ISSUE_SCOPE_DENIED,
                $this->localized_string('agent_scope_denied', null, $lang),
                ['links' => $links, 'debugmessage' => $debug]
            );
        }
        $decision = $this->resolve_decision($input);
        if ($decision === null) {
            return $this->error_result(
                self::ISSUE_DECISION_UNKNOWN,
                $this->localized_string('agent_review_evidence_decision_required', null, $lang),
                ['links' => $links, 'debugmessage' => $debug]
            );
        }
        $evidence = $this->find_evidence($input);
        if ($evidence === null) {
            return $this->error_result(
                self::ISSUE_EVIDENCE_NOT_FOUND,
                $this->localized_string('agent_review_evidence_notfound', (object)[
                    'id' => taskflow_input_normalizer::to_int($input['assgincompid'] ?? null) ?? 0,
                    'assignmentid' => taskflow_input_normalizer::to_int($input['assignmentid'] ?? null) ?? 0,
                    'competencyid' => taskflow_input_normalizer::to_int($input['competencyid'] ?? null) ?? 0,
                ], $lang),
                ['links' => $links, 'debugmessage' => $debug]
            );
        }
        $requestid = $this->request_id_of($evidence);
        if ($requestid <= 0) {
            return $this->error_result(
                self::ISSUE_REQUEST_MISSING,
                $this->localized_string('agent_review_evidence_request_missing', (int)$evidence->id, $lang),
                ['links' => $links, 'debugmessage' => $debug]
            );
        }

        $assgincompid = (int)$evidence->id;
        $assignmentid = (int)$evidence->assignmentid;
        $owner = (int)$evidence->userid;
        $status = $this->evidence_status_for($decision);
        $validuntil = $decision === self::DECISION_APPROVE ? $this->to_timestamp($input['validuntil'] ?? null) : 0;
        $comment = trim((string)($input['comment'] ?? ''));

        try {
            (new request_treatment_service())->apply_evidence_status(
                $assgincompid,
                $status,
                $owner,
                $assignmentid,
                $validuntil,
                $userid
            );
        } catch (\Throwable $e) {
            return $this->error_result(
                self::ISSUE_VERIFICATION_FAILED,
                $this->localized_string('agent_review_evidence_failed', $e->getMessage(), $lang),
                ['links' => $links, 'resultid' => $assgincompid, 'debugmessage' => $debug]
            );
        }

        $expectedtreated = $decision === self::DECISION_APPROVE
            ? requests::TREATED_STATUS_CONFIRMED
            : requests::TREATED_STATUS_DECLINED;
        $expected = ['status' => $status, 'treated' => $expectedtreated];
        if ($validuntil > 0) {
            $expected['validationondate'] = $validuntil;
        }

        $reader = static function () use ($DB, $assgincompid, $requestid): ?array {
            $record = $DB->get_record(self::TABLE, ['id' => $assgincompid], '*', IGNORE_MISSING);
            if (!$record) {
                return null;
            }
            return [
                'status' => (string)$record->status,
                'validationondate' => (int)$record->validationondate,
                'treated' => (int)$DB->get_field(
                    'local_taskflow_requests',
                    'treated',
                    ['id' => $requestid],
                    IGNORE_MISSING
                ),
            ];
        };

        $fullname = $this->fullname_of($owner);
        $usermessage = $this->localized_string('agent_review_evidence_result', (object)[
            'id' => $assgincompid,
            'fullname' => $fullname,
            'status' => $status,
            'assignmentid' => $assignmentid,
        ], $lang);

        $change = $this->localized_string('agent_review_evidence_change', (object)[
            'status' => $status,
            'decision' => $this->decision_label($decision, $lang),
            'validuntil' => $validuntil > 0
                ? userdate($validuntil, get_string('strftimedate', 'langconfig'))
                : $this->localized_string('agent_preview_none', null, $lang),
        ], $lang);

        $details = $this->assignment_details($assignmentid, $contextid, $userid, $lang, $change);
        $observation = [
            $usermessage,
            'assgincompid=' . $assgincompid . ', assignmentid=' . $assignmentid . ', competencyid='
                . (int)$evidence->competencyid . ', userid=' . $owner,
            'evidence_status=' . $status . ', validationondate=' . $validuntil
                . ', requestid=' . $requestid . ', treated=' . $expectedtreated,
            'capability_gate=' . self::CAPABILITY,
        ];
        if ($comment !== '') {
            $observation[] = 'comment=' . $comment;
        }

        return $this->verified_result(
            $expected,
            $reader,
            [
                'detail' => $usermessage,
                'usermessage' => $usermessage,
                'observation_full' => implode("\n", $observation),
                'resultid' => $assgincompid,
                'assgincompid' => $assgincompid,
                'assignmentid' => $assignmentid,
                'competencyid' => (int)$evidence->competencyid,
                'userid' => $owner,
                'decision' => $decision,
                'evidencestatus' => $status,
                'validuntil' => $validuntil,
                'requestid' => $requestid,
                'treated' => $expectedtreated,
                'assignment' => $details['assignment'],
                'change' => $change,
                'links' => $this->links(
                    taskflow_result_link_builder::assignment_url($assignmentid),
                    ['competencies_and_certificates', 'requests']
                ),
                'outputlang' => $lang,
                'debugmessage' => $debug,
                'preview' => $details['preview'],
            ],
            $lang
        );
    }

    /**
     * Whether the acting user may decide about an uploaded evidence.
     *
     * Mirrors userevidence::set_data_for_dynamic_submission(): only editmessages opens the
     * status form; uploaduserevidence alone never does (see class doc, ticket T-D1).
     *
     * @param int $userid
     * @return bool
     */
    private function may_review(int $userid): bool {
        return $userid > 0 && has_capability(self::CAPABILITY, context_system::instance(), $userid);
    }

    /**
     * Evidence row by id or by the pair assignmentid plus competencyid.
     *
     * @param array $input
     * @return stdClass|null
     */
    private function find_evidence(array $input): ?stdClass {
        global $DB;

        $id = taskflow_input_normalizer::to_int($input['assgincompid'] ?? null) ?? 0;
        if ($id > 0) {
            $record = $DB->get_record(self::TABLE, ['id' => $id], '*', IGNORE_MISSING);
            return $record ?: null;
        }
        $assignmentid = taskflow_input_normalizer::to_int($input['assignmentid'] ?? null) ?? 0;
        $competencyid = taskflow_input_normalizer::to_int($input['competencyid'] ?? null) ?? 0;
        if ($assignmentid <= 0 || $competencyid <= 0) {
            return null;
        }
        $records = $DB->get_records(
            self::TABLE,
            ['assignmentid' => $assignmentid, 'competencyid' => $competencyid],
            'id DESC',
            '*',
            0,
            1
        );
        $record = reset($records);
        return $record ?: null;
    }

    /**
     * Id of the evidence request belonging to the evidence row (0 when there is none).
     *
     * @param stdClass $evidence
     * @return int
     */
    private function request_id_of(stdClass $evidence): int {
        return (int)request_treatment_service::get_request_id_by_assignment_competency(
            (int)$evidence->userid,
            (int)$evidence->assignmentid,
            (int)$evidence->id
        );
    }

    /**
     * Canonical decision value of the input, or null when it names none.
     *
     * @param array $input
     * @return string|null
     */
    private function resolve_decision(array $input): ?string {
        $value = \core_text::strtolower(trim((string)($input['decision'] ?? '')));
        return in_array($value, [self::DECISION_APPROVE, self::DECISION_REJECT], true) ? $value : null;
    }

    /**
     * Evidence status the decision writes.
     *
     * @param string $decision
     * @return string
     */
    private function evidence_status_for(string $decision): string {
        return $decision === self::DECISION_APPROVE ? self::STATUS_APPROVED : self::STATUS_REJECTED;
    }

    /**
     * Localized label of a decision value.
     *
     * @param string $decision
     * @param string $lang
     * @return string
     */
    private function decision_label(string $decision, string $lang): string {
        return $this->localized_string(
            $decision === self::DECISION_APPROVE
                ? 'userevidencestatus_approved'
                : 'userevidencestatus_rejected',
            null,
            $lang
        );
    }

    /**
     * Human readable description of the evidence row (competency, current status, upload date).
     *
     * @param stdClass $evidence
     * @param string $lang
     * @return string
     */
    private function evidence_text(stdClass $evidence, string $lang): string {
        $parts = ['#' . (int)$evidence->id];
        $name = $this->competency_name((int)$evidence->competencyid);
        if ($name !== '') {
            $parts[] = $name;
        }
        $parts[] = $this->localized_string('userevidencestatus', null, $lang) . ': '
            . (string)($evidence->status ?? '');
        $created = (int)($evidence->timecreated ?? 0);
        if ($created > 0) {
            $parts[] = userdate($created, get_string('strftimedate', 'langconfig'));
        }
        return implode(' · ', $parts);
    }

    /**
     * Short name of a competency ('' when unknown or the competency subsystem is unavailable).
     *
     * @param int $competencyid
     * @return string
     */
    private function competency_name(int $competencyid): string {
        global $DB;

        if ($competencyid <= 0) {
            return '';
        }
        return (string)$DB->get_field('competency', 'shortname', ['id' => $competencyid], IGNORE_MISSING);
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

    /**
     * Rule name of an assignment ('' when unknown).
     *
     * @param int $assignmentid
     * @return string
     */
    private function rulename_of(int $assignmentid): string {
        global $DB;

        if ($assignmentid <= 0) {
            return '';
        }
        $ruleid = (int)$DB->get_field('local_taskflow_assignment', 'ruleid', ['id' => $assignmentid], IGNORE_MISSING);
        if ($ruleid <= 0) {
            return '';
        }
        return (string)$DB->get_field('local_taskflow_rules', 'rulename', ['id' => $ruleid], IGNORE_MISSING);
    }

    /**
     * Assignment payload and preview card, reused from the read-only details skill.
     *
     * @param int $assignmentid
     * @param int $contextid
     * @param int $userid
     * @param string $lang
     * @param string $change Change line shown in the card.
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
     * Unix timestamp of an ISO date or numeric input (0 when empty or unparsable).
     *
     * @param mixed $value
     * @return int
     */
    private function to_timestamp($value): int {
        if ($value === null) {
            return 0;
        }
        if (is_int($value) || (is_string($value) && preg_match('/^\d{6,}$/', trim($value)))) {
            return (int)$value;
        }
        $value = trim((string)$value);
        if ($value === '') {
            return 0;
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? 0 : (int)$timestamp;
    }
}
