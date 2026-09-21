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

use local_taskflow\local\assignment_process\longleave_facade;
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\wizard\engine\queue_identity_provider_interface;
use local_taskflow\local\wizard\engine\skill_risk_class;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\taskflow_input_normalizer;
use local_taskflow\local\wizard\taskflow\taskflow_permission_resolver;
use local_taskflow\local\wizard\taskflow\taskflow_result_link_builder;
use local_taskflow\local\wizard\taskflow\taskflow_settings_catalog;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;

/**
 * Skill local_taskflow.pause_or_resume_assignments (implementation plan §2 #25).
 *
 * R2. Pauses or resumes every assignment of one person by calling the existing long-leave
 * facade (longleave_facade::longleave_activation() / ::longleave_deactivation()), which is the
 * same entry point the long-leave handling of the adapters uses: it deactivates/reactivates the
 * unit memberships and hands the assignments to assignments_facade::set_all_assignments_inactive()
 * / ::set_all_paused_assignments_active(). No status logic is duplicated here.
 *
 * Because an adapter import writes the long-leave flag from the external profile field, the
 * confirmation preview carries an explicit warning row whenever an external adapter is active.
 * After the call the paused and active assignments are counted again and the result is only
 * 'executed' when the counts confirm the requested direction (plan §3.4).
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pause_or_resume_assignments_skill extends taskflow_skill_base implements queue_identity_provider_interface {
    /** Skill name. */
    public const TASK_NAME = 'local_taskflow.pause_or_resume_assignments';

    /** Capability giving administrative access (supervisors pass through the scope resolver). */
    public const CAPABILITY = taskflow_permission_resolver::CAP_EDIT;

    /** Action: pause all assignments of the person. */
    public const ACTION_PAUSE = 'pause';

    /** Action: resume all paused assignments of the person. */
    public const ACTION_RESUME = 'resume';

    /** Issue code: the action is missing or unknown. */
    public const ISSUE_ACTION_UNKNOWN = 'TASKFLOW_PAUSE_RESUME_ACTION_UNKNOWN';

    /** Issue code: the change awaits the user's confirmation. */
    public const ISSUE_CONFIRM_REQUIRED = 'TASKFLOW_PAUSE_RESUME_CONFIRM_REQUIRED';

    /** Issue code: there is nothing to pause or resume. */
    public const ISSUE_NOTHING_TO_DO = 'TASKFLOW_PAUSE_RESUME_NOTHING_TO_DO';

    /**
     * Constructor: mutating, broad write (all assignments of a person).
     */
    public function __construct() {
        parent::__construct(false, skill_risk_class::R2);
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
            'description' => 'Pause or resume all taskflow assignments of one person (long leave). '
                . 'Pausing sets every assignment to the paused status and deactivates the unit '
                . 'memberships, so due dates do not run on and no reminders are sent; resuming '
                . 'reactivates the memberships, sets the paused assignments back to assigned and '
                . 're-evaluates the rules.',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'Pause all assignments of Anna Muster, she is on parental leave',
                'User 123 is back from long leave - resume the assignments',
                'Put the taskflow assignments of anna.muster@example.org on hold',
            ],
            'properties' => [
                'userid' => [
                    'type' => 'integer',
                    'description' => 'Id of the person whose assignments are paused or resumed.',
                    'required' => false,
                ],
                'userquery' => [
                    'type' => 'string',
                    'description' => 'Name, e-mail or username of the person, when the id is unknown.',
                    'required' => false,
                ],
                'action' => [
                    'type' => 'string',
                    'description' => 'Either ' . self::ACTION_PAUSE . ' or ' . self::ACTION_RESUME . '.',
                    'required' => true,
                ],
            ],
            'required' => ['action'],
        ];
    }

    /**
     * Prompt metadata.
     *
     * @return array<string,mixed>
     */
    protected function prompt_meta(): array {
        return [
            'intent' => 'Pause or resume all taskflow assignments of one person (long leave).',
            'input_fields_for_prompt' => ['userid', 'userquery', 'action'],
            'anchor_fields' => ['userid', 'userquery'],
        ];
    }

    /**
     * Example input.
     *
     * @return array
     */
    public function get_example_input(): array {
        return ['userquery' => 'anna.muster@example.org', 'action' => self::ACTION_PAUSE];
    }

    /**
     * Queue identity: the same action on the same person deduplicates.
     *
     * @param array $input
     * @return array<string,mixed>
     */
    public function build_queue_business_identity(array $input): array {
        return [
            'task_family' => 'taskflow_assignment_longleave',
            'skill' => self::TASK_NAME,
            'target' => [
                'userid' => (int)(taskflow_input_normalizer::to_int($input['userid'] ?? null) ?? 0),
                'userquery' => trim((string)($input['userquery'] ?? '')),
            ],
            'change' => ['action' => $this->resolve_action($input) ?? ''],
        ];
    }

    /**
     * Structural validation.
     *
     * @param array $input
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>}
     */
    public function check_structure(array $input): array {
        $lang = $this->get_output_language($input);
        $errors = [];

        $hasuser = ((int)(taskflow_input_normalizer::to_int($input['userid'] ?? null) ?? 0)) > 0
            || trim((string)($input['userquery'] ?? '')) !== '';
        if (!$hasuser) {
            $errors[] = $this->localized_string('agent_userref_required', null, $lang);
        }
        if ($this->resolve_action($input) === null) {
            $errors[] = $this->localized_string('agent_pause_resume_action_required', (object)[
                'pause' => self::ACTION_PAUSE,
                'resume' => self::ACTION_RESUME,
            ], $lang);
        }
        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => []];
    }

    /**
     * Preflight: resolve person and action, check the scope, then confirm.
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
                    'code' => $this->resolve_action($input) === null
                        ? self::ISSUE_ACTION_UNKNOWN
                        : 'VALIDATION_ERROR',
                    'severity' => 'needs_clarification',
                    'field' => 'action',
                    'message' => (string)$error,
                ];
            }
            return $this->invalid($issues);
        }

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
        if (!$this->may_change($targetuserid, $userid)) {
            return $this->invalid([$this->scope_denied_issue($lang, ['field' => 'userid'])]);
        }

        $action = (string)$this->resolve_action($input);
        $counts = $this->counts($targetuserid, $lang);
        $affected = $action === self::ACTION_PAUSE ? $counts['pausable'] : $counts['paused'];
        if ($affected <= 0) {
            return $this->invalid([[
                'code' => self::ISSUE_NOTHING_TO_DO,
                'severity' => 'needs_clarification',
                'field' => 'action',
                'message' => $this->localized_string('agent_pause_resume_nothing', (object)[
                    'fullname' => $this->fullname($targetuserid),
                    'action' => $action,
                ], $lang),
            ]]);
        }

        $prepared = $input;
        $prepared['userid'] = $targetuserid;
        $prepared['action'] = $action;
        unset($prepared['userquery']);

        return $this->confirmable($prepared, [[
            'code' => self::ISSUE_CONFIRM_REQUIRED,
            'severity' => 'needs_confirmation',
            'user_question' => $this->localized_string(
                $action === self::ACTION_PAUSE ? 'agent_pause_resume_confirm_pause' : 'agent_pause_resume_confirm_resume',
                (object)['fullname' => $this->fullname($targetuserid), 'affected' => $affected],
                $lang
            ),
        ]]);
    }

    /**
     * Tier-3 confirmation preview: person, action, affected assignments and the adapter warning.
     *
     * @param array $input Prepared input.
     * @return array{title:string,summary:string,rows:array[]}|null
     */
    public function describe_proposed_action(array $input): ?array {
        $lang = $this->get_output_language($input);
        $targetuserid = (int)(taskflow_input_normalizer::to_int($input['userid'] ?? null) ?? 0);
        $action = $this->resolve_action($input);
        if ($targetuserid <= 0 || $action === null) {
            return null;
        }
        $fullname = $this->fullname($targetuserid);
        if ($fullname === '') {
            return null;
        }

        $counts = $this->counts($targetuserid, $lang);
        $affected = $action === self::ACTION_PAUSE ? $counts['pausable'] : $counts['paused'];
        $ispause = $action === self::ACTION_PAUSE;

        $rows = [
            [
                'label' => $this->localized_string('fullname', null, $lang),
                'value' => $fullname,
            ],
            [
                'label' => $this->localized_string('agent_pause_resume_row_action', null, $lang),
                'value' => $this->localized_string(
                    $ispause ? 'agent_pause_resume_action_pause' : 'agent_pause_resume_action_resume',
                    null,
                    $lang
                ),
            ],
            [
                'label' => $this->localized_string('agent_pause_resume_row_affected', null, $lang),
                'value' => (string)$affected,
            ],
            [
                'label' => $this->localized_string('agent_preview_assignments', null, $lang),
                'value' => $counts['breakdown'] !== ''
                    ? $counts['breakdown']
                    : $this->localized_string('agent_preview_none', null, $lang),
            ],
        ];
        if ($counts['rules'] !== '') {
            $rows[] = [
                'label' => $this->localized_string('agent_preview_rules', null, $lang),
                'value' => $counts['rules'],
            ];
        }

        $adapter = taskflow_settings_catalog::active_adapter();
        if ($adapter !== 'standard') {
            $rows[] = [
                'label' => $this->localized_string('agent_preview_warning', null, $lang),
                'value' => $this->localized_string('agent_pause_resume_warning_adapter', $adapter, $lang),
            ];
        }

        return [
            'title' => $this->localized_string(
                $ispause ? 'agent_pause_resume_title_pause' : 'agent_pause_resume_title_resume',
                (object)['fullname' => $fullname, 'affected' => $affected],
                $lang
            ),
            'summary' => $this->localized_string(
                $ispause ? 'agent_pause_resume_summary_pause' : 'agent_pause_resume_summary_resume',
                (object)['fullname' => $fullname, 'affected' => $affected],
                $lang
            ),
            'rows' => $rows,
        ];
    }

    /**
     * Execute: call the long-leave facade and verify the resulting status counts.
     *
     * @param array $input Prepared input.
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        $lang = $this->get_output_language($input);
        $debug = $this->build_task_debug_message(self::TASK_NAME, $input);

        $action = $this->resolve_action($input);
        if ($action === null) {
            return $this->error_result(
                self::ISSUE_ACTION_UNKNOWN,
                $this->localized_string('agent_pause_resume_action_required', (object)[
                    'pause' => self::ACTION_PAUSE,
                    'resume' => self::ACTION_RESUME,
                ], $lang),
                ['debugmessage' => $debug]
            );
        }
        $targetuserid = (int)(taskflow_input_normalizer::to_int($input['userid'] ?? null) ?? 0);
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
        if (!$this->may_change($targetuserid, $userid)) {
            return $this->error_result(
                self::ISSUE_SCOPE_DENIED,
                $this->localized_string('agent_scope_denied', null, $lang),
                ['debugmessage' => $debug]
            );
        }

        $ispause = $action === self::ACTION_PAUSE;
        $before = $this->snapshot($targetuserid);
        $countsbefore = $this->counts($targetuserid, $lang);

        if ($ispause) {
            longleave_facade::longleave_activation($targetuserid);
        } else {
            longleave_facade::longleave_deactivation($targetuserid);
        }

        $after = $this->snapshot($targetuserid);
        $countsafter = $this->counts($targetuserid, $lang);
        $rows = $this->change_rows($before, $after, $lang);
        $fullname = $this->fullname($targetuserid);

        $usermessage = $this->localized_string(
            $ispause ? 'agent_pause_resume_done_pause' : 'agent_pause_resume_done_resume',
            (object)[
                'fullname' => $fullname,
                'changed' => count($rows),
                'paused' => $countsafter['paused'],
                'active' => $countsafter['pausable'],
            ],
            $lang
        );

        $links = $this->links(
            taskflow_result_link_builder::dashboard_url(),
            ['assignments_status_lifecycle', 'adapters'],
            ['user' => taskflow_result_link_builder::user_url($targetuserid)]
        );

        $observation = [
            $usermessage,
            '',
            'User: ' . $fullname . ' (' . $targetuserid . ')',
            'Action: ' . $action,
            'Before: paused=' . $countsbefore['paused'] . ', not paused=' . $countsbefore['pausable']
                . ', total=' . $countsbefore['total'],
            'After: paused=' . $countsafter['paused'] . ', not paused=' . $countsafter['pausable']
                . ', total=' . $countsafter['total'],
            'Adapter: ' . taskflow_settings_catalog::active_adapter(),
        ];
        foreach ($rows as $row) {
            $observation[] = sprintf(
                '#%d %s (rule %d): %s',
                (int)$row['id'],
                (string)$row['rulename'],
                (int)$row['ruleid'],
                (string)$row['change']
            );
        }

        // Verification: pausing must leave no pausable assignment, resuming no paused one.
        $expected = $ispause ? ['pausable' => 0] : ['paused' => 0];
        $fields = [
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'observation_full' => implode("\n", $observation),
            'resultid' => $targetuserid,
            'userid' => $targetuserid,
            'fullname' => $fullname,
            'action' => $action,
            'counts_before' => [
                'paused' => $countsbefore['paused'],
                'active' => $countsbefore['pausable'],
                'total' => $countsbefore['total'],
            ],
            'counts_after' => [
                'paused' => $countsafter['paused'],
                'active' => $countsafter['pausable'],
                'total' => $countsafter['total'],
            ],
            'changed' => $rows,
            'changed_total' => count($rows),
            'adapter' => taskflow_settings_catalog::active_adapter(),
            'links' => $links,
            'outputlang' => $lang,
            'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, [
                'Target user: ' . $targetuserid,
                'Action: ' . $action,
                'Paused after: ' . $countsafter['paused'] . ', not paused after: ' . $countsafter['pausable'],
            ]),
            'preview' => [
                'type' => taskflow_preview_renderer_factory::TYPE_ASSIGNMENT_LIST,
                'data' => [
                    'assignments' => $rows,
                    'total' => count($rows),
                    'filters' => [$this->localized_string(
                        $ispause ? 'agent_pause_resume_action_pause' : 'agent_pause_resume_action_resume',
                        null,
                        $lang
                    )],
                ],
                'payload' => [
                    'assignmentids' => array_values(array_map(
                        static fn(array $row): int => (int)$row['id'],
                        $rows
                    )),
                    'userids' => [$targetuserid],
                ],
            ],
        ];

        return $this->verified_result(
            $expected,
            fn(): array => $this->counts($targetuserid, $lang),
            $fields,
            $lang
        );
    }

    /**
     * Canonical action name of the input, or null when it names neither direction.
     *
     * @param array $input
     * @return string|null
     */
    private function resolve_action(array $input): ?string {
        $value = \core_text::strtolower(trim((string)($input['action'] ?? '')));
        if ($value === self::ACTION_PAUSE || $value === self::ACTION_RESUME) {
            return $value;
        }
        return null;
    }

    /**
     * Assignment counts of a user: total, paused and everything else, plus readable summaries.
     *
     * @param int $targetuserid
     * @param string $lang
     * @return array{total:int,paused:int,pausable:int,breakdown:string,rules:string}
     */
    private function counts(int $targetuserid, string $lang): array {
        global $DB;

        $pausedstatus = (int)assignment_status_facade::get_status_identifier('paused');
        $rows = $DB->get_records_sql(
            "SELECT a.id, a.status, a.ruleid, r.rulename
               FROM {local_taskflow_assignment} a
          LEFT JOIN {local_taskflow_rules} r ON r.id = a.ruleid
              WHERE a.userid = :userid
           ORDER BY a.id",
            ['userid' => $targetuserid]
        );

        $total = 0;
        $paused = 0;
        $bystatus = [];
        $rules = [];
        foreach ($rows as $row) {
            $total++;
            $status = (int)$row->status;
            if ($status === $pausedstatus) {
                $paused++;
            }
            $bystatus[$status] = (int)($bystatus[$status] ?? 0) + 1;
            $ruleid = (int)$row->ruleid;
            if ($ruleid > 0 && !isset($rules[$ruleid])) {
                $name = trim((string)($row->rulename ?? ''));
                $rules[$ruleid] = $name !== '' ? $name : '#' . $ruleid;
            }
        }

        $parts = [];
        foreach ($bystatus as $status => $count) {
            $parts[] = $count . ' × ' . $this->status_label((int)$status, $lang);
        }

        return [
            'total' => $total,
            'paused' => $paused,
            'pausable' => $total - $paused,
            'breakdown' => implode(', ', $parts),
            'rules' => implode(', ', array_slice($rules, 0, 10)),
        ];
    }

    /**
     * Assignments of a user keyed by id, with the fields the change rows compare.
     *
     * @param int $targetuserid
     * @return array<int,array<string,mixed>>
     */
    private function snapshot(int $targetuserid): array {
        global $DB;

        $records = $DB->get_records_sql(
            "SELECT a.id, a.userid, a.ruleid, a.status, a.duedate, a.active,
                    a.overduecounter, a.prolongedcounter, r.rulename
               FROM {local_taskflow_assignment} a
          LEFT JOIN {local_taskflow_rules} r ON r.id = a.ruleid
              WHERE a.userid = :userid
           ORDER BY a.id",
            ['userid' => $targetuserid]
        );

        $rows = [];
        foreach ($records as $record) {
            $rows[(int)$record->id] = [
                'id' => (int)$record->id,
                'userid' => (int)$record->userid,
                'ruleid' => (int)$record->ruleid,
                'rulename' => (string)($record->rulename ?? ''),
                'status' => (int)$record->status,
                'duedate' => (int)$record->duedate,
                'active' => (int)$record->active,
                'overduecounter' => (int)$record->overduecounter,
                'prolongedcounter' => (int)$record->prolongedcounter,
            ];
        }
        return $rows;
    }

    /**
     * Assignments whose status or active flag moved, as preview/result rows.
     *
     * @param array<int,array<string,mixed>> $before
     * @param array<int,array<string,mixed>> $after
     * @param string $lang
     * @return array<int,array<string,mixed>>
     */
    private function change_rows(array $before, array $after, string $lang): array {
        $rows = [];
        foreach ($after as $id => $row) {
            $old = $before[$id] ?? null;
            if ($old === null) {
                continue;
            }
            $parts = [];
            if ((int)$old['status'] !== (int)$row['status']) {
                $parts[] = $this->status_label((int)$old['status'], $lang)
                    . ' → ' . $this->status_label((int)$row['status'], $lang);
            }
            if ((int)$old['active'] !== (int)$row['active']) {
                $parts[] = $this->localized_string($old['active'] ? 'activityactive' : 'activityinactive', null, $lang)
                    . ' → ' . $this->localized_string($row['active'] ? 'activityactive' : 'activityinactive', null, $lang);
            }
            if (empty($parts)) {
                continue;
            }
            $rows[] = [
                'id' => (int)$id,
                'userid' => (int)$row['userid'],
                'fullname' => $this->fullname((int)$row['userid']),
                'ruleid' => (int)$row['ruleid'],
                'rulename' => (string)$row['rulename'],
                'status' => (int)$row['status'],
                'statuslabel' => $this->status_label((int)$row['status'], $lang),
                'status_before' => (int)$old['status'],
                'duedate' => (int)$row['duedate'],
                'active' => (bool)$row['active'],
                'overduecounter' => (int)$row['overduecounter'],
                'prolongedcounter' => (int)$row['prolongedcounter'],
                'change' => implode(' · ', $parts),
                'url' => taskflow_result_link_builder::assignment_url((int)$id),
            ];
        }
        return $rows;
    }

    /**
     * Whether the acting user may pause or resume the assignments of the target user.
     *
     * Long leave is an administrative or supervisor action; the assignees themselves may not
     * pause their own assignments, so SCOPE_SELF is not sufficient here.
     *
     * @param int $targetuserid
     * @param int $userid
     * @return bool
     */
    private function may_change(int $targetuserid, int $userid): bool {
        if ($this->permissions()->is_admin($userid, self::CAPABILITY)) {
            return true;
        }
        return $this->permissions()->is_supervisor_of($targetuserid, $userid);
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
}
