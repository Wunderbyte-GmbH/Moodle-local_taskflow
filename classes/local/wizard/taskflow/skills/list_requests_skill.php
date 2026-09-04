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
use local_taskflow\local\wizard\engine\observation_time;
use local_taskflow\local\wizard\engine\skill_risk_class;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\taskflow_input_normalizer;
use local_taskflow\local\wizard\taskflow\taskflow_permission_resolver;
use local_taskflow\local\wizard\taskflow\taskflow_result_link_builder;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;

/**
 * Read-only skill local_taskflow.list_requests (implementation plan §2 #11).
 *
 * Lists self-service requests (not-relevant, prolongation, evidence). Visibility is derived
 * from capabilities and engine state only, never from wording:
 * - own requests require local/taskflow:viewrequests;
 * - requests addressed to the acting user require local/taskflow:treatrequests and either
 *   supervisor/deputy relationship to the requesting user (forhr = supervisor receiver) or
 *   membership in the hrusers setting (forhr = HR receiver);
 * - everything requires local/taskflow:viewallrequests together with all = true (the same
 *   two-part gate the requests dashboard uses); all = true without the capability is a
 *   hard block.
 * Request type ids and receiver ids are taken from requests_manager / receiver_facade,
 * treated states from the requests::TREATED_STATUS_* constants; no id is hard-coded.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class list_requests_skill extends taskflow_skill_base {
    /** Skill name. */
    public const TASK_NAME = 'local_taskflow.list_requests';

    /** Capability to see one's own requests. */
    public const CAP_VIEWREQUESTS = 'local/taskflow:viewrequests';

    /** Capability to see and treat requests addressed to the acting user. */
    public const CAP_TREATREQUESTS = 'local/taskflow:treatrequests';

    /** Capability that, together with all = true, opens the full list. */
    public const CAP_VIEWALLREQUESTS = 'local/taskflow:viewallrequests';

    /** Default number of rows. */
    public const DEFAULT_LIMIT = 25;

    /** Upper bound of rows. */
    public const MAX_LIMIT = 100;

    /** Issue code: all = true without local/taskflow:viewallrequests. */
    public const ISSUE_ALL_DENIED = 'TASKFLOW_REQUESTS_ALL_DENIED';

    /** Issue code: unknown request type id. */
    public const ISSUE_TYPE_UNKNOWN = 'TASKFLOW_REQUEST_TYPE_UNKNOWN';

    /** Issue code: unknown treated state. */
    public const ISSUE_TREATED_UNKNOWN = 'TASKFLOW_REQUEST_TREATED_UNKNOWN';

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
            'description' => 'List taskflow requests (not-relevant, due date extension, evidence upload) visible to the '
                . 'acting user: own requests, requests addressed to them as supervisor, deputy or HR, or - with '
                . 'local/taskflow:viewallrequests and all = true - every request of the site.',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'Which requests are waiting for my decision?',
                'Show my open requests',
                'List all extension requests of the last weeks',
                'Which requests of Anna Muster were declined?',
                'Show every request on the site',
            ],
            'properties' => [
                'userid' => [
                    'type' => 'integer',
                    'description' => 'Restrict to requests raised by this user id.',
                    'required' => false,
                ],
                'userquery' => [
                    'type' => 'string',
                    'description' => 'Restrict to requests of the user matching this id, e-mail, username or name.',
                    'required' => false,
                ],
                'type' => [
                    'type' => 'integer',
                    'description' => 'Request type id: 1 = not-relevant status, 2 = due date extension, '
                        . '3 = evidence upload.',
                    'required' => false,
                ],
                'treated' => [
                    'type' => 'integer',
                    'description' => 'Treated state: 0 = open, 1 = declined, 2 = confirmed.',
                    'required' => false,
                ],
                'all' => [
                    'type' => 'boolean',
                    'description' => 'Show every request of the site. Requires local/taskflow:viewallrequests.',
                    'required' => false,
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of rows (default ' . self::DEFAULT_LIMIT . ', max '
                        . self::MAX_LIMIT . ').',
                    'required' => false,
                ],
            ],
        ];
    }

    /**
     * Prompt metadata.
     *
     * @return array<string,mixed>
     */
    protected function prompt_meta(): array {
        return [
            'intent' => 'List taskflow requests of one person, of the acting user, or of the whole site.',
            'input_fields_for_prompt' => ['userquery (or userid), type, treated, all'],
            'anchor_fields' => ['userquery', 'userid'],
        ];
    }

    /**
     * Example input for the planner contract.
     *
     * @return array
     */
    public function get_example_input(): array {
        return ['treated' => requests::TREATED_STATUS_UNTREATED, 'limit' => 25];
    }

    /**
     * Preflight: resolve the target user, the visibility scope and the filter values.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array{status:string,prepared_input:array,issues:array}
     */
    protected function run_preflight(array $input, int $contextid, int $userid): array {
        $lang = $this->get_output_language($input);
        $prepared = $input;

        $all = taskflow_input_normalizer::to_bool($input['all'] ?? null) ?? false;
        if ($all && !$this->can_view_all($userid)) {
            return $this->invalid([[
                'code' => self::ISSUE_ALL_DENIED,
                'severity' => 'needs_clarification',
                'field' => 'all',
                'message' => $this->localized_string('agent_requests_all_denied', null, $lang),
            ]]);
        }
        $prepared['all'] = $all;

        // Target user (optional).
        $targetuserid = 0;
        $hasuserfilter = !empty(taskflow_input_normalizer::to_int($input['userid'] ?? null))
            || trim((string)($input['userquery'] ?? '')) !== '';
        if ($hasuserfilter) {
            $targetuserid = $this->resolve_userid($input, $userid);
            if ($targetuserid <= 0) {
                $query = trim((string)($input['userquery'] ?? ''));
                $candidates = $query === '' ? [] : $this->search_user_candidates($query, 2);
                $code = count($candidates) > 1 ? self::ISSUE_USER_AMBIGUOUS : self::ISSUE_USER_NOT_FOUND;
                $key = count($candidates) > 1 ? 'agent_user_ambiguous' : 'agent_user_notfound';
                return $this->invalid([
                    $this->not_found_issue($code, $this->localized_string($key, $query, $lang), ['field' => 'userquery']),
                ]);
            }
            if (!$all && !$this->may_see_requests_of($targetuserid, $userid)) {
                return $this->invalid([$this->scope_denied_issue($lang, ['field' => 'userid'])]);
            }
            $prepared['userid'] = $targetuserid;
            unset($prepared['userquery']);
        }

        $issues = [];
        $type = taskflow_input_normalizer::to_int($input['type'] ?? null);
        if ($type !== null) {
            if (!array_key_exists($type, $this->request_types())) {
                $issues[] = [
                    'code' => self::ISSUE_TYPE_UNKNOWN,
                    'severity' => 'needs_clarification',
                    'field' => 'type',
                    'message' => $this->localized_string('agent_request_type_unknown', (object)[
                        'value' => (string)$type,
                        'known' => implode(', ', array_keys($this->request_types())),
                    ], $lang),
                ];
            } else {
                $prepared['type'] = $type;
            }
        } else {
            unset($prepared['type']);
        }

        $treated = taskflow_input_normalizer::to_int($input['treated'] ?? null);
        if ($treated !== null) {
            if (!array_key_exists($treated, $this->treated_states())) {
                $issues[] = [
                    'code' => self::ISSUE_TREATED_UNKNOWN,
                    'severity' => 'needs_clarification',
                    'field' => 'treated',
                    'message' => $this->localized_string('agent_request_treated_unknown', (object)[
                        'value' => (string)$treated,
                        'known' => implode(', ', array_keys($this->treated_states())),
                    ], $lang),
                ];
            } else {
                $prepared['treated'] = $treated;
            }
        } else {
            unset($prepared['treated']);
        }

        if (!empty($issues)) {
            return $this->invalid($issues);
        }

        $limit = taskflow_input_normalizer::to_int($input['limit'] ?? null) ?? self::DEFAULT_LIMIT;
        $prepared['limit'] = max(1, min(self::MAX_LIMIT, $limit));

        return $this->pass($prepared);
    }

    /**
     * Execute the request search.
     *
     * @param array $input Prepared input.
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        global $DB;

        $lang = $this->get_output_language($input);
        $debug = $this->build_task_debug_message(self::TASK_NAME, $input);

        $all = taskflow_input_normalizer::to_bool($input['all'] ?? null) ?? false;
        if ($all && !$this->can_view_all($userid)) {
            return $this->error_result(
                self::ISSUE_ALL_DENIED,
                $this->localized_string('agent_requests_all_denied', null, $lang),
                ['debugmessage' => $debug]
            );
        }

        $targetuserid = taskflow_input_normalizer::to_int($input['userid'] ?? null) ?? 0;
        if (!$all && $targetuserid > 0 && !$this->may_see_requests_of($targetuserid, $userid)) {
            return $this->error_result(
                self::ISSUE_SCOPE_DENIED,
                $this->localized_string('agent_scope_denied', null, $lang),
                ['debugmessage' => $debug]
            );
        }

        [$where, $params, $scope] = $this->visibility_sql($all, $userid);
        if ($targetuserid > 0) {
            $where .= ' AND r.userid = :filteruserid';
            $params['filteruserid'] = $targetuserid;
        }
        $type = taskflow_input_normalizer::to_int($input['type'] ?? null);
        if ($type !== null && array_key_exists($type, $this->request_types())) {
            $where .= ' AND (r.request = :typea OR (r.request = 0 AND r.status = :typeb))';
            $params['typea'] = $type;
            $params['typeb'] = $type;
        }
        $treated = taskflow_input_normalizer::to_int($input['treated'] ?? null);
        if ($treated !== null && array_key_exists($treated, $this->treated_states())) {
            $where .= ' AND r.treated = :treated';
            $params['treated'] = $treated;
        }
        $limit = max(1, min(self::MAX_LIMIT, taskflow_input_normalizer::to_int($input['limit'] ?? null) ?? self::DEFAULT_LIMIT));

        $from = "FROM {local_taskflow_requests} r
                 JOIN {user} u ON u.id = r.userid
            LEFT JOIN {local_taskflow_assignment} a ON a.id = r.assignmentid
            LEFT JOIN {local_taskflow_rules} rl ON rl.id = a.ruleid
                WHERE {$where}";

        $total = (int)$DB->count_records_sql("SELECT COUNT(r.id) {$from}", $params);
        $userfields = \core_user\fields::for_name()->get_sql('u')->selects;
        $records = $DB->get_records_sql(
            "SELECT r.id, r.request, r.status, r.userid, r.assignmentid, r.treated, r.forhr, r.timecreated,
                    r.comment AS requestcomment, rl.rulename, rl.rulejson {$userfields}
             {$from}
             ORDER BY r.timecreated DESC, r.id DESC",
            $params,
            0,
            $limit
        );

        $types = $this->request_types();
        $receivers = $this->receiver_labels($lang);
        $rows = [];
        $userids = [];
        $assignmentids = [];
        foreach ($records as $record) {
            $requesttype = (int)$record->request > 0 ? (int)$record->request : (int)$record->status;
            $treatedstate = (int)$record->treated;
            $assignmentid = (int)$record->assignmentid;
            $rulename = trim((string)($record->rulename ?? ''));
            if ($rulename === '' && !empty($record->rulejson)) {
                $decoded = json_decode((string)$record->rulejson, true);
                $rulename = (string)($decoded['rulejson']['rule']['name'] ?? '');
            }
            $receiverid = (int)($record->forhr ?? 0);
            $rows[] = [
                'id' => (int)$record->id,
                'type' => $requesttype,
                'typelabel' => $this->type_label($requesttype, $types, $lang),
                'treated' => $treatedstate,
                'treatedlabel' => $this->treated_label($treatedstate, $lang),
                'userid' => (int)$record->userid,
                'fullname' => fullname($record),
                'assignmentid' => $assignmentid,
                'rulename' => $rulename,
                'receiver' => $receiverid,
                'receiverlabel' => $receivers[$receiverid] ?? '',
                'comment' => (string)($record->requestcomment ?? ''),
                'timecreated' => (int)$record->timecreated,
                'timecreated_text' => $this->format_time((int)$record->timecreated),
                'url' => $assignmentid > 0 ? taskflow_result_link_builder::assignment_url($assignmentid) : '',
            ];
            $userids[] = (int)$record->userid;
            if ($assignmentid > 0) {
                $assignmentids[] = $assignmentid;
            }
        }

        $filters = $this->describe_filters($input, $targetuserid, $type, $treated, $types, $lang);
        $scopelabel = $this->localized_string('agent_scope_' . $scope, null, $lang);
        $usermessage = $this->localized_string('agent_list_requests_summary', (object)[
            'shown' => count($rows),
            'total' => $total,
            'scope' => $scopelabel,
        ], $lang);

        $observation = [$usermessage];
        if (!empty($filters)) {
            $observation[] = $this->localized_string('agent_preview_filters', null, $lang) . ': ' . implode('; ', $filters);
        }
        foreach ($rows as $row) {
            $observation[] = sprintf(
                '#%d %s | %s | %s: %s | %s | %s: #%d %s%s',
                $row['id'],
                $row['fullname'],
                $row['typelabel'],
                $this->localized_string('status', null, $lang),
                $row['treatedlabel'],
                $row['timecreated_text'],
                $this->localized_string('assignment', null, $lang),
                $row['assignmentid'],
                $row['rulename'],
                $row['comment'] !== '' ? ' | ' . $this->localized_string('comment', null, $lang) . ': '
                    . $row['comment'] : ''
            );
        }

        return $this->base_result(self::STATUS_EXECUTED, [
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'observation_full' => implode("\n", $observation),
            'resultid' => $rows[0]['id'] ?? 0,
            'requests' => $rows,
            'scope' => $scope,
            'total' => $total,
            'shown' => count($rows),
            'filters' => $filters,
            'links' => $this->links(taskflow_result_link_builder::dashboard_url(), ['requests', 'dashboard']),
            'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, [
                'Scope: ' . $scope,
                'Total: ' . $total . ', shown: ' . count($rows),
            ]),
            'preview' => [
                'type' => taskflow_preview_renderer_factory::TYPE_REQUEST_LIST,
                'data' => [
                    'requests' => $rows,
                    'scope' => $scope,
                    'total' => $total,
                    'filters' => $filters,
                ],
                'payload' => [
                    'requestids' => array_column($rows, 'id'),
                    'assignmentids' => array_values(array_unique($assignmentids)),
                    'userids' => array_values(array_unique($userids)),
                ],
            ],
        ]);
    }

    /**
     * Whether the acting user may open the full list (capability part of the two-part gate).
     *
     * @param int $userid
     * @return bool
     */
    private function can_view_all(int $userid): bool {
        return $userid > 0 && has_capability(self::CAP_VIEWALLREQUESTS, context_system::instance(), $userid);
    }

    /**
     * Whether the acting user may see the requests raised by one person.
     *
     * @param int $targetuserid
     * @param int $userid Acting user.
     * @return bool
     */
    private function may_see_requests_of(int $targetuserid, int $userid): bool {
        if ($this->can_view_all($userid)) {
            return true;
        }
        $context = context_system::instance();
        if ($targetuserid === $userid && has_capability(self::CAP_VIEWREQUESTS, $context, $userid)) {
            return true;
        }
        if (!has_capability(self::CAP_TREATREQUESTS, $context, $userid)) {
            return false;
        }
        if ($this->permissions()->is_hr_user($userid)) {
            return true;
        }
        return $this->permissions()->is_supervisor_of($targetuserid, $userid);
    }

    /**
     * WHERE clause, parameters and scope name for the visibility of the acting user.
     *
     * @param bool $all
     * @param int $userid
     * @return array{0:string,1:array,2:string}
     */
    private function visibility_sql(bool $all, int $userid): array {
        global $DB;

        if ($all && $this->can_view_all($userid)) {
            return ['1 = 1', [], taskflow_permission_resolver::SCOPE_ADMIN];
        }

        $context = context_system::instance();
        $clauses = [];
        $params = [];
        $receiverside = false;

        if (has_capability(self::CAP_VIEWREQUESTS, $context, $userid)) {
            $clauses[] = 'r.userid = :selfid';
            $params['selfid'] = $userid;
        }

        if (has_capability(self::CAP_TREATREQUESTS, $context, $userid)) {
            $visible = (array)($this->permissions()->visible_userids($userid) ?? []);
            $subordinates = array_values(array_filter($visible, static fn(int $id): bool => $id !== $userid));
            if (!empty($subordinates)) {
                [$insql, $inparams] = $DB->get_in_or_equal($subordinates, SQL_PARAMS_NAMED, 'sub');
                $clauses[] = "(r.forhr = :supervisorreceiver AND r.userid {$insql})";
                $params['supervisorreceiver'] = $this->supervisor_receiver_id();
                $params = array_merge($params, $inparams);
                $receiverside = true;
            }
            if ($this->permissions()->is_hr_user($userid)) {
                $clauses[] = 'r.forhr = :hrreceiver';
                $params['hrreceiver'] = $this->hr_receiver_id();
                $receiverside = true;
            }
        }

        if (empty($clauses)) {
            return ['1 = 0', [], taskflow_permission_resolver::SCOPE_SELF];
        }

        $scope = $receiverside ? taskflow_permission_resolver::SCOPE_SUPERVISOR : taskflow_permission_resolver::SCOPE_SELF;
        return ['(' . implode(' OR ', $clauses) . ')', $params, $scope];
    }

    /**
     * Request type id => type key, from the request type manager.
     *
     * @return array<int,string>
     */
    private function request_types(): array {
        try {
            return array_map('strval', (new requests_manager())->get_request_types_with_ids());
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Treated state id => lang key, from the requests constants.
     *
     * @return array<int,string>
     */
    private function treated_states(): array {
        return [
            requests::TREATED_STATUS_UNTREATED => 'open',
            requests::TREATED_STATUS_DECLINED => 'declined',
            requests::TREATED_STATUS_CONFIRMED => 'confirmed',
        ];
    }

    /**
     * Localized request type title.
     *
     * @param int $type
     * @param array<int,string> $types
     * @param string $lang
     * @return string
     */
    private function type_label(int $type, array $types, string $lang): string {
        $key = $types[$type] ?? '';
        if ($key === '') {
            return requests::resolve_status($type);
        }
        return $this->localized_string($key . '_title', null, $lang);
    }

    /**
     * Localized treated state label.
     *
     * @param int $treated
     * @param string $lang
     * @return string
     */
    private function treated_label(int $treated, string $lang): string {
        $key = $this->treated_states()[$treated] ?? '';
        return $key === '' ? requests::resolve_treated($treated) : $this->localized_string($key, null, $lang);
    }

    /**
     * Receiver id => localized description, from the receiver facade.
     *
     * @param string $lang
     * @return array<int,string>
     */
    private function receiver_labels(string $lang): array {
        $labels = [];
        try {
            foreach (receiver_facade::get_request_receivers() as $id => $receiver) {
                $labels[(int)$id] = $this->localized_string((string)$receiver::SETTINGKEY, null, $lang);
            }
        } catch (\Throwable $e) {
            return [];
        }
        return $labels;
    }

    /**
     * Receiver id of the supervisor receiver (engine state, never hard-coded).
     *
     * @return int
     */
    private function supervisor_receiver_id(): int {
        return $this->receiver_id_for(\local_taskflow\local\requests\request_receivers\receivers\supervisor_receiver::class);
    }

    /**
     * Receiver id of the HR receiver (engine state, never hard-coded).
     *
     * @return int
     */
    private function hr_receiver_id(): int {
        return $this->receiver_id_for(\local_taskflow\local\requests\request_receivers\receivers\hr_receiver::class);
    }

    /**
     * Id declared by one receiver class.
     *
     * @param string $class
     * @return int
     */
    private function receiver_id_for(string $class): int {
        try {
            foreach (receiver_facade::get_request_receivers() as $id => $receiver) {
                if ($receiver instanceof $class) {
                    return (int)$id;
                }
            }
        } catch (\Throwable $e) {
            return 0;
        }
        return 0;
    }

    /**
     * Human readable list of the active filters.
     *
     * @param array $input
     * @param int $targetuserid
     * @param int|null $type
     * @param int|null $treated
     * @param array<int,string> $types
     * @param string $lang
     * @return string[]
     */
    private function describe_filters(
        array $input,
        int $targetuserid,
        ?int $type,
        ?int $treated,
        array $types,
        string $lang
    ): array {
        $filters = [];
        if ($targetuserid > 0) {
            $user = \core_user::get_user($targetuserid, '*', IGNORE_MISSING);
            $filters[] = $this->localized_string('requestinguser', null, $lang) . ': '
                . ($user ? fullname($user) : (string)$targetuserid);
        }
        if ($type !== null && array_key_exists($type, $types)) {
            $filters[] = $this->type_label($type, $types, $lang);
        }
        if ($treated !== null && array_key_exists($treated, $this->treated_states())) {
            $filters[] = $this->localized_string('status', null, $lang) . ': ' . $this->treated_label($treated, $lang);
        }
        if (taskflow_input_normalizer::to_bool($input['all'] ?? null) ?? false) {
            $filters[] = $this->localized_string('agent_filter_allrequests', null, $lang);
        }
        return $filters;
    }

    /**
     * Timezone-adjusted date text for observations ('' when unset).
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
