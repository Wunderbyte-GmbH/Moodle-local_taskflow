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
use local_taskflow\local\assignments\assignment_query_builder;
use local_taskflow\local\wizard\engine\observation_time;
use local_taskflow\local\wizard\engine\skill_risk_class;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\taskflow_input_normalizer;
use local_taskflow\local\wizard\taskflow\taskflow_permission_resolver;
use local_taskflow\local\wizard\taskflow\taskflow_result_link_builder;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use local_taskflow\local\wizard\taskflow\taskflow_unit_resolver;

/**
 * Read-only skill local_taskflow.search_assignments (implementation plan §2 #5).
 *
 * Lists assignments visible to the acting user. The visibility scope is derived from
 * taskflow_permission_resolver (admin: everything; supervisor/deputy: subordinates plus
 * self; everybody else: own assignments only) and never from a native capability, so
 * supervisors are not locked out by the engine's hard capability check. Status filters
 * accept status ids or status names and are resolved against assignment_status_facade
 * (engine state); numeric status ids are never hard-coded.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_assignments_skill extends taskflow_skill_base {
    /** Skill name. */
    public const TASK_NAME = 'local_taskflow.search_assignments';

    /** Default number of rows. */
    public const DEFAULT_LIMIT = 25;

    /** Upper bound of rows. */
    public const MAX_LIMIT = 100;

    /** Issue code: a status value could not be resolved. */
    public const ISSUE_STATUS_UNKNOWN = 'TASKFLOW_STATUS_UNKNOWN';

    /** Issue code: a date value could not be parsed. */
    public const ISSUE_DATE_INVALID = 'TASKFLOW_DATE_INVALID';

    /** Issue code: the organisational unit does not exist. */
    public const ISSUE_UNIT_NOT_FOUND = 'TASKFLOW_UNIT_NOT_FOUND';

    /** Issue code: several organisational units match the query. */
    public const ISSUE_UNIT_AMBIGUOUS = 'TASKFLOW_UNIT_AMBIGUOUS';

    /** @var taskflow_unit_resolver|null */
    private ?taskflow_unit_resolver $unitresolver = null;

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
            'description' => 'Search taskflow assignments visible to the acting user (admin: all; supervisor/deputy: '
                . 'subordinates and own; otherwise own only). Filters by person, unit membership (unit name or '
                . 'id, sub-units included), rule, status, due date. Answers who is overdue or due soon in a unit '
                . 'or team directly: unitquery "Team Nord" + overdueonly, or duebefore/dueafter; no list_units '
                . 'call is needed. Deadline questions belong here, not to list_units. A person or unit filter '
                . 'that matches nothing is rejected, never widened to all assignments.',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'Which assignments does Anna Muster have?',
                'Show all overdue assignments of my team',
                'Who in unit 7 is overdue?',
                'Who in Team Nord is past their deadline?',
                'Which assignments in the Facility unit are due within the next 14 days?',
                'List assignments of rule 17 that are due before 30 September',
                'What do I still have to complete?',
                'Assignments in unit 3 with status paused',
            ],
            'properties' => [
                'userid' => [
                    'type' => 'integer',
                    'description' => 'Restrict to assignments of this user id.',
                    'required' => false,
                ],
                'userquery' => [
                    'type' => 'string',
                    'description' => 'Restrict to assignments of the user matching this id, e-mail, username or name.',
                    'required' => false,
                ],
                'unitid' => [
                    'type' => 'integer',
                    'description' => 'Restrict to assignments of the members of this organisational unit id, '
                        . 'sub-units included (membership, not the unit a rule is attached to).',
                    'required' => false,
                ],
                'unitquery' => [
                    'type' => 'string',
                    'description' => 'Unit or team name (case-insensitive part of the name) instead of unitid; '
                        . 'resolved here, a unit name is never a userquery. Several matches are rejected with '
                        . 'the candidates.',
                    'required' => false,
                ],
                'ruleunitid' => [
                    'type' => 'integer',
                    'description' => 'Restrict to assignments created by rules attached to this unit id (the '
                        . 'unit of the rule, regardless of where the person is a member).',
                    'required' => false,
                ],
                'ruleid' => [
                    'type' => 'integer',
                    'description' => 'Restrict to assignments created by this rule id.',
                    'required' => false,
                ],
                'status' => [
                    'type' => 'array',
                    'description' => 'Restrict to these statuses: status ids or status names (e.g. assigned, overdue, '
                        . 'completed, paused, prolonged). Unknown values are rejected with the list of valid names.',
                    'required' => false,
                ],
                'duebefore' => [
                    'type' => 'string',
                    'description' => 'Only assignments due before this date (ISO 8601 date/time or Unix timestamp).',
                    'required' => false,
                ],
                'dueafter' => [
                    'type' => 'string',
                    'description' => 'Only assignments due after this date (ISO 8601 date/time or Unix timestamp).',
                    'required' => false,
                ],
                'activeonly' => [
                    'type' => 'boolean',
                    'description' => 'Only active assignments (default true; default false when a status filter is '
                        . 'given, because paused assignments are stored inactive). Set false to include inactive ones.',
                    'required' => false,
                ],
                'overdueonly' => [
                    'type' => 'boolean',
                    'description' => 'Only overdue assignments: status overdue, or an active status with a due date '
                        . 'in the past.',
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
            'intent' => 'List or count taskflow assignments of one person, a team, a unit or a rule, '
                . 'including who is overdue or due before/after a date.',
            'input_fields_for_prompt' => ['userquery (or userid), unitquery (or unitid), status, overdueonly, ruleid'],
            'anchor_fields' => ['userquery', 'userid', 'unitquery', 'ruleid'],
        ];
    }

    /**
     * Example input for the planner contract.
     *
     * @return array
     */
    public function get_example_input(): array {
        return ['userquery' => 'anna.muster@example.org', 'overdueonly' => true, 'limit' => 25];
    }

    /**
     * Preflight: resolve the target user, the scope and the filter values.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array{status:string,prepared_input:array,issues:array}
     */
    protected function run_preflight(array $input, int $contextid, int $userid): array {
        $resolved = $this->resolve_filters($input, $userid);
        if (!empty($resolved['issues'])) {
            return $this->invalid($resolved['issues']);
        }
        return $this->pass($resolved['prepared']);
    }

    /**
     * Resolve every filter of the raw input against engine/DB state (shared by preflight and execute).
     *
     * A person filter that resolves nobody is a hard stop (not found / ambiguous) and never
     * widens the search to the acting user's scope; unknown status values and unparsable
     * dates are rejected as well. Already prepared input (ids, timestamps) passes unchanged.
     *
     * @param array $input Raw or prepared input.
     * @param int $userid Acting user.
     * @return array{prepared:array,issues:array}
     */
    private function resolve_filters(array $input, int $userid): array {
        $input = $this->canonical_input($input);
        $lang = $this->get_output_language($input);
        $issues = [];
        $prepared = $input;

        // Target user (optional): unresolvable ⇒ hard stop, never "all visible".
        $targetuserid = 0;
        $hasuserfilter = !empty(taskflow_input_normalizer::to_int($input['userid'] ?? null))
            || trim((string)($input['userquery'] ?? '')) !== '';
        if ($hasuserfilter) {
            $targetuserid = $this->resolve_userid($input, $userid);
            if ($targetuserid <= 0) {
                return ['prepared' => [], 'issues' => [$this->user_lookup_issue($input, $lang)]];
            }
            $prepared['userid'] = $targetuserid;
            unset($prepared['userquery']);
        }

        // Scope (admin > supervisor > self) — never a native capability.
        $visible = $this->permissions()->visible_userids($userid);
        if ($visible !== null && $targetuserid > 0 && !in_array($targetuserid, $visible, true)) {
            return ['prepared' => [], 'issues' => [$this->scope_denied_issue($lang, ['field' => 'userid'])]];
        }

        // Unit filter: name or id ⇒ member user ids (sub-units included); unknown/ambiguous ⇒ hard stop.
        $unitquery = trim((string)($input['unitquery'] ?? ''));
        $unitid = taskflow_input_normalizer::to_int($input['unitid'] ?? null) ?? 0;
        if ($unitid <= 0 && $unitquery !== '') {
            $candidates = $this->units()->candidates($unitquery);
            if (count($candidates) !== 1) {
                $issue = $this->not_found_issue(
                    empty($candidates) ? self::ISSUE_UNIT_NOT_FOUND : self::ISSUE_UNIT_AMBIGUOUS,
                    empty($candidates)
                        ? $this->localized_string('agent_unit_notfound', $unitquery, $lang)
                        : $this->localized_string('agent_unit_ambiguous', (object)[
                            'query' => $unitquery,
                            'candidates' => implode(', ', array_map(
                                static fn(int $id, string $name): string =>
                                    taskflow_unit_resolver::own_name($name) . ' (#' . $id . ')',
                                array_keys($candidates),
                                array_values($candidates)
                            )),
                        ], $lang),
                    ['field' => 'unitquery']
                );
                if (!empty($candidates)) {
                    $issue['candidates'] = array_map(
                        static fn(int $id, string $name): array => ['unitid' => $id, 'name' => $name],
                        array_keys($candidates),
                        array_values($candidates)
                    );
                }
                return ['prepared' => [], 'issues' => [$issue]];
            }
            $unitid = (int)array_key_first($candidates);
        }
        if ($unitid > 0) {
            if (!$this->units()->exists($unitid)) {
                return ['prepared' => [], 'issues' => [$this->not_found_issue(
                    self::ISSUE_UNIT_NOT_FOUND,
                    $this->localized_string('agent_unit_notfound', (string)$unitid, $lang),
                    ['field' => 'unitid']
                )]];
            }
            $prepared['unitid'] = $unitid;
            $prepared['unitmemberids'] = $this->units()->member_userids($unitid, true);
        } else {
            unset($prepared['unitid'], $prepared['unitmemberids']);
        }
        unset($prepared['unitquery']);
        $ruleunitid = taskflow_input_normalizer::to_int($input['ruleunitid'] ?? null) ?? 0;
        if ($ruleunitid > 0) {
            $prepared['ruleunitid'] = $ruleunitid;
        } else {
            unset($prepared['ruleunitid']);
        }

        // Status filter: ids or names, resolved against the status facade; unknown ⇒ rejected.
        $statusinput = taskflow_input_normalizer::to_list($input['status'] ?? null) ?? [];
        $statusids = [];
        foreach ($statusinput as $value) {
            $resolved = $this->resolve_status_id($value, $lang);
            if ($resolved === null) {
                $issues[] = [
                    'code' => self::ISSUE_STATUS_UNKNOWN,
                    'severity' => 'needs_clarification',
                    'field' => 'status',
                    'message' => $this->localized_string('agent_status_unknown', (object)[
                        'value' => (string)(is_scalar($value) ? $value : json_encode($value)),
                        'known' => implode(', ', array_keys($this->status_names_by_type())),
                    ], $lang),
                ];
                continue;
            }
            $statusids[] = $resolved;
        }
        $prepared['status'] = array_values(array_unique($statusids));

        // Due date bounds.
        foreach (['duebefore', 'dueafter'] as $field) {
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
        // An explicit status filter (e.g. paused, which deactivates the row) includes inactive rows unless
        // activeonly was set explicitly.
        $prepared['activeonly'] = taskflow_input_normalizer::to_bool($input['activeonly'] ?? null) ?? empty($statusids);
        $prepared['overdueonly'] = taskflow_input_normalizer::to_bool($input['overdueonly'] ?? null) ?? false;

        return ['prepared' => $prepared, 'issues' => []];
    }

    /**
     * Execute the search.
     *
     * The read-only chat path calls execute() with the raw command input (no preflight), so
     * every filter is resolved again here with the same rule: an unresolvable person or an
     * unknown status is an error result without any assignments.
     *
     * @param array $input Prepared or raw input.
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        global $DB;

        $lang = $this->get_output_language($input);
        $now = time();

        // Filters and scope (recomputed: execute() must be safe without preflight).
        $resolved = $this->resolve_filters($input, $userid);
        if (!empty($resolved['issues'])) {
            $first = reset($resolved['issues']);
            return $this->error_result(
                (string)($first['code'] ?? self::ISSUE_STATUS_UNKNOWN),
                implode(' ', array_map(static fn(array $issue): string => (string)($issue['message'] ?? ''), $resolved['issues'])),
                [
                    'issue_codes' => array_values(array_unique(array_map(
                        static fn(array $issue): string => (string)($issue['code'] ?? ''),
                        $resolved['issues']
                    ))),
                    'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input),
                ]
            );
        }
        $input = $resolved['prepared'];
        $visible = $this->permissions()->visible_userids($userid);
        $scope = $this->scope_name($visible, $userid);
        $targetuserid = taskflow_input_normalizer::to_int($input['userid'] ?? null) ?? 0;

        $activeonly = taskflow_input_normalizer::to_bool($input['activeonly'] ?? null) ?? true;
        $overdueonly = taskflow_input_normalizer::to_bool($input['overdueonly'] ?? null) ?? false;
        $limit = max(1, min(self::MAX_LIMIT, taskflow_input_normalizer::to_int($input['limit'] ?? null) ?? self::DEFAULT_LIMIT));
        // Status ids were validated against the facade in resolve_filters(); no casting of names here.
        $statusids = array_values(array_map('intval', taskflow_input_normalizer::to_list($input['status'] ?? null) ?? []));
        $unitid = taskflow_input_normalizer::to_int($input['unitid'] ?? null) ?? 0;
        $unitmemberids = array_map('intval', (array)($input['unitmemberids'] ?? []));
        $ruleunitid = taskflow_input_normalizer::to_int($input['ruleunitid'] ?? null) ?? 0;
        $ruleid = taskflow_input_normalizer::to_int($input['ruleid'] ?? null) ?? 0;
        $duebefore = taskflow_input_normalizer::to_int($input['duebefore'] ?? null);
        $dueafter = taskflow_input_normalizer::to_int($input['dueafter'] ?? null);

        // Inner filters through the domain query builder (unaliased columns → subselect).
        $builder = (new assignment_query_builder())
            ->where_active($activeonly ? 1 : null)
            ->where_userid($targetuserid > 0 ? (string)$targetuserid : null)
            ->where_status(!empty($statusids) ? $statusids : null);
        [$innerwhere, $params] = $builder->get_sql();
        $innerwhere = empty($innerwhere) ? '1 = 1' : implode(' AND ', $innerwhere);

        $outer = [];
        if ($visible !== null) {
            if (empty($visible)) {
                $outer[] = '1 = 0';
            } else {
                [$insql, $inparams] = $DB->get_in_or_equal($visible, SQL_PARAMS_NAMED, 'vis');
                $outer[] = "ta.userid {$insql}";
                $params = array_merge($params, $inparams);
            }
        }
        if ($unitid > 0) {
            // Membership semantics: the people in the unit (and its sub-units), whatever rule assigned them.
            if (empty($unitmemberids)) {
                $outer[] = '1 = 0';
            } else {
                [$memsql, $memparams] = $DB->get_in_or_equal($unitmemberids, SQL_PARAMS_NAMED, 'mem');
                $outer[] = "ta.userid {$memsql}";
                $params = array_merge($params, $memparams);
            }
        }
        if ($ruleunitid > 0) {
            $outer[] = 'ta.unitid = :ruleunitid';
            $params['ruleunitid'] = $ruleunitid;
        }
        if ($ruleid > 0) {
            $outer[] = 'ta.ruleid = :ruleid';
            $params['ruleid'] = $ruleid;
        }
        if ($duebefore !== null) {
            $outer[] = 'ta.duedate < :duebefore';
            $params['duebefore'] = $duebefore;
        }
        if ($dueafter !== null) {
            $outer[] = 'ta.duedate > :dueafter';
            $params['dueafter'] = $dueafter;
        }
        if ($overdueonly) {
            $activestates = array_map('intval', assignment_status_facade::get_all_active_states());
            $overduestatus = assignment_status_facade::get_status_identifier('overdue');
            $clause = 'ta.status = :overduestatus';
            $params['overduestatus'] = $overduestatus;
            if (!empty($activestates)) {
                [$actsql, $actparams] = $DB->get_in_or_equal($activestates, SQL_PARAMS_NAMED, 'act');
                $clause .= " OR (ta.duedate > 0 AND ta.duedate < :now AND ta.status {$actsql})";
                $params['now'] = $now;
                $params = array_merge($params, $actparams);
            }
            $outer[] = '(' . $clause . ')';
        }
        $outerwhere = empty($outer) ? '1 = 1' : implode(' AND ', $outer);

        $from = "FROM (SELECT * FROM {local_taskflow_assignment} WHERE {$innerwhere}) ta
                 JOIN {user} u ON u.id = ta.userid
            LEFT JOIN {local_taskflow_rules} r ON r.id = ta.ruleid
                WHERE {$outerwhere}";

        $total = (int)$DB->count_records_sql("SELECT COUNT(ta.id) {$from}", $params);
        $userfields = \core_user\fields::for_name()->get_sql('u')->selects;
        $records = $DB->get_records_sql(
            "SELECT ta.id, ta.userid, ta.ruleid, ta.unitid, ta.status, ta.active, ta.assigneddate, ta.duedate,
                    ta.overduecounter, ta.prolongedcounter, ta.keepchanges, r.rulejson, r.rulename, u.email {$userfields}
             {$from}
             ORDER BY CASE WHEN ta.duedate IS NULL OR ta.duedate = 0 THEN 1 ELSE 0 END, ta.duedate ASC, ta.id ASC",
            $params,
            0,
            $limit
        );

        $rows = [];
        $userids = [];
        foreach ($records as $record) {
            $rulename = trim((string)($record->rulename ?? ''));
            if ($rulename === '' && !empty($record->rulejson)) {
                $decoded = json_decode((string)$record->rulejson, true);
                $rulename = (string)($decoded['rulejson']['rule']['name'] ?? '');
            }
            $status = (int)$record->status;
            $duedate = (int)($record->duedate ?? 0);
            $rows[] = [
                'id' => (int)$record->id,
                'userid' => (int)$record->userid,
                'fullname' => fullname($record),
                'email' => (string)($record->email ?? ''),
                'ruleid' => (int)$record->ruleid,
                'rulename' => $rulename,
                'unitid' => (int)($record->unitid ?? 0),
                'status' => $status,
                'statuslabel' => $this->status_label($status, $lang),
                'active' => (bool)$record->active,
                'assigneddate' => (int)($record->assigneddate ?? 0),
                'assigneddate_text' => $this->format_time((int)($record->assigneddate ?? 0)),
                'duedate' => $duedate,
                'duedate_text' => $this->format_time($duedate),
                'overdue' => $this->is_overdue($status, $duedate, $now),
                'overduecounter' => (int)($record->overduecounter ?? 0),
                'prolongedcounter' => (int)($record->prolongedcounter ?? 0),
                'keepchanges' => (bool)($record->keepchanges ?? 0),
                'url' => taskflow_result_link_builder::assignment_url((int)$record->id),
            ];
            $userids[] = (int)$record->userid;
        }

        $filters = $this->describe_filters($input, $statusids, $targetuserid, $lang);
        $scopelabel = $this->localized_string('agent_scope_' . $scope, null, $lang);
        $usermessage = $this->localized_string('agent_search_assignments_summary', (object)[
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
                '#%d %s | %s (rule %d) | %s | %s: %s%s%s',
                $row['id'],
                $row['fullname'],
                $row['rulename'],
                $row['ruleid'],
                $row['statuslabel'],
                $this->localized_string('duedate', null, $lang),
                $row['duedate_text'] !== '' ? $row['duedate_text'] : '-',
                $row['overdue'] ? ' [overdue]' : '',
                $row['active'] ? '' : ' [inactive]'
            );
        }

        return $this->base_result(self::STATUS_EXECUTED, [
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'observation_full' => implode("\n", $observation),
            'resultid' => $rows[0]['id'] ?? 0,
            'assignments' => $rows,
            'scope' => $scope,
            'total' => $total,
            'shown' => count($rows),
            'filters' => $filters,
            'links' => $this->links(
                taskflow_result_link_builder::dashboard_url(),
                ['dashboard', 'assignments_status_lifecycle']
            ),
            'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, [
                'Scope: ' . $scope,
                'Visible userids: ' . ($visible === null ? 'all' : implode(',', $visible)),
                'Unit: ' . ($unitid > 0 ? $unitid . ' (members: ' . implode(',', $unitmemberids) . ')' : '-'),
                'Total: ' . $total . ', shown: ' . count($rows),
            ]),
            'preview' => [
                'type' => taskflow_preview_renderer_factory::TYPE_ASSIGNMENT_LIST,
                'data' => [
                    'assignments' => $rows,
                    'scope' => $scope,
                    'total' => $total,
                    'filters' => $filters,
                ],
                'payload' => [
                    'assignmentids' => array_column($rows, 'id'),
                    'userids' => array_values(array_unique($userids)),
                ],
            ],
        ]);
    }

    /**
     * Unit resolver (memoized per skill instance).
     *
     * @return taskflow_unit_resolver
     */
    private function units(): taskflow_unit_resolver {
        if ($this->unitresolver === null) {
            $this->unitresolver = new taskflow_unit_resolver();
        }
        return $this->unitresolver;
    }

    /**
     * Scope name of the acting user derived from the visible id list.
     *
     * @param int[]|null $visible
     * @param int $userid
     * @return string admin|supervisor|self
     */
    private function scope_name(?array $visible, int $userid): string {
        if ($visible === null) {
            return taskflow_permission_resolver::SCOPE_ADMIN;
        }
        $others = array_filter($visible, static fn(int $id): bool => $id !== $userid);
        return empty($others) ? taskflow_permission_resolver::SCOPE_SELF : taskflow_permission_resolver::SCOPE_SUPERVISOR;
    }

    /**
     * Status names (type class names) => id, from the facade.
     *
     * @return array<string,int>
     */
    private function status_names_by_type(): array {
        $map = [];
        foreach (assignment_status_facade::get_all() as $id => $info) {
            $map[(string)($info['label'] ?? '')] = (int)$id;
        }
        unset($map['']);
        return $map;
    }

    /**
     * Resolve one status input value (id, type name or localized name) to a status id.
     *
     * @param mixed $value
     * @param string $lang
     * @return int|null Null when unknown.
     */
    private function resolve_status_id($value, string $lang): ?int {
        $all = assignment_status_facade::get_all();
        $int = taskflow_input_normalizer::to_int($value);
        if ($int !== null) {
            return isset($all[$int]) ? $int : null;
        }
        if (!is_string($value)) {
            return null;
        }
        $needle = \core_text::strtolower(trim($value));
        if ($needle === '') {
            return null;
        }
        foreach ($all as $id => $info) {
            if (\core_text::strtolower((string)($info['label'] ?? '')) === $needle) {
                return (int)$id;
            }
        }
        $languages = array_values(array_unique(array_filter([$lang, current_language(), 'en'])));
        foreach ($all as $id => $info) {
            foreach ($languages as $language) {
                if (\core_text::strtolower(assignment_status_facade::get_specific_names((int)$id, $language)) === $needle) {
                    return (int)$id;
                }
            }
        }
        return null;
    }

    /**
     * Parse a Unix timestamp or ISO date string.
     *
     * @param string $value
     * @return int|null
     */
    private function parse_timestamp(string $value): ?int {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{9,11}$/', $value)) {
            return (int)$value;
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? null : $timestamp;
    }

    /**
     * Whether an assignment counts as overdue (status overdue, or active with a past due date).
     *
     * @param int $status
     * @param int $duedate
     * @param int $now
     * @return bool
     */
    private function is_overdue(int $status, int $duedate, int $now): bool {
        if ($status === assignment_status_facade::get_status_identifier('overdue')) {
            return true;
        }
        if ($duedate <= 0 || $duedate >= $now) {
            return false;
        }
        return in_array($status, array_map('intval', assignment_status_facade::get_all_active_states()), true);
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

    /**
     * Human readable list of the active filters.
     *
     * @param array $input
     * @param int[] $statusids
     * @param int $targetuserid
     * @param string $lang
     * @return string[]
     */
    private function describe_filters(array $input, array $statusids, int $targetuserid, string $lang): array {
        $filters = [];
        if ($targetuserid > 0) {
            $user = \core_user::get_user($targetuserid, '*', IGNORE_MISSING);
            $filters[] = $this->localized_string('fullname', null, $lang) . ': '
                . ($user ? fullname($user) : (string)$targetuserid);
        }
        $ruleid = taskflow_input_normalizer::to_int($input['ruleid'] ?? null) ?? 0;
        if ($ruleid > 0) {
            $filters[] = $this->localized_string('rule', null, $lang) . ' #' . $ruleid;
        }
        $unitid = taskflow_input_normalizer::to_int($input['unitid'] ?? null) ?? 0;
        if ($unitid > 0) {
            $unitname = taskflow_unit_resolver::own_name($this->units()->name($unitid));
            $filters[] = $this->localized_string(
                'agent_filter_unitmembers',
                ($unitname !== '' ? $unitname . ' ' : '') . '#' . $unitid,
                $lang
            );
        }
        $ruleunitid = taskflow_input_normalizer::to_int($input['ruleunitid'] ?? null) ?? 0;
        if ($ruleunitid > 0) {
            $filters[] = $this->localized_string('agent_filter_ruleunit', '#' . $ruleunitid, $lang);
        }
        if (!empty($statusids)) {
            $filters[] = $this->localized_string('status', null, $lang) . ': ' . implode(', ', array_map(
                fn(int $id): string => $this->status_label($id, $lang),
                $statusids
            ));
        }
        $duebefore = taskflow_input_normalizer::to_int($input['duebefore'] ?? null);
        if ($duebefore !== null) {
            $filters[] = $this->localized_string('agent_filter_duebefore', $this->format_time($duebefore), $lang);
        }
        $dueafter = taskflow_input_normalizer::to_int($input['dueafter'] ?? null);
        if ($dueafter !== null) {
            $filters[] = $this->localized_string('agent_filter_dueafter', $this->format_time($dueafter), $lang);
        }
        if (taskflow_input_normalizer::to_bool($input['overdueonly'] ?? null) ?? false) {
            $filters[] = $this->localized_string('agent_filter_overdueonly', null, $lang);
        }
        if (!(taskflow_input_normalizer::to_bool($input['activeonly'] ?? null) ?? true)) {
            $filters[] = $this->localized_string('agent_filter_includeinactive', null, $lang);
        }
        return $filters;
    }
}
