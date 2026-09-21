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
use local_taskflow\local\requests\request_receivers\receiver_facade;
use local_taskflow\local\requests\request_types\requests_manager;
use local_taskflow\local\requests\request_types\types\allowselfextension;
use local_taskflow\local\requests\request_types\types\allowselfnotrelevant;
use local_taskflow\local\wizard\engine\queue_identity_provider_interface;
use local_taskflow\local\wizard\engine\skill_risk_class;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\taskflow_input_normalizer;
use local_taskflow\local\wizard\taskflow\taskflow_result_link_builder;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;

/**
 * Mutating skill local_taskflow.create_request (implementation plan §2 #27).
 *
 * Self service: the acting user asks the receiver configured in the rule (supervisor or HR) to
 * set one of their OWN assignments to "not relevant" or to extend its due date. The row is
 * written by local_taskflow\local\requests::create(), the same call the dynamic forms
 * notrelevantforme / requestprolongation use; nothing is duplicated here.
 *
 * Every gate derives from engine state, never from wording:
 * - the assignment must belong to the acting user (self scope);
 * - the request type must be enabled by the global setting (allowselfnotrelevant /
 *   allowselfextension) AND by the rule (actions[0].requests.receiver_<type> != not_allowed);
 * - an untreated request of the same type for the same assignment blocks a duplicate.
 * requests::create() returns 0 when local/taskflow:createrequests is missing; that is reported
 * as TASKFLOW_REQUEST_NOT_CREATED and never as a success.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_request_skill extends taskflow_skill_base implements queue_identity_provider_interface {
    /** Skill name. */
    public const TASK_NAME = 'local_taskflow.create_request';

    /** Capability required to create a request. */
    public const CAPABILITY = 'local/taskflow:createrequests';

    /** Rule receiver value that switches a request type off for a rule. */
    public const RECEIVER_NOT_ALLOWED = 'not_allowed';

    /** Issue code: the request type id is unknown or not a self service type. */
    public const ISSUE_TYPE_UNKNOWN = 'TASKFLOW_REQUEST_TYPE_UNKNOWN';

    /** Issue code: the request type is switched off in the plugin settings. */
    public const ISSUE_TYPE_DISABLED = 'TASKFLOW_REQUEST_TYPE_DISABLED';

    /** Issue code: the rule of the assignment does not allow this request type. */
    public const ISSUE_RULE_DISALLOWS = 'TASKFLOW_REQUEST_RULE_DISALLOWS';

    /** Issue code: an untreated request of the same type already exists. */
    public const ISSUE_DUPLICATE = 'TASKFLOW_REQUEST_DUPLICATE';

    /** Issue code: requests::create() returned 0 (missing capability). */
    public const ISSUE_NOT_CREATED = 'TASKFLOW_REQUEST_NOT_CREATED';

    /** Issue code: the mutation needs an explicit confirmation. */
    public const ISSUE_CONFIRM = 'TASKFLOW_CREATE_REQUEST_CONFIRM_REQUIRED';

    /**
     * Constructor: mutating, R1, hard capability gate on local/taskflow:createrequests.
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
            'description' => 'Create a self service request for one of YOUR OWN taskflow assignments: either ask '
                . 'for the assignment to be marked as not relevant (type 1) or ask for an extension of its due '
                . 'date (type 2). The request goes to the receiver configured in the rule (supervisor or HR) and '
                . 'leaves the assignment unchanged until it is decided.',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'This training is not relevant for me, please ask my supervisor',
                'Request an extension for assignment 4711',
                'I need more time for the data protection course',
                'Ask for the due date of my assignment to be extended',
            ],
            'properties' => [
                'assignmentid' => [
                    'type' => 'integer',
                    'description' => 'Id of the own assignment the request belongs to.',
                    'required' => true,
                ],
                'type' => [
                    'type' => 'integer',
                    'description' => 'Request type id: ' . allowselfnotrelevant::ID . ' = not relevant, '
                        . allowselfextension::ID . ' = due date extension.',
                    'required' => true,
                ],
                'comment' => [
                    'type' => 'string',
                    'description' => 'Optional reason shown to the receiver of the request.',
                    'required' => false,
                ],
            ],
            'required' => ['assignmentid', 'type'],
        ];
    }

    /**
     * Prompt metadata.
     *
     * @return array<string,mixed>
     */
    protected function prompt_meta(): array {
        return [
            'intent' => 'Create a not-relevant or extension request for an own taskflow assignment.',
        ];
    }

    /**
     * Example input.
     *
     * @return array
     */
    public function get_example_input(): array {
        return ['assignmentid' => 4711, 'type' => allowselfextension::ID, 'comment' => 'I am on a project until May.'];
    }

    /**
     * Queue business identity: one open request per assignment and type.
     *
     * @param array $input
     * @return array<string,mixed>
     */
    public function build_queue_business_identity(array $input): array {
        return [
            'task_family' => self::TASK_NAME,
            'target' => [
                'assignmentid' => (int)(taskflow_input_normalizer::to_int($input['assignmentid'] ?? null) ?? 0),
                'type' => (int)(taskflow_input_normalizer::to_int($input['type'] ?? null) ?? 0),
            ],
        ];
    }

    /**
     * Tier-3 confirmation preview: assignment, rule, type, receiver, comment.
     *
     * @param array $input Prepared input.
     * @return array|null
     */
    public function describe_proposed_action(array $input): ?array {
        $lang = $this->get_output_language($input);
        $assignmentid = (int)(taskflow_input_normalizer::to_int($input['assignmentid'] ?? null) ?? 0);
        if ($assignmentid <= 0) {
            return null;
        }
        $typelabel = (string)($input['typelabel'] ?? '');
        $receiver = (string)($input['receiverlabel'] ?? '');
        $rulename = (string)($input['rulename'] ?? '');
        $comment = trim((string)($input['comment'] ?? ''));

        $rows = [
            ['label' => $this->localized_string('assignment', null, $lang), 'value' => '#' . $assignmentid],
            ['label' => $this->localized_string('type', null, $lang), 'value' => $typelabel],
            ['label' => $this->localized_string('agent_preview_receiver', null, $lang), 'value' => $receiver],
        ];
        if ($rulename !== '') {
            array_splice($rows, 1, 0, [[
                'label' => $this->localized_string('agent_preview_rules', null, $lang),
                'value' => $rulename,
            ]]);
        }
        if ($comment !== '') {
            $rows[] = ['label' => $this->localized_string('comment', null, $lang), 'value' => $comment];
        }

        return [
            'title' => $this->localized_string('agent_create_request_title', (object)[
                'type' => $typelabel,
                'assignmentid' => $assignmentid,
            ], $lang),
            'summary' => $this->localized_string('agent_create_request_summary_proposed', (object)[
                'type' => $typelabel,
                'receiver' => $receiver,
            ], $lang),
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

        $assignmentid = taskflow_input_normalizer::to_int($input['assignmentid'] ?? null);
        if ($assignmentid === null || $assignmentid <= 0) {
            $errors[] = $this->localized_string('agent_assignmentid_required', null, $lang);
        }
        $type = taskflow_input_normalizer::to_int($input['type'] ?? null);
        if ($type === null || $type <= 0) {
            $errors[] = $this->localized_string(
                'agent_request_type_required',
                implode(', ', array_keys($this->self_service_types())),
                $lang
            );
        }

        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => []];
    }

    /**
     * Preflight: self scope, type enabled globally and by the rule, no duplicate.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array{status:string,prepared_input:array,issues:array}
     */
    protected function run_preflight(array $input, int $contextid, int $userid): array {
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
            return $this->invalid([$this->scope_denied_issue($lang, ['field' => 'assignmentid'])]);
        }

        $assignmentid = (int)taskflow_input_normalizer::to_int($input['assignmentid']);
        $type = (int)taskflow_input_normalizer::to_int($input['type']);

        $types = $this->self_service_types();
        if (!isset($types[$type])) {
            return $this->invalid([[
                'code' => self::ISSUE_TYPE_UNKNOWN,
                'severity' => 'needs_clarification',
                'field' => 'type',
                'message' => $this->localized_string('agent_request_type_unknown', (object)[
                    'value' => (string)$type,
                    'known' => implode(', ', array_keys($types)),
                ], $lang),
            ]]);
        }
        $typekey = $types[$type];

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
        if ((int)($assignment->userid ?? 0) !== $userid) {
            return $this->invalid([[
                'code' => self::ISSUE_SCOPE_DENIED,
                'severity' => 'needs_clarification',
                'field' => 'assignmentid',
                'message' => $this->localized_string('agent_create_request_self_only', null, $lang),
            ]]);
        }

        if (!$this->type_enabled_globally($typekey)) {
            return $this->invalid([[
                'code' => self::ISSUE_TYPE_DISABLED,
                'severity' => 'needs_clarification',
                'field' => 'type',
                'message' => $this->localized_string(
                    'agent_create_request_type_disabled',
                    $this->type_label($type, $typekey, $lang),
                    $lang
                ),
            ]]);
        }

        $rule = $this->resolve_rule((int)($assignment->ruleid ?? 0));
        $receiver = $this->rule_receiver($rule, $typekey);
        if ($receiver === null) {
            return $this->invalid([[
                'code' => self::ISSUE_RULE_DISALLOWS,
                'severity' => 'needs_clarification',
                'field' => 'type',
                'message' => $this->localized_string(
                    'agent_create_request_rule_disallows',
                    $this->type_label($type, $typekey, $lang),
                    $lang
                ),
            ]]);
        }

        $duplicate = $this->find_open_request($userid, $assignmentid, $type);
        if ($duplicate > 0) {
            return $this->invalid([[
                'code' => self::ISSUE_DUPLICATE,
                'severity' => 'needs_clarification',
                'field' => 'type',
                'message' => $this->localized_string('agent_create_request_duplicate', (object)[
                    'id' => $duplicate,
                    'type' => $this->type_label($type, $typekey, $lang),
                    'assignmentid' => $assignmentid,
                ], $lang),
            ]]);
        }

        $prepared = $input;
        $prepared['assignmentid'] = $assignmentid;
        $prepared['type'] = $type;
        $prepared['comment'] = trim((string)($input['comment'] ?? ''));
        $prepared['typelabel'] = $this->type_label($type, $typekey, $lang);
        $prepared['receiver'] = $receiver;
        $prepared['receiverlabel'] = $this->receiver_label($receiver, $lang);
        $prepared['rulename'] = (string)($rule['rulename'] ?? '');

        return $this->confirmable($prepared, [[
            'code' => self::ISSUE_CONFIRM,
            'severity' => 'needs_confirmation',
            'user_question' => $this->localized_string('agent_create_request_confirm', (object)[
                'type' => $prepared['typelabel'],
                'assignmentid' => $assignmentid,
                'receiver' => $prepared['receiverlabel'],
            ], $lang),
        ]]);
    }

    /**
     * Execute: create the request through requests::create() and verify the row.
     *
     * @param array $input Prepared input.
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        global $DB;

        $lang = $this->get_output_language($input);
        $assignmentid = (int)(taskflow_input_normalizer::to_int($input['assignmentid'] ?? null) ?? 0);
        $type = (int)(taskflow_input_normalizer::to_int($input['type'] ?? null) ?? 0);
        $comment = trim((string)($input['comment'] ?? ''));
        $links = $this->links(
            $assignmentid > 0 ? taskflow_result_link_builder::assignment_url($assignmentid) : null,
            ['requests', 'assignments_due_dates']
        );

        if (!has_capability(self::CAPABILITY, context_system::instance(), $userid)) {
            return $this->error_result(
                self::ISSUE_SCOPE_DENIED,
                $this->localized_string('agent_scope_denied', null, $lang),
                ['links' => $links]
            );
        }

        $types = $this->self_service_types();
        if (!isset($types[$type])) {
            return $this->error_result(
                self::ISSUE_TYPE_UNKNOWN,
                $this->localized_string('agent_request_type_unknown', (object)[
                    'value' => (string)$type,
                    'known' => implode(', ', array_keys($types)),
                ], $lang),
                ['links' => $links]
            );
        }
        $typekey = $types[$type];
        $typelabel = $this->type_label($type, $typekey, $lang);

        $assignment = $this->resolve_assignment(['assignmentid' => $assignmentid]);
        if ($assignment === null) {
            return $this->error_result(
                self::ISSUE_ASSIGNMENT_NOT_FOUND,
                $this->localized_string('agent_notfound_assignment', $assignmentid, $lang),
                ['links' => $links]
            );
        }
        if ((int)($assignment->userid ?? 0) !== $userid) {
            return $this->error_result(
                self::ISSUE_SCOPE_DENIED,
                $this->localized_string('agent_create_request_self_only', null, $lang),
                ['links' => $links]
            );
        }
        if (!$this->type_enabled_globally($typekey)) {
            return $this->error_result(
                self::ISSUE_TYPE_DISABLED,
                $this->localized_string('agent_create_request_type_disabled', $typelabel, $lang),
                ['links' => $links]
            );
        }

        $rule = $this->resolve_rule((int)($assignment->ruleid ?? 0));
        $receiver = $this->rule_receiver($rule, $typekey);
        if ($receiver === null) {
            return $this->error_result(
                self::ISSUE_RULE_DISALLOWS,
                $this->localized_string('agent_create_request_rule_disallows', $typelabel, $lang),
                ['links' => $links]
            );
        }
        $duplicate = $this->find_open_request($userid, $assignmentid, $type);
        if ($duplicate > 0) {
            return $this->error_result(
                self::ISSUE_DUPLICATE,
                $this->localized_string('agent_create_request_duplicate', (object)[
                    'id' => $duplicate,
                    'type' => $typelabel,
                    'assignmentid' => $assignmentid,
                ], $lang),
                ['links' => $links, 'resultid' => $duplicate]
            );
        }

        $requestid = (int)requests::create($type, $userid, $assignmentid, $type, $userid, $comment);
        if ($requestid <= 0) {
            return $this->error_result(
                self::ISSUE_NOT_CREATED,
                $this->localized_string('agent_request_not_created', null, $lang),
                ['links' => $links]
            );
        }

        $receiverlabel = $this->receiver_label($receiver, $lang);
        $usermessage = $this->localized_string('agent_create_request_summary', (object)[
            'id' => $requestid,
            'type' => $typelabel,
            'assignmentid' => $assignmentid,
            'receiver' => $receiverlabel,
        ], $lang);

        $row = [
            'id' => $requestid,
            'type' => $type,
            'typelabel' => $typelabel,
            'treated' => requests::TREATED_STATUS_UNTREATED,
            'treatedlabel' => $this->localized_string('open', null, $lang),
            'userid' => $userid,
            'fullname' => fullname(\core_user::get_user($userid, '*', IGNORE_MISSING) ?: (object)[]),
            'assignmentid' => $assignmentid,
            'rulename' => (string)($rule['rulename'] ?? ''),
            'receiver' => $receiver,
            'receiverlabel' => $receiverlabel,
            'comment' => $comment,
            'timecreated' => time(),
            'url' => taskflow_result_link_builder::assignment_url($assignmentid),
        ];

        $observation = $usermessage . "\n" . sprintf(
            '#%d %s | %s | %s: %s%s',
            $requestid,
            $row['fullname'],
            $typelabel,
            $this->localized_string('agent_preview_receiver', null, $lang),
            $receiverlabel,
            $comment === '' ? '' : ' | ' . $this->localized_string('comment', null, $lang) . ': ' . $comment
        );

        return $this->verified_result(
            [
                'id' => $requestid,
                'request' => $type,
                'assignmentid' => $assignmentid,
                'userid' => $userid,
                'treated' => requests::TREATED_STATUS_UNTREATED,
            ],
            static function () use ($DB, $requestid): ?array {
                $record = $DB->get_record('local_taskflow_requests', ['id' => $requestid], '*', IGNORE_MISSING);
                return $record ? (array)$record : null;
            },
            [
                'detail' => $usermessage,
                'usermessage' => $usermessage,
                'observation_full' => $observation,
                'resultid' => $requestid,
                'request' => $row,
                'links' => $links,
                'outputlang' => $lang,
                'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, [
                    'Created request id: ' . $requestid,
                    'Receiver: ' . $receiver,
                ]),
                'preview' => [
                    'type' => taskflow_preview_renderer_factory::TYPE_REQUEST_LIST,
                    'data' => ['requests' => [$row], 'total' => 1, 'filters' => []],
                    'payload' => [
                        'requestids' => [$requestid],
                        'assignmentids' => [$assignmentid],
                        'userids' => [$userid],
                    ],
                ],
            ],
            $lang
        );
    }

    /**
     * Self service request type ids => setting key, taken from the request type manager.
     *
     * @return array<int,string>
     */
    private function self_service_types(): array {
        $allowed = [allowselfnotrelevant::SETTINGKEY, allowselfextension::SETTINGKEY];
        $types = [];
        try {
            foreach ((new requests_manager())->get_request_types_with_ids() as $id => $key) {
                if (in_array((string)$key, $allowed, true)) {
                    $types[(int)$id] = (string)$key;
                }
            }
        } catch (\Throwable $e) {
            return [];
        }
        ksort($types);
        return $types;
    }

    /**
     * Whether the request type is switched on in the plugin settings.
     *
     * @param string $typekey Setting key of the request type.
     * @return bool
     */
    private function type_enabled_globally(string $typekey): bool {
        return (bool)get_config('local_taskflow', $typekey);
    }

    /**
     * Receiver id configured in the rule for the request type, or null when not allowed.
     *
     * @param array $rule Result of resolve_rule().
     * @param string $typekey
     * @return int|null
     */
    private function rule_receiver(array $rule, string $typekey): ?int {
        $requests = (array)(($rule['rule']['actions'][0]['requests'] ?? []));
        $raw = $requests['receiver_' . $typekey] ?? null;
        if ($raw === null || (string)$raw === self::RECEIVER_NOT_ALLOWED || !is_numeric($raw)) {
            return null;
        }
        return (int)$raw;
    }

    /**
     * Localized description of a receiver id (receiver_facade), '' when unknown.
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
     * Localized title of a request type.
     *
     * @param int $type
     * @param string $typekey
     * @param string $lang
     * @return string
     */
    private function type_label(int $type, string $typekey, string $lang): string {
        if ($typekey === '') {
            return requests::resolve_status($type);
        }
        return $this->localized_string($typekey . '_title', null, $lang);
    }

    /**
     * Id of an untreated request of the same type for the same assignment (0 = none).
     *
     * Mirrors the duplicate check of the notrelevantforme / requestprolongation forms.
     *
     * @param int $userid
     * @param int $assignmentid
     * @param int $type
     * @return int
     */
    private function find_open_request(int $userid, int $assignmentid, int $type): int {
        global $DB;

        $record = $DB->get_record('local_taskflow_requests', [
            'userid' => $userid,
            'assignmentid' => $assignmentid,
            'status' => $type,
            'treated' => requests::TREATED_STATUS_UNTREATED,
        ], 'id', IGNORE_MULTIPLE);
        return $record ? (int)$record->id : 0;
    }
}
