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
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\assignments\assignment;
use local_taskflow\local\history\history;
use local_taskflow\local\requests;
use local_taskflow\local\requests\request_receivers\receiver_facade;
use local_taskflow\local\requests\request_receivers\receivers\hr_receiver;
use local_taskflow\local\requests\request_receivers\receivers\supervisor_receiver;
use local_taskflow\local\requests\request_treatment_service;
use local_taskflow\local\requests\request_types\requests_manager;
use local_taskflow\local\requests\request_types\types\allowselfextension;
use local_taskflow\local\requests\request_types\types\allowselfnotrelevant;
use local_taskflow\local\requests\request_types\types\allowuploadevidence;
use local_taskflow\local\wizard\engine\observation_time;
use local_taskflow\local\wizard\engine\queue_identity_provider_interface;
use local_taskflow\local\wizard\engine\skill_risk_class;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\taskflow_input_normalizer;
use local_taskflow\local\wizard\taskflow\taskflow_result_link_builder;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use stdClass;

/**
 * Mutating skill local_taskflow.treat_request (implementation plan §2 #28).
 *
 * Confirms or declines one taskflow request. The whole business logic lives in
 * local_taskflow\local\requests\request_treatment_service (the same service the UI uses):
 * type 1 sets the assignment to "not relevant", type 2 optionally grants the extension through
 * the assignment_manual_update_service, type 3 approves / rejects the uploaded evidence. This
 * skill only resolves the target, enforces the receiver scope and verifies the outcome.
 *
 * Access derives from engine state only: local/taskflow:treatrequests plus either being the
 * addressed receiver (supervisor / deputy of the requesting user for the supervisor receiver,
 * membership in the hrusers setting for the HR receiver) or holding
 * local/taskflow:viewallrequests.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class treat_request_skill extends taskflow_skill_base implements queue_identity_provider_interface {
    /** Skill name. */
    public const TASK_NAME = 'local_taskflow.treat_request';

    /** Capability required to treat a request. */
    public const CAPABILITY = 'local/taskflow:treatrequests';

    /** Capability that replaces the receiver relationship. */
    public const CAPABILITY_ALL = 'local/taskflow:viewallrequests';

    /** Decision value: confirm the request. */
    public const DECISION_CONFIRM = 'confirm';

    /** Decision value: decline the request. */
    public const DECISION_DECLINE = 'decline';

    /** Issue code: the request does not exist. */
    public const ISSUE_REQUEST_NOT_FOUND = 'TASKFLOW_REQUEST_NOT_FOUND';

    /** Issue code: the request was already treated. */
    public const ISSUE_ALREADY_TREATED = 'TASKFLOW_REQUEST_ALREADY_TREATED';

    /** Issue code: the acting user is not the addressed receiver. */
    public const ISSUE_NOT_RECEIVER = 'TASKFLOW_REQUEST_NOT_RECEIVER';

    /** Issue code: a new due date was passed for a request type that has none. */
    public const ISSUE_DUEDATE_UNSUPPORTED = 'TASKFLOW_REQUEST_DUEDATE_UNSUPPORTED';

    /** Issue code: the service reported that the request was not treated. */
    public const ISSUE_TREATMENT_FAILED = 'TASKFLOW_REQUEST_TREATMENT_FAILED';

    /** Issue code: the mutation needs an explicit confirmation. */
    public const ISSUE_CONFIRM = 'TASKFLOW_TREAT_REQUEST_CONFIRM_REQUIRED';

    /**
     * Constructor: mutating, R1, hard capability gate on local/taskflow:treatrequests.
     */
    public function __construct() {
        parent::__construct(false, skill_risk_class::R1, [self::CAPABILITY]);
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
            'description' => 'Confirm or decline ONE taskflow request that is addressed to the acting user. Request types: a '
                . 'not-relevant request (the assignment becomes "not relevant" on confirmation), an extension request (a '
                . 'new due date can be granted) or an uploaded evidence (approved or rejected). Use list_requests first '
                . 'to find the request id.',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'Confirm request 88',
                'Decline the extension request of Anna Muster',
                'Approve the evidence of request 91',
                'Grant the extension until 30 September',
            ],
            'properties' => [
                'requestid' => [
                    'type' => 'integer',
                    'description' => 'Id of the request to treat.',
                    'required' => true,
                ],
                'decision' => [
                    'type' => 'string',
                    'enum' => [self::DECISION_CONFIRM, self::DECISION_DECLINE],
                    'description' => 'confirm or decline.',
                    'required' => true,
                ],
                'comment' => [
                    'type' => 'string',
                    'description' => 'Optional comment written into the assignment history.',
                    'required' => false,
                ],
                'newduedate' => [
                    'type' => 'string',
                    'description' => 'Only for an extension request (type ' . allowselfextension::ID . ') that is '
                        . 'confirmed: the new due date as ISO 8601 date or Unix timestamp.',
                    'required' => false,
                ],
            ],
            'required' => ['requestid', 'decision'],
        ];
    }

    /**
     * Prompt metadata.
     *
     * @return array<string,mixed>
     */
    protected function prompt_meta(): array {
        return [
            'intent' => 'Confirm or decline a taskflow request addressed to the acting user.',
            'anchor_fields' => ['requestid'],
        ];
    }

    /**
     * Example input.
     *
     * @return array
     */
    public function get_example_input(): array {
        return ['requestid' => 88, 'decision' => self::DECISION_CONFIRM];
    }

    /**
     * Queue business identity: one decision per request.
     *
     * @param array $input
     * @return array<string,mixed>
     */
    public function build_queue_business_identity(array $input): array {
        return [
            'task_family' => self::TASK_NAME,
            'target' => [
                'requestid' => (int)(taskflow_input_normalizer::to_int($input['requestid'] ?? null) ?? 0),
            ],
            'decision' => strtolower(trim((string)($input['decision'] ?? ''))),
        ];
    }

    /**
     * Tier-3 confirmation preview: person, assignment, type, decision, resulting effect.
     *
     * @param array $input Prepared input.
     * @return array|null
     */
    public function describe_proposed_action(array $input): ?array {
        $lang = $this->get_output_language($input);
        $requestid = (int)(taskflow_input_normalizer::to_int($input['requestid'] ?? null) ?? 0);
        if ($requestid <= 0) {
            return null;
        }
        $decision = strtolower(trim((string)($input['decision'] ?? '')));
        $decisionlabel = $this->decision_label($decision, $lang);
        $comment = trim((string)($input['comment'] ?? ''));

        $rows = [
            [
                'label' => $this->localized_string('requestinguser', null, $lang),
                'value' => (string)($input['fullname'] ?? ''),
            ],
            [
                'label' => $this->localized_string('assignment', null, $lang),
                'value' => '#' . (int)($input['assignmentid'] ?? 0)
                    . (trim((string)($input['rulename'] ?? '')) === '' ? '' : ' ' . (string)$input['rulename']),
            ],
            [
                'label' => $this->localized_string('type', null, $lang),
                'value' => (string)($input['typelabel'] ?? ''),
            ],
            [
                'label' => $this->localized_string('agent_preview_decision', null, $lang),
                'value' => $decisionlabel,
            ],
            [
                'label' => $this->localized_string('agent_preview_effect', null, $lang),
                'value' => (string)($input['effect'] ?? ''),
            ],
        ];
        if ($comment !== '') {
            $rows[] = ['label' => $this->localized_string('comment', null, $lang), 'value' => $comment];
        }

        return [
            'title' => $this->localized_string('agent_treat_request_title', (object)[
                'id' => $requestid,
                'decision' => $decisionlabel,
                'type' => (string)($input['typelabel'] ?? ''),
                'fullname' => (string)($input['fullname'] ?? ''),
            ], $lang),
            'summary' => (string)($input['effect'] ?? ''),
            'rows' => $rows,
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
        $lang = $this->get_output_language($input);

        $requestid = taskflow_input_normalizer::to_int($input['requestid'] ?? null);
        if ($requestid === null || $requestid <= 0) {
            $errors[] = $this->localized_string('agent_treat_request_requestid_required', null, $lang);
        }
        $decision = strtolower(trim((string)($input['decision'] ?? '')));
        if (!in_array($decision, [self::DECISION_CONFIRM, self::DECISION_DECLINE], true)) {
            $errors[] = $this->localized_string('agent_treat_request_decision_required', null, $lang);
        }
        if (
            isset($input['newduedate'])
            && trim((string)$input['newduedate']) !== ''
            && $this->to_timestamp($input['newduedate']) <= 0
        ) {
            $errors[] = $this->localized_string('agent_date_invalid', (string)$input['newduedate'], $lang);
        }

        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => []];
    }

    /**
     * Preflight: receiver scope, untreated state, due date only for extension requests.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array{status:string,prepared_input:array,issues:array}
     */
    protected function run_preflight(array $input, int $contextid, int $userid): array {
        global $DB;

        $lang = $this->get_output_language($input);

        $structure = $this->check_structure($input);
        if (!($structure['valid'] ?? false)) {
            $issues = [];
            foreach ((array)($structure['errors'] ?? []) as $error) {
                $issues[] = [
                    'code' => 'VALIDATION_ERROR',
                    'severity' => 'needs_clarification',
                    'message' => (string)$error,
                ];
            }
            return $this->invalid($issues);
        }

        if (!has_capability(self::CAPABILITY, context_system::instance(), $userid)) {
            return $this->invalid([$this->scope_denied_issue($lang, ['field' => 'requestid'])]);
        }

        $requestid = (int)taskflow_input_normalizer::to_int($input['requestid']);
        $request = $DB->get_record('local_taskflow_requests', ['id' => $requestid], '*', IGNORE_MISSING);
        if (!$request) {
            return $this->invalid([
                $this->not_found_issue(
                    self::ISSUE_REQUEST_NOT_FOUND,
                    $this->localized_string('agent_notfound_request', $requestid, $lang),
                    ['field' => 'requestid']
                ),
            ]);
        }

        if (!$this->may_treat($request, $userid)) {
            return $this->invalid([[
                'code' => self::ISSUE_NOT_RECEIVER,
                'severity' => 'needs_clarification',
                'field' => 'requestid',
                'message' => $this->localized_string('agent_treat_request_denied', null, $lang),
            ]]);
        }

        if ((int)$request->treated !== requests::TREATED_STATUS_UNTREATED) {
            $treatment = $this->treatment_record($request);
            return $this->invalid([[
                'code' => self::ISSUE_ALREADY_TREATED,
                'severity' => 'needs_clarification',
                'field' => 'requestid',
                'message' => $this->localized_string('agent_treat_request_already', (object)[
                    'id' => $requestid,
                    'state' => requests::resolve_treated((int)$request->treated),
                    'user' => $treatment['fullname'],
                    'time' => $treatment['time'],
                ], $lang),
            ]]);
        }

        $decision = strtolower(trim((string)$input['decision']));
        $type = $this->request_type_id($request);
        $newduedate = $this->to_timestamp($input['newduedate'] ?? null);
        if ($newduedate > 0 && $type !== allowselfextension::ID) {
            return $this->invalid([[
                'code' => self::ISSUE_DUEDATE_UNSUPPORTED,
                'severity' => 'needs_clarification',
                'field' => 'newduedate',
                'message' => $this->localized_string('agent_treat_request_newduedate_unsupported', null, $lang),
            ]]);
        }

        $prepared = $input;
        $prepared['requestid'] = $requestid;
        $prepared['decision'] = $decision;
        $prepared['comment'] = trim((string)($input['comment'] ?? ''));
        $prepared['requesttype'] = $type;
        $prepared['typelabel'] = $this->type_label($type, $lang);
        $prepared['assignmentid'] = (int)$request->assignmentid;
        $prepared['requestuserid'] = (int)$request->userid;
        $prepared['fullname'] = $this->fullname_of((int)$request->userid);
        $prepared['rulename'] = $this->rulename_of((int)$request->assignmentid);
        if ($newduedate > 0) {
            $prepared['newduedate'] = $newduedate;
        } else {
            unset($prepared['newduedate']);
        }
        $prepared['effect'] = $this->effect_text($type, $decision, (int)$request->assignmentid, $newduedate, $lang);

        return $this->confirmable($prepared, [[
            'code' => self::ISSUE_CONFIRM,
            'severity' => 'needs_confirmation',
            'user_question' => $this->localized_string('agent_treat_request_confirm', (object)[
                'id' => $requestid,
                'decision' => $this->decision_label($decision, $lang),
                'type' => $prepared['typelabel'],
                'fullname' => $prepared['fullname'],
            ], $lang),
        ]]);
    }

    /**
     * Execute: hand the decision to the request_treatment_service and verify the outcome.
     *
     * @param array $input Prepared input.
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        global $DB;

        $lang = $this->get_output_language($input);
        $requestid = (int)(taskflow_input_normalizer::to_int($input['requestid'] ?? null) ?? 0);
        $decision = strtolower(trim((string)($input['decision'] ?? '')));
        $comment = trim((string)($input['comment'] ?? ''));
        $links = $this->links(taskflow_result_link_builder::dashboard_url(), ['requests']);

        if (!has_capability(self::CAPABILITY, context_system::instance(), $userid)) {
            return $this->error_result(
                self::ISSUE_SCOPE_DENIED,
                $this->localized_string('agent_scope_denied', null, $lang),
                ['links' => $links]
            );
        }
        if (!in_array($decision, [self::DECISION_CONFIRM, self::DECISION_DECLINE], true)) {
            return $this->error_result(
                'VALIDATION_ERROR',
                $this->localized_string('agent_treat_request_decision_required', null, $lang),
                ['links' => $links]
            );
        }

        $request = $DB->get_record('local_taskflow_requests', ['id' => $requestid], '*', IGNORE_MISSING);
        if (!$request) {
            return $this->error_result(
                self::ISSUE_REQUEST_NOT_FOUND,
                $this->localized_string('agent_notfound_request', $requestid, $lang),
                ['links' => $links]
            );
        }
        if (!$this->may_treat($request, $userid)) {
            return $this->error_result(
                self::ISSUE_NOT_RECEIVER,
                $this->localized_string('agent_treat_request_denied', null, $lang),
                ['links' => $links]
            );
        }
        if ((int)$request->treated !== requests::TREATED_STATUS_UNTREATED) {
            $treatment = $this->treatment_record($request);
            return $this->error_result(
                self::ISSUE_ALREADY_TREATED,
                $this->localized_string('agent_treat_request_already', (object)[
                    'id' => $requestid,
                    'state' => requests::resolve_treated((int)$request->treated),
                    'user' => $treatment['fullname'],
                    'time' => $treatment['time'],
                ], $lang),
                ['links' => $links, 'resultid' => $requestid]
            );
        }

        $type = $this->request_type_id($request);
        $assignmentid = (int)$request->assignmentid;
        $newduedate = $this->to_timestamp($input['newduedate'] ?? null);
        if ($newduedate > 0 && $type !== allowselfextension::ID) {
            return $this->error_result(
                self::ISSUE_DUEDATE_UNSUPPORTED,
                $this->localized_string('agent_treat_request_newduedate_unsupported', null, $lang),
                ['links' => $links]
            );
        }

        $options = ['comment' => $comment];
        if ($newduedate > 0) {
            $options['newduedate'] = $newduedate;
        }

        $service = new request_treatment_service();
        try {
            $outcome = $decision === self::DECISION_CONFIRM
                ? $service->confirm($requestid, $userid, $options)
                : $service->decline($requestid, $userid, $options);
        } catch (\Throwable $e) {
            return $this->error_result(
                self::ISSUE_TREATMENT_FAILED,
                $this->localized_string('agent_treat_request_failed', $e->getMessage(), $lang),
                ['links' => $links, 'resultid' => $requestid]
            );
        }
        if (empty($outcome->success)) {
            return $this->error_result(
                self::ISSUE_TREATMENT_FAILED,
                $this->localized_string('agent_treat_request_failed', '', $lang),
                ['links' => $links, 'resultid' => $requestid]
            );
        }

        $expectedtreated = $decision === self::DECISION_CONFIRM
            ? requests::TREATED_STATUS_CONFIRMED
            : requests::TREATED_STATUS_DECLINED;

        $expected = ['treated' => $expectedtreated];
        if ($type === allowselfnotrelevant::ID && $decision === self::DECISION_CONFIRM) {
            $expected['assignmentstatus'] = assignment_status_facade::get_status_identifier('notrelevant');
        }
        if ($type === allowselfextension::ID && $decision === self::DECISION_CONFIRM && $newduedate > 0) {
            $expected['assignmentduedate'] = $newduedate;
        }

        $reader = static function () use ($DB, $requestid, $assignmentid): ?array {
            $record = $DB->get_record('local_taskflow_requests', ['id' => $requestid], '*', IGNORE_MISSING);
            if (!$record) {
                return null;
            }
            $actual = ['treated' => (int)$record->treated];
            if ($assignmentid > 0) {
                assignment::destroy_instance($assignmentid);
                $stored = $DB->get_record('local_taskflow_assignment', ['id' => $assignmentid], '*', IGNORE_MISSING);
                $actual['assignmentstatus'] = $stored ? (int)$stored->status : null;
                $actual['assignmentduedate'] = $stored ? (int)$stored->duedate : null;
            }
            return $actual;
        };

        $typelabel = $this->type_label($type, $lang);
        $fullname = $this->fullname_of((int)$request->userid);
        $effect = $this->effect_text($type, $decision, $assignmentid, $newduedate, $lang);
        $usermessage = $this->localized_string('agent_treat_request_summary', (object)[
            'id' => $requestid,
            'type' => $typelabel,
            'fullname' => $fullname,
            'decision' => requests::resolve_treated($expectedtreated),
        ], $lang);

        $row = [
            'id' => $requestid,
            'type' => $type,
            'typelabel' => $typelabel,
            'treated' => $expectedtreated,
            'treatedlabel' => requests::resolve_treated($expectedtreated),
            'userid' => (int)$request->userid,
            'fullname' => $fullname,
            'assignmentid' => $assignmentid,
            'rulename' => $this->rulename_of($assignmentid),
            'receiver' => (int)($request->forhr ?? 0),
            'receiverlabel' => $this->receiver_label((int)($request->forhr ?? 0), $lang),
            'comment' => $comment !== '' ? $comment : (string)($request->comment ?? ''),
            'timecreated' => (int)$request->timecreated,
            'url' => $assignmentid > 0 ? taskflow_result_link_builder::assignment_url($assignmentid) : '',
        ];

        return $this->verified_result(
            $expected,
            $reader,
            [
                'detail' => $usermessage,
                'usermessage' => $usermessage,
                'observation_full' => $usermessage . "\n" . $effect,
                'resultid' => $requestid,
                'request' => $row,
                'decision' => $decision,
                'effect' => $effect,
                'evidencestatus' => (string)($outcome->evidencestatus ?? ''),
                'links' => $this->links(
                    $assignmentid > 0 ? taskflow_result_link_builder::assignment_url($assignmentid) : null,
                    ['requests', 'assignments_due_dates']
                ),
                'outputlang' => $lang,
                'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, [
                    'Request type: ' . $type . ', decision: ' . $decision,
                    'New due date: ' . ($newduedate > 0 ? (string)$newduedate : '-'),
                ]),
                'preview' => [
                    'type' => taskflow_preview_renderer_factory::TYPE_REQUEST_LIST,
                    'data' => ['requests' => [$row], 'total' => 1, 'filters' => [$effect]],
                    'payload' => [
                        'requestids' => [$requestid],
                        'assignmentids' => $assignmentid > 0 ? [$assignmentid] : [],
                        'userids' => [(int)$request->userid],
                    ],
                ],
            ],
            $lang
        );
    }

    /**
     * Whether the acting user may treat this request (receiver relationship or viewallrequests).
     *
     * @param stdClass $request
     * @param int $userid
     * @return bool
     */
    private function may_treat(stdClass $request, int $userid): bool {
        if ($userid <= 0) {
            return false;
        }
        if (has_capability(self::CAPABILITY_ALL, context_system::instance(), $userid)) {
            return true;
        }
        $receiver = (int)($request->forhr ?? 0);
        if ($receiver === $this->receiver_id_for(hr_receiver::class)) {
            return $this->permissions()->is_hr_user($userid);
        }
        if ($receiver === $this->receiver_id_for(supervisor_receiver::class)) {
            return $this->permissions()->is_supervisor_of((int)$request->userid, $userid);
        }
        return false;
    }

    /**
     * Request type id of a row (the request column, falling back to the legacy status column).
     *
     * @param stdClass $request
     * @return int
     */
    private function request_type_id(stdClass $request): int {
        $type = (int)($request->request ?? 0);
        return $type > 0 ? $type : (int)($request->status ?? 0);
    }

    /**
     * Localized title of a request type.
     *
     * @param int $type
     * @param string $lang
     * @return string
     */
    private function type_label(int $type, string $lang): string {
        try {
            $keys = (new requests_manager())->get_request_types_with_ids();
        } catch (\Throwable $e) {
            $keys = [];
        }
        $key = (string)($keys[$type] ?? '');
        return $key === '' ? requests::resolve_status($type) : $this->localized_string($key . '_title', null, $lang);
    }

    /**
     * Localized label of a decision value.
     *
     * @param string $decision
     * @param string $lang
     * @return string
     */
    private function decision_label(string $decision, string $lang): string {
        return $decision === self::DECISION_CONFIRM
            ? $this->localized_string('confirmed', null, $lang)
            : $this->localized_string('declined', null, $lang);
    }

    /**
     * Plain text of the effect the decision has on the assignment.
     *
     * @param int $type
     * @param string $decision
     * @param int $assignmentid
     * @param int $newduedate
     * @param string $lang
     * @return string
     */
    private function effect_text(int $type, string $decision, int $assignmentid, int $newduedate, string $lang): string {
        if ($decision !== self::DECISION_CONFIRM) {
            if ($type === allowuploadevidence::ID) {
                return $this->localized_string('agent_treat_request_effect_evidence_rejected', null, $lang);
            }
            return $this->localized_string('agent_treat_request_effect_none', null, $lang);
        }
        if ($type === allowselfnotrelevant::ID) {
            return $this->localized_string('agent_treat_request_effect_notrelevant', null, $lang);
        }
        if ($type === allowuploadevidence::ID) {
            return $this->localized_string('agent_treat_request_effect_evidence_approved', null, $lang);
        }
        if ($type === allowselfextension::ID && $newduedate > 0) {
            return $this->localized_string('agent_treat_request_effect_duedate', (object)[
                'assignmentid' => $assignmentid,
                'duedate' => userdate($newduedate, get_string('strftimedate', 'langconfig')),
            ], $lang);
        }
        return $this->localized_string('agent_treat_request_effect_none', null, $lang);
    }

    /**
     * Who treated the request and when, read from the assignment history.
     *
     * @param stdClass $request
     * @return array{fullname:string,time:string}
     */
    private function treatment_record(stdClass $request): array {
        global $DB;

        $types = [history::TYPE_REQUEST_CONFIRMED, history::TYPE_REQUEST_DECLINED];
        [$insql, $params] = $DB->get_in_or_equal($types, SQL_PARAMS_NAMED, 'ht');
        $params['assignmentid'] = (int)$request->assignmentid;
        $records = $DB->get_records_select(
            'local_taskflow_history',
            "assignmentid = :assignmentid AND type {$insql}",
            $params,
            'timecreated DESC, id DESC'
        );
        foreach ($records as $record) {
            $data = json_decode((string)$record->data, true);
            if ((int)($data['data']['requestid'] ?? 0) !== (int)$request->id) {
                continue;
            }
            return [
                'fullname' => $this->fullname_of((int)$record->createdby),
                'time' => $this->format_time((int)$record->timecreated),
            ];
        }
        return [
            'fullname' => $this->fullname_of((int)($request->usermodified ?? 0)),
            'time' => $this->format_time((int)($request->timemodified ?? 0)),
        ];
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
     * Localized description of a receiver id, '' when unknown.
     *
     * @param int $receiver
     * @param string $lang
     * @return string
     */
    private function receiver_label(int $receiver, string $lang): string {
        try {
            foreach (receiver_facade::get_request_receivers() as $id => $instance) {
                if ((int)$id === $receiver) {
                    return $this->localized_string((string)$instance::SETTINGKEY, null, $lang);
                }
            }
        } catch (\Throwable $e) {
            return '';
        }
        return '';
    }

    /**
     * Id declared by one receiver class (-1 when the class is unavailable).
     *
     * @param string $class
     * @return int
     */
    private function receiver_id_for(string $class): int {
        try {
            foreach (receiver_facade::get_request_receivers() as $id => $instance) {
                if ($instance instanceof $class) {
                    return (int)$id;
                }
            }
        } catch (\Throwable $e) {
            return -1;
        }
        return -1;
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

    /**
     * Timezone aware date text ('' when unset).
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
