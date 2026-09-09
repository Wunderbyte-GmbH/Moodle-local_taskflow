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

use local_taskflow\local\assignment_process\assignment_preprocessor;
use local_taskflow\local\units\organisational_unit_factory;
use local_taskflow\local\wizard\engine\queue_identity_provider_interface;
use local_taskflow\local\wizard\engine\skill_risk_class;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\taskflow_input_normalizer;
use local_taskflow\local\wizard\taskflow\taskflow_permission_resolver;
use local_taskflow\local\wizard\taskflow\taskflow_result_link_builder;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;

/**
 * Skill local_taskflow.recheck_assignments (implementation plan §2 #24).
 *
 * R1, idempotent: runs exactly the assignment logic the assignment page runs behind
 * "assignment.php?action=checkstatus" — an assignment_preprocessor built with the same
 * selectors (set_this_user(), set_all_inheritance_unit_rules(), process_assignemnts()), once
 * per assignment of the target user so every rule json / unit combination is evaluated like on
 * the page. The skill itself contains no assignment logic.
 *
 * The effect is synchronous, so the result is 'executed'. To stay faithful, the assignments of
 * the user are captured before and after the run and only the real differences (status, due
 * date, active flag, newly created assignments) are reported in changed[]; when nothing moved
 * the result says so plainly instead of implying work was done.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recheck_assignments_skill extends taskflow_skill_base implements queue_identity_provider_interface {
    /** Skill name. */
    public const TASK_NAME = 'local_taskflow.recheck_assignments';

    /** Capability giving administrative access (supervisors pass through the scope resolver). */
    public const CAPABILITY = taskflow_permission_resolver::CAP_EDIT;

    /** Issue code: neither a user nor an assignment was named, or both were. */
    public const ISSUE_SELECTOR_REQUIRED = 'TASKFLOW_RECHECK_SELECTOR_REQUIRED';

    /** Issue code: the re-check awaits the user's confirmation. */
    public const ISSUE_CONFIRM_REQUIRED = 'TASKFLOW_RECHECK_ASSIGNMENTS_CONFIRM_REQUIRED';

    /** Issue code: the user has no assignment at all. */
    public const ISSUE_NO_ASSIGNMENTS = 'TASKFLOW_RECHECK_NO_ASSIGNMENTS';

    /**
     * Constructor: mutating, scoped write; access is resolved per user (admin or supervisor).
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
            'description' => 'Re-run the taskflow assignment logic for one person right now, exactly '
            . 'like the "check status" action '
                . 'on the assignment page. Rules of the unit (including inherited ones) are evaluated again, statuses '
                . 'and due dates are recalculated and missing assignments are created. Identify the person by userid or '
                . 'userquery, or name a single assignment; the skill reports only what really changed.',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'Re-check the assignments of Anna Muster',
                'Run the status check for assignment 4711 now',
                'The status of user 123 looks outdated, evaluate the rules again',
            ],
            'properties' => [
                'userid' => [
                    'type' => 'integer',
                    'description' => 'Id of the person whose assignments are re-checked.',
                    'required' => false,
                ],
                'userquery' => [
                    'type' => 'string',
                    'description' => 'Name, e-mail or username of the person, when the id is unknown.',
                    'required' => false,
                ],
                'assignmentid' => [
                    'type' => 'integer',
                    'description' => 'Id of a single assignment; its owner is re-checked. '
                        . 'Use either the person or the assignment, not both.',
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
            'intent' => 'Re-evaluate the taskflow rules for one person and report the resulting changes.',
            'input_fields_for_prompt' => ['userid', 'userquery', 'assignmentid'],
            'anchor_fields' => ['assignmentid', 'userid', 'userquery'],
        ];
    }

    /**
     * Example input.
     *
     * @return array
     */
    public function get_example_input(): array {
        return ['userquery' => 'anna.muster@example.org'];
    }

    /**
     * Queue identity: re-checks of the same person deduplicate.
     *
     * @param array $input
     * @return array<string,mixed>
     */
    public function build_queue_business_identity(array $input): array {
        return [
            'task_family' => 'taskflow_assignment_recheck',
            'skill' => self::TASK_NAME,
            'target' => [
                'userid' => (int)(taskflow_input_normalizer::to_int($input['userid'] ?? null) ?? 0),
                'userquery' => trim((string)($input['userquery'] ?? '')),
                'assignmentid' => (int)(taskflow_input_normalizer::to_int($input['assignmentid'] ?? null) ?? 0),
            ],
        ];
    }

    /**
     * Structural validation: exactly one selector.
     *
     * @param array $input
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>}
     */
    public function check_structure(array $input): array {
        $lang = $this->get_output_language($input);
        $hasuser = ((int)(taskflow_input_normalizer::to_int($input['userid'] ?? null) ?? 0)) > 0
            || trim((string)($input['userquery'] ?? '')) !== '';
        $hasassignment = ((int)(taskflow_input_normalizer::to_int($input['assignmentid'] ?? null) ?? 0)) > 0;

        $errors = [];
        if ($hasuser === $hasassignment) {
            $errors[] = $this->localized_string('agent_recheck_selector_required', null, $lang);
        }
        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => []];
    }

    /**
     * Preflight: resolve the target user, check the scope, then confirm.
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
                    'code' => self::ISSUE_SELECTOR_REQUIRED,
                    'severity' => 'needs_clarification',
                    'field' => 'userid',
                    'message' => (string)$error,
                ];
            }
            return $this->invalid($issues);
        }

        $assignmentid = (int)(taskflow_input_normalizer::to_int($input['assignmentid'] ?? null) ?? 0);
        if ($assignmentid > 0) {
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
            $targetuserid = (int)$assignment->userid;
        } else {
            $targetuserid = $this->resolve_userid($input, 0);
            if ($targetuserid <= 0) {
                return $this->invalid([
                    $this->not_found_issue(
                        self::ISSUE_USER_NOT_FOUND,
                        $this->localized_string('agent_user_notfound', trim((string)($input['userquery'] ?? '')), $lang),
                        ['field' => 'userquery']
                    ),
                ]);
            }
        }

        if (!$this->may_recheck($targetuserid, $userid)) {
            return $this->invalid([$this->scope_denied_issue($lang, ['field' => 'userid'])]);
        }

        $snapshot = $this->snapshot($targetuserid);
        if (empty($snapshot)) {
            return $this->invalid([[
                'code' => self::ISSUE_NO_ASSIGNMENTS,
                'severity' => 'needs_clarification',
                'field' => 'userid',
                'message' => $this->localized_string('agent_recheck_no_assignments', $this->fullname($targetuserid), $lang),
            ]]);
        }

        $prepared = $input;
        $prepared['userid'] = $targetuserid;
        unset($prepared['userquery']);

        return $this->confirmable($prepared, [[
            'code' => self::ISSUE_CONFIRM_REQUIRED,
            'severity' => 'needs_confirmation',
            'user_question' => $this->localized_string('agent_recheck_confirm', (object)[
                'fullname' => $this->fullname($targetuserid),
                'assignments' => count($snapshot),
            ], $lang),
        ]]);
    }

    /**
     * Tier-3 confirmation preview: who is re-checked, how much is in scope, what is written.
     *
     * @param array $input Prepared input.
     * @return array{title:string,summary:string,rows:array[]}|null
     */
    public function describe_proposed_action(array $input): ?array {
        $lang = $this->get_output_language($input);
        $targetuserid = (int)(taskflow_input_normalizer::to_int($input['userid'] ?? null) ?? 0);
        if ($targetuserid <= 0) {
            return null;
        }
        $fullname = $this->fullname($targetuserid);
        if ($fullname === '') {
            return null;
        }
        $snapshot = $this->snapshot($targetuserid);
        $scope = $this->scope_facts($snapshot);

        return [
            'title' => $this->localized_string('agent_recheck_title', (object)[
                'fullname' => $fullname,
            ], $lang),
            'summary' => $this->localized_string('agent_recheck_summary', (object)[
                'fullname' => $fullname,
                'assignments' => count($snapshot),
            ], $lang),
            'rows' => [
                [
                    'label' => $this->localized_string('fullname', null, $lang),
                    'value' => $fullname,
                ],
                [
                    'label' => $this->localized_string('agent_preview_assignments', null, $lang),
                    'value' => (string)count($snapshot),
                ],
                [
                    'label' => $this->localized_string('agent_preview_units', null, $lang),
                    'value' => $scope['units'] !== '' ? $scope['units'] : $this->localized_string(
                        'agent_preview_none',
                        null,
                        $lang
                    ),
                ],
                [
                    'label' => $this->localized_string('agent_preview_rules', null, $lang),
                    'value' => $scope['rules'],
                ],
            ],
        ];
    }

    /**
     * Execute: snapshot, run the preprocessor per assignment like the page, diff, report.
     *
     * @param array $input Prepared input.
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        $lang = $this->get_output_language($input);
        $debug = $this->build_task_debug_message(self::TASK_NAME, $input);

        $assignmentid = (int)(taskflow_input_normalizer::to_int($input['assignmentid'] ?? null) ?? 0);
        $targetuserid = (int)(taskflow_input_normalizer::to_int($input['userid'] ?? null) ?? 0);
        if ($targetuserid <= 0 && $assignmentid > 0) {
            $assignment = $this->resolve_assignment(['assignmentid' => $assignmentid]);
            if ($assignment === null) {
                return $this->error_result(
                    self::ISSUE_ASSIGNMENT_NOT_FOUND,
                    $this->localized_string('agent_notfound_assignment', $assignmentid, $lang),
                    ['debugmessage' => $debug]
                );
            }
            $targetuserid = (int)$assignment->userid;
        }
        if ($targetuserid <= 0) {
            $targetuserid = $this->resolve_userid($input, 0);
        }
        if ($targetuserid <= 0) {
            return $this->error_result(
                self::ISSUE_USER_NOT_FOUND,
                $this->localized_string('agent_user_notfound', trim((string)($input['userquery'] ?? '')), $lang),
                ['debugmessage' => $debug]
            );
        }
        if (!$this->may_recheck($targetuserid, $userid)) {
            return $this->error_result(
                self::ISSUE_SCOPE_DENIED,
                $this->localized_string('agent_scope_denied', null, $lang),
                ['debugmessage' => $debug]
            );
        }

        $before = $this->snapshot($targetuserid);
        $this->run_checkstatus($targetuserid, $before);
        $after = $this->snapshot($targetuserid);

        $changed = $this->diff($before, $after, $lang);
        $fullname = $this->fullname($targetuserid);
        $usermessage = empty($changed)
            ? $this->localized_string('agent_recheck_nochange', (object)[
                'fullname' => $fullname,
                'assignments' => count($after),
            ], $lang)
            : $this->localized_string('agent_recheck_done', (object)[
                'fullname' => $fullname,
                'changed' => count($changed),
                'assignments' => count($after),
            ], $lang);

        $observation = [$usermessage];
        foreach ($changed as $row) {
            $observation[] = sprintf(
                '#%d %s (rule %d): %s',
                (int)$row['id'],
                (string)$row['rulename'],
                (int)$row['ruleid'],
                (string)$row['change']
            );
        }

        return $this->base_result(self::STATUS_EXECUTED, [
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'observation_full' => implode("\n", $observation),
            'resultid' => $targetuserid,
            'userid' => $targetuserid,
            'fullname' => $fullname,
            'assignments_total' => count($after),
            'changed' => $changed,
            'changed_total' => count($changed),
            'links' => $this->links(
                taskflow_result_link_builder::dashboard_url(),
                ['assignments_status_lifecycle', 'assignments_due_dates', 'scheduled_tasks'],
                ['user' => taskflow_result_link_builder::user_url($targetuserid)]
            ),
            'outputlang' => $lang,
            'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, [
                'Target user: ' . $targetuserid,
                'Assignments before: ' . count($before) . ', after: ' . count($after),
                'Changed: ' . count($changed),
            ]),
            'preview' => [
                'type' => taskflow_preview_renderer_factory::TYPE_ASSIGNMENT_LIST,
                'data' => [
                    'assignments' => $changed,
                    'total' => count($changed),
                    'filters' => [$this->localized_string('agent_preview_change', null, $lang)],
                ],
                'payload' => [
                    'assignmentids' => array_values(array_map(
                        static fn(array $row): int => (int)$row['id'],
                        $changed
                    )),
                    'userids' => [$targetuserid],
                ],
            ],
        ]);
    }

    /**
     * Run the assignment logic like assignment.php?action=checkstatus, once per assignment.
     *
     * The page builds the preprocessor from the rule json and the unit of one assignment; a user
     * can hold assignments from several units, so the same call is repeated per distinct
     * rulejson/unit pair. Nothing else is done here — all logic lives in the preprocessor.
     *
     * @param int $targetuserid
     * @param array<int,array<string,mixed>> $snapshot Assignments of the user before the run.
     * @return void
     */
    private function run_checkstatus(int $targetuserid, array $snapshot): void {
        $seen = [];
        foreach ($snapshot as $row) {
            $rulejson = (string)($row['rulejson'] ?? '');
            $unitid = (int)($row['unitid'] ?? 0);
            $key = $unitid . '|' . md5($rulejson);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $data = [
                'relateduserid' => $targetuserid,
                'rulejson' => $rulejson,
                'other' => ['unitid' => $unitid],
            ];
            $preprocessor = new assignment_preprocessor($data);
            $preprocessor->set_this_user($data['relateduserid']);
            $preprocessor->set_all_inheritance_unit_rules();
            $preprocessor->process_assignemnts();
        }
    }

    /**
     * Assignments of a user with the fields the diff compares (fresh read, singleton free).
     *
     * @param int $targetuserid
     * @return array<int,array<string,mixed>> Keyed by assignment id.
     */
    private function snapshot(int $targetuserid): array {
        global $DB;

        $sql = "SELECT a.id, a.userid, a.ruleid, a.unitid, a.status, a.duedate, a.active,
                       a.assigneddate, a.overduecounter, a.prolongedcounter, r.rulename, r.rulejson
                  FROM {local_taskflow_assignment} a
             LEFT JOIN {local_taskflow_rules} r ON r.id = a.ruleid
                 WHERE a.userid = :userid
              ORDER BY a.id";
        $records = $DB->get_records_sql($sql, ['userid' => $targetuserid]);

        $rows = [];
        foreach ($records as $record) {
            $rows[(int)$record->id] = [
                'id' => (int)$record->id,
                'userid' => (int)$record->userid,
                'ruleid' => (int)$record->ruleid,
                'unitid' => (int)$record->unitid,
                'rulename' => (string)($record->rulename ?? ''),
                'rulejson' => (string)($record->rulejson ?? ''),
                'status' => (int)$record->status,
                'duedate' => (int)$record->duedate,
                'active' => (int)$record->active,
                'assigneddate' => (int)$record->assigneddate,
                'overduecounter' => (int)$record->overduecounter,
                'prolongedcounter' => (int)$record->prolongedcounter,
            ];
        }
        return $rows;
    }

    /**
     * Differences between two snapshots as preview/result rows (empty when nothing moved).
     *
     * @param array<int,array<string,mixed>> $before
     * @param array<int,array<string,mixed>> $after
     * @param string $lang
     * @return array<int,array<string,mixed>>
     */
    private function diff(array $before, array $after, string $lang): array {
        $changed = [];
        foreach ($after as $id => $row) {
            $old = $before[$id] ?? null;
            $changes = [];
            $parts = [];

            if ($old === null) {
                $parts[] = $this->localized_string('agent_recheck_change_created', null, $lang);
                $changes[] = ['field' => 'assignment', 'from' => null, 'to' => (int)$id];
            } else {
                if ((int)$old['status'] !== (int)$row['status']) {
                    $from = $this->status_label((int)$old['status'], $lang);
                    $to = $this->status_label((int)$row['status'], $lang);
                    $parts[] = $this->localized_string('status', null, $lang) . ': ' . $from . ' → ' . $to;
                    $changes[] = [
                        'field' => 'status',
                        'from' => (int)$old['status'],
                        'to' => (int)$row['status'],
                        'from_text' => $from,
                        'to_text' => $to,
                    ];
                }
                if ((int)$old['duedate'] !== (int)$row['duedate']) {
                    $from = $this->format_date((int)$old['duedate']);
                    $to = $this->format_date((int)$row['duedate']);
                    $parts[] = $this->localized_string('duedate', null, $lang) . ': ' . $from . ' → ' . $to;
                    $changes[] = [
                        'field' => 'duedate',
                        'from' => (int)$old['duedate'],
                        'to' => (int)$row['duedate'],
                        'from_text' => $from,
                        'to_text' => $to,
                    ];
                }
                if ((int)$old['active'] !== (int)$row['active']) {
                    $from = $this->localized_string($old['active'] ? 'activityactive' : 'activityinactive', null, $lang);
                    $to = $this->localized_string($row['active'] ? 'activityactive' : 'activityinactive', null, $lang);
                    $parts[] = $from . ' → ' . $to;
                    $changes[] = [
                        'field' => 'active',
                        'from' => (int)$old['active'],
                        'to' => (int)$row['active'],
                        'from_text' => $from,
                        'to_text' => $to,
                    ];
                }
            }

            if (empty($changes)) {
                continue;
            }
            $changed[] = [
                'id' => (int)$id,
                'userid' => (int)$row['userid'],
                'fullname' => $this->fullname((int)$row['userid']),
                'ruleid' => (int)$row['ruleid'],
                'rulename' => (string)$row['rulename'],
                'status' => (int)$row['status'],
                'statuslabel' => $this->status_label((int)$row['status'], $lang),
                'duedate' => (int)$row['duedate'],
                'active' => (bool)$row['active'],
                'overduecounter' => (int)$row['overduecounter'],
                'prolongedcounter' => (int)$row['prolongedcounter'],
                'changes' => $changes,
                'change' => implode(' · ', $parts),
                'url' => taskflow_result_link_builder::assignment_url((int)$id),
            ];
        }

        // Assignments that vanished are reported as well, so the result stays faithful.
        foreach ($before as $id => $row) {
            if (isset($after[$id])) {
                continue;
            }
            $changed[] = [
                'id' => (int)$id,
                'userid' => (int)$row['userid'],
                'fullname' => $this->fullname((int)$row['userid']),
                'ruleid' => (int)$row['ruleid'],
                'rulename' => (string)$row['rulename'],
                'status' => (int)$row['status'],
                'statuslabel' => $this->status_label((int)$row['status'], $lang),
                'duedate' => (int)$row['duedate'],
                'active' => false,
                'overduecounter' => (int)$row['overduecounter'],
                'prolongedcounter' => (int)$row['prolongedcounter'],
                'changes' => [['field' => 'assignment', 'from' => (int)$id, 'to' => null]],
                'change' => $this->localized_string('agent_recheck_change_removed', null, $lang),
                'url' => taskflow_result_link_builder::assignment_url((int)$id),
            ];
        }

        return $changed;
    }

    /**
     * Unit and rule names in scope, for the confirmation rows.
     *
     * @param array<int,array<string,mixed>> $snapshot
     * @return array{units:string,rules:string}
     */
    private function scope_facts(array $snapshot): array {
        $units = [];
        $rules = [];
        foreach ($snapshot as $row) {
            $unitid = (int)($row['unitid'] ?? 0);
            if ($unitid > 0 && !isset($units[$unitid])) {
                $name = $this->unit_name($unitid);
                $units[$unitid] = $name !== '' ? $name : '#' . $unitid;
            }
            $ruleid = (int)($row['ruleid'] ?? 0);
            if ($ruleid > 0 && !isset($rules[$ruleid])) {
                $name = trim((string)($row['rulename'] ?? ''));
                $rules[$ruleid] = $name !== '' ? '#' . $ruleid . ' ' . $name : '#' . $ruleid;
            }
        }
        return ['units' => implode(', ', $units), 'rules' => implode(', ', $rules)];
    }

    /**
     * Whether the acting user may re-check the assignments of the target user.
     *
     * Admin = local/taskflow:editassignment in the system context; supervisors and the assignees
     * themselves reach the same action on the assignment page, so the resolver decides.
     *
     * @param int $targetuserid
     * @param int $userid
     * @return bool
     */
    private function may_recheck(int $targetuserid, int $userid): bool {
        $scope = $this->permissions()->scope_for_user($targetuserid, $userid);
        if ($scope === taskflow_permission_resolver::SCOPE_NONE) {
            return false;
        }
        if ($scope === taskflow_permission_resolver::SCOPE_ADMIN) {
            return $this->permissions()->is_admin($userid, self::CAPABILITY);
        }
        return true;
    }

    /**
     * Full name of a user ('' when unknown).
     *
     * @param int $targetuserid
     * @return string
     */
    private function fullname(int $targetuserid): string {
        $user = \core_user::get_user($targetuserid, '*', IGNORE_MISSING);
        return $user ? fullname($user) : '';
    }

    /**
     * Localized date of a timestamp ('-' when empty).
     *
     * @param int $timestamp
     * @return string
     */
    private function format_date(int $timestamp): string {
        return $timestamp > 0 ? userdate($timestamp, get_string('strftimedatefullshort', 'core_langconfig')) : '-';
    }

    /**
     * Name of an organisational unit ('' when unresolvable).
     *
     * @param int $unitid
     * @return string
     */
    private function unit_name(int $unitid): string {
        try {
            $unit = organisational_unit_factory::instance($unitid);
        } catch (\Throwable $e) {
            return '';
        }
        return is_object($unit) && method_exists($unit, 'get_name') ? (string)$unit->get_name() : '';
    }
}
