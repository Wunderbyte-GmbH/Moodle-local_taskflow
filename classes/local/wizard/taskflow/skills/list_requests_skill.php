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
use local_taskflow\local\supervisor\supervisor;
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
 * - requests addressed to the acting user require local/taskflow:viewrequests (the gate of the
 *   requests dashboard) or local/taskflow:treatrequests, and either the supervisor/deputy
 *   relationship to the requesting user (forhr = supervisor receiver) or membership in the
 *   hrusers setting (forhr = HR receiver); treating them is the treat_request skill's gate;
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
            'description' => 'List taskflow requests (not-relevant, due date extension, evidence upload) visible to the acting '
                . 'user. Scope: own requests, requests addressed to them as supervisor, deputy or HR, or - with '
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
                    'type' => 'array',
                    'description' => 'Request type ids to include (one or several): 1 = not-relevant status, '
                        . '2 = due date extension, 3 = evidence upload. Omit for every type.',
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
                'self' => [
                    'type' => 'boolean',
                    'description' => 'Only the acting user\'s own requests ("my", "I"). Set true instead of guessing '
                        . 'a userquery for the person who is asking.',
                    'required' => false,
                ],
                'createdafter' => [
                    'type' => 'string',
                    'description' => 'Only requests created at or after this date (ISO 8601 date or Unix timestamp), '
                        . 'e.g. the first day of last month.',
                    'required' => false,
                ],
                'createdbefore' => [
                    'type' => 'string',
                    'description' => 'Only requests created before this date (ISO 8601 date or Unix timestamp).',
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
            // Every filter is optional: a sentence here would render as one required token on the
            // constructor card and make the model ask for it (#470).
            'input_fields_for_prompt' => [],
            'anchor_fields' => ['userquery', 'userid'],
        ];
    }

    /**
     * Example input for the planner contract (the constructor only sees example VALUES, so the
     * date filter is advertised here, #470).
     *
     * @return array
     */
    public function get_example_input(): array {
        return ['treated' => requests::TREATED_STATUS_UNTREATED, 'createdafter' => '2026-01-01', 'limit' => 25];
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
        $resolved = $this->resolve_input($input, $userid);
        if (!empty($resolved['issues'])) {
            return $this->invalid($resolved['issues']);
        }
        return $this->pass($resolved['prepared']);
    }

    /**
     * Resolve the "all" gate, the target user, the scope and the enum filters (shared by
     * preflight and execute).
     *
     * The read-only chat path calls execute() with the raw command input, so a userquery
     * that resolves nobody, a foreign user outside the acting user's scope or an unknown
     * type/treated value is a hard stop here and never widens the list to every visible request.
     *
     * @param array $input Raw or prepared input.
     * @param int $userid Acting user.
     * @return array{prepared:array,issues:array}
     */
    private function resolve_input(array $input, int $userid): array {
        $input = $this->canonical_input($input);
        $lang = $this->get_output_language($input);
        $prepared = $input;

        $all = taskflow_input_normalizer::to_bool($input['all'] ?? null) ?? false;
        if ($all && !$this->can_view_all($userid)) {
            return ['prepared' => [], 'issues' => [[
                'code' => self::ISSUE_ALL_DENIED,
                'severity' => 'needs_clarification',
                'field' => 'all',
                'message' => $this->localized_string('agent_requests_all_denied', null, $lang),
            ]]];
        }
        $prepared['all'] = $all;

        // Target user (optional): unresolvable ⇒ hard stop, never "all visible".
        $hasuserfilter = !empty(taskflow_input_normalizer::to_int($input['userid'] ?? null))
            || trim((string)($input['userquery'] ?? '')) !== '';
        // The flag self = the acting user; wins over any user filter (a guessed userquery for "me" is ignored, #470).
        unset($prepared['self']);
        if (taskflow_input_normalizer::to_bool($input['self'] ?? null) ?? false) {
            if (!$all && !$this->may_see_requests_of($userid, $userid)) {
                return ['prepared' => [], 'issues' => [$this->scope_denied_issue($lang, ['field' => 'self'])]];
            }
            $prepared['userid'] = $userid;
            unset($prepared['userquery']);
        } else if ($hasuserfilter) {
            $targetuserid = $this->resolve_userid($input, $userid);
            if ($targetuserid <= 0) {
                return ['prepared' => [], 'issues' => [$this->user_lookup_issue($input, $lang)]];
            }
            if (!$all && !$this->may_see_requests_of($targetuserid, $userid)) {
                return ['prepared' => [], 'issues' => [$this->scope_denied_issue($lang, ['field' => 'userid'])]];
            }
            $prepared['userid'] = $targetuserid;
            unset($prepared['userquery']);
        } else {
            unset($prepared['userid'], $prepared['userquery']);
        }

        $issues = [];
        // One id or a list of ids; anything that is not a known id is a clarification that offers
        // the localized type titles as candidates and names no schema ids in its text (#462).
        $types = $this->request_types();
        $rawtype = $input['type'] ?? null;
        $typelist = taskflow_input_normalizer::to_list($rawtype) ?? [];
        $typeids = [];
        $unknown = false;
        foreach ($typelist as $value) {
            $id = $this->resolve_type_id($value, $types, $lang);
            if ($id === null) {
                $unknown = true;
                break;
            }
            $typeids[$id] = $id;
        }
        if ($unknown) {
            $candidates = [];
            foreach ($types as $id => $key) {
                $candidates[] = ['id' => (int)$id, 'label' => $this->type_label((int)$id, $types, $lang)];
            }
            $issues[] = [
                'code' => self::ISSUE_TYPE_UNKNOWN,
                'severity' => 'needs_clarification',
                'field' => 'type',
                'message' => $this->localized_string(
                    'agent_request_type_unknown',
                    is_scalar($rawtype) ? (string)$rawtype : json_encode($rawtype),
                    $lang
                ),
                'candidates' => $candidates,
            ];
        } else if (!empty($typeids)) {
            $prepared['type'] = array_values($typeids);
        } else {
            unset($prepared['type']);
        }

        $treated = taskflow_input_normalizer::to_int($input['treated'] ?? null);
        $hastreated = isset($input['treated']) && trim((string)(is_scalar($input['treated']) ? $input['treated'] : '')) !== '';
        if ($hastreated && ($treated === null || !array_key_exists($treated, $this->treated_states()))) {
            $issues[] = [
                'code' => self::ISSUE_TREATED_UNKNOWN,
                'severity' => 'needs_clarification',
                'field' => 'treated',
                'message' => $this->localized_string('agent_request_treated_unknown', (object)[
                    'value' => (string)(is_scalar($input['treated']) ? $input['treated'] : json_encode($input['treated'])),
                    'known' => implode(', ', array_keys($this->treated_states())),
                ], $lang),
            ];
        } else if ($hastreated) {
            $prepared['treated'] = $treated;
        } else {
            unset($prepared['treated']);
        }

        // Creation date bounds (#470): unparsable ⇒ clarification, like the due date bounds of search_assignments.
        foreach (['createdafter', 'createdbefore'] as $field) {
            if (!isset($input[$field]) || trim((string)$input[$field]) === '') {
                unset($prepared[$field]);
                continue;
            }
            $timestamp = $this->parse_timestamp((string)$input[$field]);
            if ($timestamp === null) {
                $issues[] = [
                    'code' => self::ISSUE_DATE_INVALID,
                    'severity' => 'needs_clarification',
                    'field' => $field,
                    'message' => $this->localized_string('agent_date_invalid', (string)$input[$field], $lang),
                ];
                continue;
            }
            $prepared[$field] = $timestamp;
        }

        if (!empty($issues)) {
            return ['prepared' => [], 'issues' => $issues];
        }

        $limit = taskflow_input_normalizer::to_int($input['limit'] ?? null) ?? self::DEFAULT_LIMIT;
        $prepared['limit'] = max(1, min(self::MAX_LIMIT, $limit));

        return ['prepared' => $prepared, 'issues' => []];
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

        // Gate, person, scope and enum filters recomputed: execute() must be safe without preflight.
        $resolved = $this->resolve_input($input, $userid);
        if (!empty($resolved['issues'])) {
            $first = reset($resolved['issues']);
            return $this->error_result(
                (string)($first['code'] ?? self::ISSUE_SCOPE_DENIED),
                implode(' ', array_map(static fn(array $issue): string => (string)($issue['message'] ?? ''), $resolved['issues'])),
                [
                    'issue_codes' => array_values(array_unique(array_map(
                        static fn(array $issue): string => (string)($issue['code'] ?? ''),
                        $resolved['issues']
                    ))),
                    'debugmessage' => $debug,
                ]
            );
        }
        $input = $resolved['prepared'];
        $all = (bool)$input['all'];
        $targetuserid = (int)($input['userid'] ?? 0);

        [$where, $params, $scope] = $this->visibility_sql($all, $userid);
        if ($targetuserid > 0) {
            $where .= ' AND r.userid = :filteruserid';
            $params['filteruserid'] = $targetuserid;
        }
        // Type and treated ids were validated against the engine lists in resolve_input().
        $typeids = array_values(array_filter(array_map(
            static fn($value): ?int => taskflow_input_normalizer::to_int($value),
            (array)($input['type'] ?? [])
        ), static fn(?int $id): bool => $id !== null));
        if (!empty($typeids)) {
            [$typeasql, $typeaparams] = $DB->get_in_or_equal($typeids, SQL_PARAMS_NAMED, 'typea');
            [$typebsql, $typebparams] = $DB->get_in_or_equal($typeids, SQL_PARAMS_NAMED, 'typeb');
            $where .= " AND (r.request {$typeasql} OR (r.request = 0 AND r.status {$typebsql}))";
            $params = array_merge($params, $typeaparams, $typebparams);
        }
        $treated = taskflow_input_normalizer::to_int($input['treated'] ?? null);
        if ($treated !== null) {
            $where .= ' AND r.treated = :treated';
            $params['treated'] = $treated;
        }
        // Creation date bounds were parsed to timestamps in resolve_input().
        $createdafter = taskflow_input_normalizer::to_int($input['createdafter'] ?? null);
        if ($createdafter !== null) {
            $where .= ' AND r.timecreated >= :createdafter';
            $params['createdafter'] = $createdafter;
        }
        $createdbefore = taskflow_input_normalizer::to_int($input['createdbefore'] ?? null);
        if ($createdbefore !== null) {
            $where .= ' AND r.timecreated < :createdbefore';
            $params['createdbefore'] = $createdbefore;
        }
        $limit = (int)$input['limit'];

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

        $filters = $this->describe_filters($input, $targetuserid, $typeids, $treated, $types, $lang);
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

        // Receiver side follows the requests dashboard: viewrequests plus the relationship is enough
        // to SEE requests addressed to the acting user; treatrequests is only needed to treat them.
        if (
            has_capability(self::CAP_VIEWREQUESTS, $context, $userid)
            || has_capability(self::CAP_TREATREQUESTS, $context, $userid)
        ) {
            // A null from visible_userids() means unrestricted (viewassignment holders); on the
            // receiver side that still means "my own team", never an empty team (#463).
            $visible = $this->permissions()->visible_userids($userid);
            if ($visible === null) {
                $visible = array_map('intval', supervisor::get_visible_subordinate_ids($userid));
            }
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
     * Resolve one type input value (id, type key or localized title) to a type id, like
     * search_assignments resolves status names (#465). Structural comparison only, no word list.
     *
     * @param mixed $value
     * @param array $types Type id => type key.
     * @param string $lang
     * @return int|null Null when unknown.
     */
    private function resolve_type_id($value, array $types, string $lang): ?int {
        $int = taskflow_input_normalizer::to_int($value);
        if ($int !== null) {
            return array_key_exists($int, $types) ? $int : null;
        }
        if (!is_string($value)) {
            return null;
        }
        $needle = \core_text::strtolower(trim($value));
        if ($needle === '') {
            return null;
        }
        foreach ($types as $id => $key) {
            if (\core_text::strtolower((string)$key) === $needle) {
                return (int)$id;
            }
        }
        $languages = array_values(array_unique(array_filter([$lang, current_language(), 'en'])));
        foreach ($types as $id => $key) {
            foreach ($languages as $language) {
                $manager = get_string_manager();
                if (
                    $manager->string_exists($key . '_title', 'local_taskflow')
                    && \core_text::strtolower($manager->get_string($key . '_title', 'local_taskflow', null, $language)) === $needle
                ) {
                    return (int)$id;
                }
            }
        }
        return null;
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
     * @param int[] $typeids
     * @param int|null $treated
     * @param array $types Type id => type key.
     * @param string $lang
     * @return string[]
     */
    private function describe_filters(
        array $input,
        int $targetuserid,
        array $typeids,
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
        foreach ($typeids as $typeid) {
            if (array_key_exists($typeid, $types)) {
                $filters[] = $this->type_label($typeid, $types, $lang);
            }
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
