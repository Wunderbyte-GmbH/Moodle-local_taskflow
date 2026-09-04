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
use local_taskflow\event\rule_created_updated;
use local_taskflow\local\wizard\engine\queue_identity_provider_interface;
use local_taskflow\local\wizard\engine\skill_risk_class;
use local_taskflow\local\wizard\taskflow\taskflow_input_normalizer;
use local_taskflow\local\wizard\taskflow\taskflow_result_link_builder;
use local_taskflow\local\wizard\taskflow\taskflow_rule_builder;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;

/**
 * Skill local_taskflow.reschedule_rule: re-roll one rule (implementation plan §2 #21).
 *
 * R1, capability local/taskflow:createrules. Nothing is changed on the rule itself: the skill
 * triggers exactly the event the scheduled task local_taskflow\task\reschedule_rules fires per
 * rule (rule_created_updated with the database row as other.ruledata), whose event handler queues
 * the adhoc task local_taskflow\task\update_rule. The result is therefore 'queued' with the adhoc
 * task row as evidence — it never claims that assignments were created or updated.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reschedule_rule_skill extends taskflow_skill_base implements queue_identity_provider_interface {
    /** Skill name. */
    public const TASK_NAME = 'local_taskflow.reschedule_rule';

    /** Native capability required to roll out rules. */
    public const CAPABILITY = 'local/taskflow:createrules';

    /** Issue code: the roll-out needs an explicit confirmation. */
    public const ISSUE_CONFIRM_REQUIRED = 'TASKFLOW_RESCHEDULE_RULE_CONFIRM_REQUIRED';

    /** Issue code: the event fired but no adhoc task was queued. */
    public const ISSUE_TASK_NOT_QUEUED = 'TASKFLOW_UPDATE_RULE_NOT_QUEUED';

    /**
     * Constructor: mutating (queues work), scoped write, guarded by local/taskflow:createrules.
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
            'description' => 'Re-run one taskflow rule now: fires the same event the nightly '
                . 'reschedule_rules task fires, so an adhoc task re-checks all members of the rule '
                . 'scope at the next cron run and creates the assignments that are missing. The rule '
                . 'itself is not changed and nothing is assigned immediately.',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'Roll out taskflow rule 17 again',
                'Re-evaluate rule 17 for all members of the unit',
                'The new colleagues did not get their assignment from rule 21 - run it again',
                'Trigger rule 17 now instead of waiting for the nightly task',
            ],
            'properties' => [
                'ruleid' => [
                    'type' => 'integer',
                    'description' => 'Id of the rule to roll out again (use search_rules to find it).',
                    'required' => true,
                ],
            ],
            'required' => ['ruleid'],
        ];
    }

    /**
     * Prompt metadata.
     *
     * @return array<string,mixed>
     */
    protected function prompt_meta(): array {
        return [
            'intent' => 'Queue a fresh roll-out of one taskflow rule without changing it.',
            'input_fields_for_prompt' => ['ruleid'],
            'anchor_fields' => ['ruleid'],
        ];
    }

    /**
     * Example input.
     *
     * @return array
     */
    public function get_example_input(): array {
        return ['ruleid' => 17];
    }

    /**
     * Queue identity: roll-outs of the same rule deduplicate.
     *
     * @param array $input
     * @return array
     */
    public function build_queue_business_identity(array $input): array {
        return [
            'task_family' => create_rule_skill::QUEUE_TASK_FAMILY,
            'skill' => self::TASK_NAME,
            'ruleid' => (int)(taskflow_input_normalizer::to_int($input['ruleid'] ?? null) ?? 0),
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
        $ruleid = taskflow_input_normalizer::to_int($input['ruleid'] ?? null);
        if ($ruleid === null || $ruleid <= 0) {
            $errors[] = $this->localized_string('agent_invalid_ruleid', null, $this->get_output_language($input));
        }
        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => []];
    }

    /**
     * Preflight: capability, rule existence, then confirmation.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array{status:string,prepared_input:array,issues:array}
     */
    protected function run_preflight(array $input, int $contextid, int $userid): array {
        $lang = $this->get_output_language($input);
        if (!has_capability(self::CAPABILITY, context_system::instance(), $userid)) {
            return $this->invalid([$this->scope_denied_issue($lang, ['field' => 'ruleid'])]);
        }

        $structure = $this->check_structure($input);
        if (!($structure['valid'] ?? false)) {
            $issues = [];
            foreach ((array)($structure['errors'] ?? []) as $error) {
                $issues[] = [
                    'code' => 'VALIDATION_ERROR',
                    'severity' => 'needs_clarification',
                    'field' => 'ruleid',
                    'message' => (string)$error,
                ];
            }
            return $this->invalid($issues);
        }

        $ruleid = (int)taskflow_input_normalizer::to_int($input['ruleid']);
        if (empty($this->resolve_rule($ruleid))) {
            return $this->invalid([
                $this->not_found_issue(
                    self::ISSUE_RULE_NOT_FOUND,
                    $this->localized_string('agent_notfound_rule', $ruleid, $lang),
                    ['field' => 'ruleid']
                ),
            ]);
        }

        $input['ruleid'] = $ruleid;
        $facts = $this->facts($ruleid, $lang);
        return $this->confirmable($input, [[
            'code' => self::ISSUE_CONFIRM_REQUIRED,
            'severity' => 'needs_confirmation',
            'user_question' => $this->localized_string('agent_reschedule_rule_confirm', (object)[
                'id' => $ruleid,
                'name' => $facts['name'],
                'members' => $facts['members'],
            ], $lang),
        ]]);
    }

    /**
     * Tier-3 confirmation preview: what the roll-out does and what it does not do.
     *
     * @param array $input Prepared input.
     * @return array{title:string,summary:string,rows:array[]}|null
     */
    public function describe_proposed_action(array $input): ?array {
        $lang = $this->get_output_language($input);
        $ruleid = (int)(taskflow_input_normalizer::to_int($input['ruleid'] ?? null) ?? 0);
        if ($ruleid <= 0) {
            return null;
        }
        $facts = $this->facts($ruleid, $lang);
        if ($facts['name'] === '' && $facts['scope'] === '') {
            return null;
        }

        return [
            'title' => $this->localized_string('agent_reschedule_rule_title', (object)[
                'id' => $ruleid,
                'name' => $facts['name'],
            ], $lang),
            'summary' => $this->localized_string('agent_reschedule_rule_summary', (object)[
                'members' => $facts['members'],
                'scope' => $facts['scope'],
                'task' => create_rule_skill::UPDATE_RULE_TASK,
            ], $lang),
            'rows' => [
                [
                    'label' => $this->localized_string('agent_rule_row_rule', null, $lang),
                    'value' => '#' . $ruleid . ' ' . $facts['name'],
                ],
                [
                    'label' => $this->localized_string('agent_rule_row_targetgroup', null, $lang),
                    'value' => $facts['scope'],
                ],
                [
                    'label' => $this->localized_string('agent_preview_members', null, $lang),
                    'value' => (string)$facts['members'],
                ],
                [
                    'label' => $this->localized_string('agent_rule_row_assignments', null, $lang),
                    'value' => (string)$facts['assignments'],
                ],
                [
                    'label' => $this->localized_string('agent_rule_row_active', null, $lang),
                    'value' => $facts['isactive'] ? get_string('yes') : get_string('no'),
                ],
            ],
        ];
    }

    /**
     * Execute: fire rule_created_updated exactly like task\reschedule_rules and report the queue.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        global $DB;

        $lang = $this->get_output_language($input);
        if (!has_capability(self::CAPABILITY, context_system::instance(), $userid)) {
            return $this->error_result(
                self::ISSUE_SCOPE_DENIED,
                $this->localized_string('agent_scope_denied', null, $lang),
                ['links' => $this->links(null, ['rules', 'scheduled_tasks'])]
            );
        }

        $ruleid = (int)(taskflow_input_normalizer::to_int($input['ruleid'] ?? null) ?? 0);
        $record = $ruleid > 0 ? $DB->get_record('local_taskflow_rules', ['id' => $ruleid]) : false;
        if (!$record) {
            return $this->error_result(
                self::ISSUE_RULE_NOT_FOUND,
                $this->localized_string('agent_notfound_rule', $ruleid, $lang),
                ['links' => $this->links(taskflow_result_link_builder::dashboard_url(), ['rules'])]
            );
        }

        // Identical to local_taskflow\task\reschedule_rules::execute(): the database row is handed to
        // the event, whose handler queues the update_rule adhoc task with that row as custom data.
        rule_created_updated::create([
            'objectid' => $record->id,
            'context' => context_system::instance(),
            'other' => [
                'ruledata' => $record,
            ],
        ])->trigger();

        $facts = $this->facts($ruleid, $lang);
        $tasks = $this->pending_tasks($ruleid);
        $links = $this->links(
            taskflow_result_link_builder::edit_rule_url($ruleid),
            ['rules', 'scheduled_tasks'],
            ['dashboard' => taskflow_result_link_builder::dashboard_url()]
        );

        if (empty($tasks)) {
            return $this->error_result(
                self::ISSUE_TASK_NOT_QUEUED,
                $this->localized_string('agent_reschedule_rule_notqueued', $ruleid, $lang),
                ['links' => $links, 'resultid' => $ruleid]
            );
        }

        $usermessage = $this->localized_string('agent_reschedule_rule_done', (object)[
            'id' => $ruleid,
            'name' => $facts['name'],
            'members' => $facts['members'],
            'task' => create_rule_skill::UPDATE_RULE_TASK,
        ], $lang);

        $details = create_rule_skill::rule_details($ruleid, $lang, $userid);
        $result = $this->base_result(self::STATUS_QUEUED, [
            'rule' => (array)($details['rule'] ?? []),
            'targets' => (array)($details['targets'] ?? []),
            'filters' => (array)($details['filters'] ?? []),
            'assignments_total' => $facts['assignments'],
            'members' => $facts['members'],
            'queued_effects' => [[
                'task' => create_rule_skill::UPDATE_RULE_TASK,
                'status' => self::STATUS_QUEUED,
                'taskids' => $tasks,
                'detail' => $this->localized_string('agent_rule_queued_update', (object)[
                    'task' => create_rule_skill::UPDATE_RULE_TASK,
                    'affected' => $facts['members'],
                ], $lang),
            ]],
            'pending_update_rule_tasks' => count($tasks),
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'observation_full' => implode("\n", [
                $usermessage,
                '',
                'Rule: #' . $ruleid . ' ' . $facts['name'],
                'Target group: ' . $facts['scope'],
                'Members in scope: ' . $facts['members'],
                'Existing assignments: ' . $facts['assignments'],
                'Queued adhoc tasks (' . create_rule_skill::UPDATE_RULE_TASK . '): ' . implode(', ', $tasks),
                'Nothing has been assigned yet - the task runs at the next cron run.',
            ]),
            'resultid' => $ruleid,
            'links' => $links,
            'outputlang' => $lang,
            'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, [
                'Queued task ids: ' . implode(', ', $tasks),
            ]),
        ]);

        if (!empty($details['preview']['type'])) {
            $result['preview'] = [
                'type' => (string)$details['preview']['type'],
                'data' => (array)($details['preview']['data'] ?? []),
                'payload' => ['ruleids' => [$ruleid]],
            ];
        }

        return $result;
    }

    /**
     * Read-only facts about the rule used by preview and result.
     *
     * @param int $ruleid
     * @param string $lang
     * @return array{name:string,scope:string,members:int,assignments:int,isactive:bool}
     */
    private function facts(int $ruleid, string $lang): array {
        global $DB;

        $resolved = $this->resolve_rule($ruleid);
        if (empty($resolved)) {
            return ['name' => '', 'scope' => '', 'members' => 0, 'assignments' => 0, 'isactive' => false];
        }
        $builder = new taskflow_rule_builder($lang);
        $rule = taskflow_rule_builder::from_stored($resolved);
        $dryrun = $builder->affected_users($rule, (string)$resolved['rulejson']);

        return [
            'name' => (string)$rule['name'],
            'scope' => $builder->scope_text($rule),
            'members' => (int)$dryrun['members'],
            'assignments' => (int)$DB->count_records('local_taskflow_assignment', ['ruleid' => $ruleid]),
            'isactive' => (bool)$resolved['isactive'],
        ];
    }

    /**
     * Ids of the queued update_rule adhoc tasks of this rule (the evidence of the roll-out).
     *
     * @param int $ruleid
     * @return int[]
     */
    private function pending_tasks(int $ruleid): array {
        global $DB;

        $rows = $DB->get_records_list(
            'task_adhoc',
            'classname',
            ['\\' . create_rule_skill::UPDATE_RULE_TASK, create_rule_skill::UPDATE_RULE_TASK],
            'id',
            'id, customdata'
        );

        $ids = [];
        foreach ($rows as $row) {
            $data = json_decode((string)$row->customdata, true);
            if (is_array($data) && (int)($data['id'] ?? 0) === $ruleid) {
                $ids[] = (int)$row->id;
            }
        }
        return $ids;
    }
}
