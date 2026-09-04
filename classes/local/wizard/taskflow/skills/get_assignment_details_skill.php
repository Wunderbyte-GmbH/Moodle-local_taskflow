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

use local_taskflow\local\actions\targets\targets_factory;
use local_taskflow\local\competencies\assignment_competency;
use local_taskflow\local\history\history;
use local_taskflow\local\internal_messages\internal_messages;
use local_taskflow\local\requests;
use local_taskflow\local\supervisor\supervisor;
use local_taskflow\local\wizard\engine\observation_time;
use local_taskflow\local\wizard\engine\skill_risk_class;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\taskflow_input_normalizer;
use local_taskflow\local\wizard\taskflow\taskflow_permission_resolver;
use local_taskflow\local\wizard\taskflow\taskflow_result_link_builder;
use local_taskflow\local\wizard\taskflow\taskflow_settings_catalog;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use moodle_url;
use stdClass;

/**
 * Read-only skill local_taskflow.get_assignment_details (implementation plan §2 #6).
 *
 * Full picture of one assignment: record fields with localized status, supervisor, targets
 * with completion state and names, open requests, recent history, internal chat preview
 * (only when internal communication is enabled), pending adhoc tasks touching the assignment
 * and the adapter flags in effect. Scope is resolved like assignment.php (admin > supervisor/
 * deputy > assignee) through taskflow_permission_resolver.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_assignment_details_skill extends taskflow_skill_base {
    /** Skill name. */
    public const TASK_NAME = 'local_taskflow.get_assignment_details';

    /** Default number of history entries. */
    public const DEFAULT_HISTORY_LIMIT = 10;

    /** Upper bound of history entries. */
    public const MAX_HISTORY_LIMIT = 100;

    /** Number of chat messages in the preview. */
    public const CHAT_PREVIEW_COUNT = 3;

    /** Adhoc task classes (local_taskflow\task\*) that address a single assignment. */
    public const ASSIGNMENT_TASKS = [
        'check_assignment_status',
        'reset_cyclic_assignment',
        'open_planned_assignment',
        'send_taskflow_message',
        'update_assignment',
    ];

    /**
     * History type => lang string key (mirrors table\history_table::col_type()).
     *
     * @var array<string,string>
     */
    private const HISTORY_TYPE_STRINGS = [
        history::TYPE_MESSAGE => 'status:messagesent',
        history::TYPE_MANUAL_CHANGE => 'status:manualchange',
        history::TYPE_LIMIT_REACHED => 'status:limitreached',
        history::TYPE_USER_ACTION => 'status:useraction',
        history::TYPE_RULE_CHANGE => 'status:rulechange',
        history::TYPE_STATUS_CHANGED => 'status:statuschanged',
        history::TYPE_COMPETENCY_UPLOAD => 'status:competencyupload',
        history::TYPE_COURSE_COMPLETED => 'status:coursecompleted',
        history::TYPE_COURSE_ENROLLED => 'status:courseenroled',
        history::TYPE_MAIL_SEND => 'status:mailsend',
        history::TYPE_REQUEST_CONFIRMED => 'status:requestconfirmed',
        history::TYPE_REQUEST_DECLINED => 'status:requestdeclined',
    ];

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
            'description' => 'Get the full details of one taskflow assignment: status, due date, counters, supervisor, '
                . 'targets with completion state, open requests, history, chat preview and pending tasks.',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'Show me assignment 4711',
                'What is the state of assignment #4711 and which targets are still open?',
                'Why is assignment 4711 overdue? Show its history',
                'Are there open requests for assignment 4711?',
            ],
            'properties' => [
                'assignmentid' => [
                    'type' => 'integer',
                    'description' => 'Id of the assignment (find it with local_taskflow.search_assignments).',
                    'required' => true,
                ],
                'historylimit' => [
                    'type' => 'integer',
                    'description' => 'Number of most recent history entries (default ' . self::DEFAULT_HISTORY_LIMIT
                        . ', max ' . self::MAX_HISTORY_LIMIT . ').',
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
            'intent' => 'Inspect one identified taskflow assignment in depth.',
            'input_fields_for_prompt' => ['assignmentid'],
            'anchor_fields' => ['assignmentid'],
        ];
    }

    /**
     * Example input for the planner contract.
     *
     * @return array
     */
    public function get_example_input(): array {
        return ['assignmentid' => 4711, 'historylimit' => 10];
    }

    /**
     * Structural check: assignmentid is required.
     *
     * @param array $input
     * @return array{valid:bool,errors:string[],ambiguities:string[]}
     */
    public function check_structure(array $input): array {
        $errors = [];
        $assignmentid = taskflow_input_normalizer::to_int($input['assignmentid'] ?? null) ?? 0;
        if ($assignmentid <= 0) {
            $errors[] = $this->localized_string('agent_assignmentid_required', null, $this->get_output_language($input));
        }
        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => []];
    }

    /**
     * Preflight: assignment must exist and be within scope.
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
                    'field' => 'assignmentid',
                    'message' => (string)$error,
                ];
            }
            return $this->invalid($issues);
        }

        $assignmentid = (int)taskflow_input_normalizer::to_int($input['assignmentid']);
        $assignment = $this->resolve_assignment($input);
        if ($assignment === null) {
            return $this->invalid([
                $this->not_found_issue(
                    self::ISSUE_ASSIGNMENT_NOT_FOUND,
                    $this->localized_string('agent_notfound_assignment', $assignmentid, $lang),
                    ['field' => 'assignmentid']
                ),
            ]);
        }

        $scope = $this->permissions()->scope_for_assignment($assignmentid, $userid);
        if ($scope === taskflow_permission_resolver::SCOPE_NONE) {
            return $this->invalid([$this->scope_denied_issue($lang, ['field' => 'assignmentid'])]);
        }

        $prepared = $input;
        $prepared['assignmentid'] = $assignmentid;
        $historylimit = taskflow_input_normalizer::to_int($input['historylimit'] ?? null) ?? self::DEFAULT_HISTORY_LIMIT;
        $prepared['historylimit'] = max(0, min(self::MAX_HISTORY_LIMIT, $historylimit));
        return $this->pass($prepared);
    }

    /**
     * Execute: assemble the assignment payload.
     *
     * @param array $input Prepared input.
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        global $DB;

        $lang = $this->get_output_language($input);
        $assignmentid = taskflow_input_normalizer::to_int($input['assignmentid'] ?? null) ?? 0;
        $debug = $this->build_task_debug_message(self::TASK_NAME, $input);

        $data = $this->resolve_assignment(['assignmentid' => $assignmentid]);
        if ($data === null) {
            return $this->error_result(
                self::ISSUE_ASSIGNMENT_NOT_FOUND,
                $this->localized_string('agent_notfound_assignment', $assignmentid, $lang),
                ['debugmessage' => $debug]
            );
        }
        $scope = $this->permissions()->scope_for_assignment($assignmentid, $userid);
        if ($scope === taskflow_permission_resolver::SCOPE_NONE) {
            return $this->error_result(
                self::ISSUE_SCOPE_DENIED,
                $this->localized_string('agent_scope_denied', null, $lang),
                ['debugmessage' => $debug]
            );
        }
        $historylimit = max(0, min(
            self::MAX_HISTORY_LIMIT,
            taskflow_input_normalizer::to_int($input['historylimit'] ?? null) ?? self::DEFAULT_HISTORY_LIMIT
        ));

        $now = time();
        $status = (int)$data->status;
        $duedate = (int)($data->duedate ?? 0);
        $supervisor = $this->supervisor_of((int)$data->userid);
        $assigneeuser = \core_user::get_user((int)$data->userid, '*', IGNORE_MISSING);
        $rule = $this->resolve_rule((int)$data->ruleid);
        $canedit = $this->permissions()->can_edit_assignment($assignmentid, $userid);

        $assignment = [
            'id' => (int)$data->id,
            'userid' => (int)$data->userid,
            'fullname' => (string)$data->fullname,
            'email' => $assigneeuser ? (string)$assigneeuser->email : '',
            'ruleid' => (int)$data->ruleid,
            'rulename' => (string)($data->name !== '' ? $data->name : ($rule['rulename'] ?? '')),
            'ruledescription' => (string)$data->ruledescription,
            'ruleexists' => !empty($rule),
            'unitid' => (int)($data->unitid ?? 0),
            'unitname' => $this->unit_name((int)($data->unitid ?? 0)),
            'active' => (bool)$data->active,
            'status' => $status,
            'statuslabel' => $this->status_label($status, $lang),
            'assigneddate' => (int)($data->assigneddate ?? 0),
            'assigneddate_text' => $this->format_time((int)($data->assigneddate ?? 0)),
            'duedate' => $duedate,
            'duedate_text' => $this->format_time($duedate),
            'overdue' => $duedate > 0 && $duedate < $now,
            'overduecounter' => (int)($data->overduecounter ?? 0),
            'prolongedcounter' => (int)($data->prolongedcounter ?? 0),
            'keepchanges' => (bool)($data->keepchanges ?? 0),
            'timecreated' => (int)($data->timecreated ?? 0),
            'timemodified' => (int)($data->timemodified ?? 0),
            'usermodified' => (int)($data->usermodified ?? 0),
            'supervisor' => $supervisor,
            'scope' => $scope,
            'caneditassignment' => $canedit,
        ];

        $targets = $this->build_targets($data);
        $requests = $this->build_requests($data, $lang);
        $history = $historylimit > 0 ? $this->build_history($assignmentid, $historylimit, $lang) : [];
        $chat = $this->build_chat($assignmentid);
        $pendingtasks = $this->build_pending_tasks($assignmentid);
        $adapter = $this->adapter_flags();

        $done = count(array_filter($targets, static fn(array $target): bool => $target['completed']));
        $links = $this->links(
            taskflow_result_link_builder::assignment_url($assignmentid),
            ['assignments_detail_page', 'assignments_status_lifecycle', 'assignments_history'],
            $canedit ? ['edit' => taskflow_result_link_builder::edit_assignment_url($assignmentid)] : []
        );

        $usermessage = $this->localized_string('agent_get_assignment_details_summary', (object)[
            'id' => $assignment['id'],
            'fullname' => $assignment['fullname'],
            'rulename' => $assignment['rulename'],
            'status' => $assignment['statuslabel'],
            'duedate' => $assignment['duedate_text'] !== '' ? $assignment['duedate_text'] : '-',
            'done' => $done,
            'targets' => count($targets),
        ], $lang);

        $observation = [$usermessage];
        $observation[] = $this->localized_string('agent_preview_supervisor', null, $lang) . ': '
            . ($supervisor ? $supervisor['fullname'] . ' (id=' . $supervisor['id'] . ')' : '-');
        $observation[] = sprintf(
            'active=%s, overduecounter=%d, prolongedcounter=%d, keepchanges=%s, assigned=%s',
            $assignment['active'] ? 'yes' : 'no',
            $assignment['overduecounter'],
            $assignment['prolongedcounter'],
            $assignment['keepchanges'] ? 'yes' : 'no',
            $assignment['assigneddate_text'] !== '' ? $assignment['assigneddate_text'] : '-'
        );
        foreach ($targets as $target) {
            $observation[] = sprintf(
                '%s: [%s] %s %s%s',
                $this->localized_string('target', null, $lang),
                $target['completed'] ? 'done' : 'open',
                $target['typelabel'],
                $target['name'] !== '' ? $target['name'] : '#' . $target['targetid'],
                $target['evidence'] !== null ? ' (evidence: ' . $target['evidence']['status'] . ')' : ''
            );
        }
        $observation[] = $this->localized_string('agent_preview_requests', null, $lang) . ': ' . count($requests['open'])
            . ' open / ' . $requests['total'] . ' total';
        foreach ($requests['open'] as $request) {
            $observation[] = sprintf(
                '%s #%d: %s, %s (%s)%s',
                $this->localized_string('request', null, $lang),
                $request['id'],
                $request['typelabel'],
                $request['treatedlabel'],
                $request['timecreated_text'],
                $request['comment'] !== '' ? ' "' . $request['comment'] . '"' : ''
            );
        }
        $observation[] = $this->localized_string('agent_preview_history', null, $lang) . ' (' . count($history) . '):';
        foreach ($history as $entry) {
            $observation[] = sprintf(
                '  %s | %s | %s%s',
                $entry['timecreated_text'],
                $entry['typelabel'],
                $entry['createdbyname'],
                $entry['annotation'] !== '' ? ' | ' . $entry['annotation'] : ''
            );
        }
        if ($chat['enabled']) {
            $observation[] = $this->localized_string('internalcommunication', null, $lang) . ': ' . $chat['total'];
            foreach ($chat['preview'] as $message) {
                $observation[] = '  ' . $message['timecreated_text'] . ' ' . $message['fullname'] . ': ' . $message['text'];
            }
        }
        $observation[] = $this->localized_string('agent_preview_pending_tasks', null, $lang) . ': ' . count($pendingtasks);
        foreach ($pendingtasks as $task) {
            $observation[] = '  ' . $task['name'] . ' (next run ' . $task['nextruntime_text'] . ')';
        }
        $excluded = empty($adapter['excludedstatuses']) ? '-' : implode(',', array_column($adapter['excludedstatuses'], 'label'));
        $observation[] = 'adapter=' . $adapter['name'] . ', usingprolongedstate=' . ($adapter['usingprolongedstate'] ? '1' : '0')
            . ', excludedstatuses=' . $excluded;

        return $this->base_result(self::STATUS_EXECUTED, [
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'observation_full' => implode("\n", $observation),
            'resultid' => $assignmentid,
            'assignment' => $assignment,
            'targets' => $targets,
            'targets_done' => $done,
            'open_requests' => $requests['open'],
            'requests_total' => $requests['total'],
            'history' => $history,
            'chat_enabled' => $chat['enabled'],
            'chat_total' => $chat['total'],
            'chat_preview' => $chat['preview'],
            'pending_tasks' => $pendingtasks,
            'adapter' => $adapter,
            'links' => $links,
            'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, ['Scope: ' . $scope]),
            'preview' => [
                'type' => taskflow_preview_renderer_factory::TYPE_ASSIGNMENT,
                'data' => [
                    'assignment' => $assignment,
                    'targets' => $targets,
                    'open_requests' => $requests['open'],
                    'history' => $history,
                    'chat_enabled' => $chat['enabled'],
                    'chat_total' => $chat['total'],
                    'chat_preview' => $chat['preview'],
                    'pending_tasks' => $pendingtasks,
                    'links' => $links,
                ],
                'payload' => [
                    'assignmentids' => [$assignmentid],
                    'userids' => [(int)$data->userid],
                ],
            ],
        ]);
    }

    /**
     * Supervisor of the assignee (adapter-aware), or null.
     *
     * @param int $userid
     * @return array{id:int,fullname:string,email:string}|null
     */
    private function supervisor_of(int $userid): ?array {
        try {
            $supervisor = supervisor::get_supervisor_for_user($userid);
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_object($supervisor) || empty($supervisor->id)) {
            return null;
        }
        return [
            'id' => (int)$supervisor->id,
            'fullname' => fullname($supervisor),
            'email' => (string)($supervisor->email ?? ''),
        ];
    }

    /**
     * Name of an organisational unit ('' when unknown or backend misconfigured).
     *
     * @param int $unitid
     * @return string
     */
    private function unit_name(int $unitid): string {
        if ($unitid <= 0) {
            return '';
        }
        try {
            $unit = \local_taskflow\local\units\organisational_unit_factory::instance($unitid);
            return is_object($unit) && method_exists($unit, 'get_name') ? (string)$unit->get_name() : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Targets of the assignment with names, completion state and links.
     *
     * @param stdClass $data
     * @return array<int,array<string,mixed>>
     */
    private function build_targets(stdClass $data): array {
        $decoded = json_decode((string)($data->targets ?? ''), true);
        if (!is_array($decoded)) {
            return [];
        }
        $targets = [];
        foreach ($decoded as $target) {
            $target = (array)$target;
            $type = (string)($target['targettype'] ?? '');
            $targetid = (int)($target['targetid'] ?? 0);
            $name = '';
            try {
                $name = (string)targets_factory::get_name($type, $targetid);
            } catch (\Throwable $e) {
                $name = '';
            }
            if ($name === '') {
                $name = (string)($target['targetname'] ?? '');
            }
            $typelabel = get_string_manager()->string_exists($type, 'local_taskflow')
                ? $this->localized_string($type) : $type;
            $completion = (int)($target['completionstatus'] ?? 0);
            $targets[] = [
                'targettype' => $type,
                'typelabel' => $typelabel,
                'targetid' => $targetid,
                'name' => $name,
                'completionstatus' => $completion,
                'completed' => $completion === 1,
                'completebeforenext' => (bool)($target['completebeforenext'] ?? 0),
                'sortorder' => (int)($target['sortorder'] ?? 0),
                'actiontype' => (string)($target['actiontype'] ?? ''),
                'url' => $this->target_url($type, $targetid, (int)$data->userid),
                'evidence' => $type === 'competency' ? $this->competency_evidence((int)$data->userid, $targetid) : null,
            ];
        }
        return $targets;
    }

    /**
     * Deep link of a target (course page, booking option view), '' when not resolvable.
     *
     * @param string $type
     * @param int $targetid
     * @param int $userid
     * @return string
     */
    private function target_url(string $type, int $targetid, int $userid): string {
        if ($targetid <= 0) {
            return '';
        }
        if ($type === 'moodlecourse') {
            return (new moodle_url('/course/view.php', ['id' => $targetid]))->out(false);
        }
        if ($type === 'bookingoption' && class_exists('\\mod_booking\\singleton_service')) {
            try {
                $settings = \mod_booking\singleton_service::get_instance_of_booking_option_settings($targetid);
                if (!empty($settings->id) && !empty($settings->cmid)) {
                    return (new moodle_url('/mod/booking/optionview.php', [
                        'optionid' => (int)$settings->id,
                        'cmid' => (int)$settings->cmid,
                        'userid' => $userid,
                    ]))->out(false);
                }
            } catch (\Throwable $e) {
                return '';
            }
        }
        return '';
    }

    /**
     * Evidence state of a competency target, or null when none was uploaded.
     *
     * @param int $userid
     * @param int $competencyid
     * @return array{status:string,name:string,timecreated:int}|null
     */
    private function competency_evidence(int $userid, int $competencyid): ?array {
        if (!class_exists(assignment_competency::class)) {
            return null;
        }
        try {
            $evidence = assignment_competency::get_with_evidence_by_user_and_competency($userid, $competencyid, true);
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_object($evidence) || empty((array)$evidence)) {
            return null;
        }
        return [
            'status' => (string)($evidence->ac_status ?? ($evidence->status ?? '')),
            'name' => (string)($evidence->evidence_name ?? ''),
            'timecreated' => (int)($evidence->evidence_timecreated ?? ($evidence->timecreated ?? 0)),
        ];
    }

    /**
     * Requests of the assignee filtered to this assignment.
     *
     * @param stdClass $data
     * @param string $lang
     * @return array{open:array<int,array<string,mixed>>,total:int}
     */
    private function build_requests(stdClass $data, string $lang): array {
        try {
            $records = requests::get_by_user((int)$data->userid);
        } catch (\Throwable $e) {
            $records = [];
        }
        $open = [];
        $total = 0;
        foreach ($records as $record) {
            if ((int)($record->assignmentid ?? 0) !== (int)$data->id) {
                continue;
            }
            $total++;
            $treated = (int)($record->treated ?? requests::TREATED_STATUS_UNTREATED);
            if ($treated !== requests::TREATED_STATUS_UNTREATED) {
                continue;
            }
            $open[] = [
                'id' => (int)$record->id,
                'type' => (int)($record->request ?? 0),
                'typelabel' => $this->request_type_label((int)($record->request ?? 0), $lang),
                'treated' => $treated,
                'treatedlabel' => $this->localized_string('open', null, $lang),
                'forhr' => (bool)($record->forhr ?? 0),
                'comment' => trim((string)($record->comment ?? '')),
                'timecreated' => (int)($record->timecreated ?? 0),
                'timecreated_text' => $this->format_time((int)($record->timecreated ?? 0)),
            ];
        }
        return ['open' => $open, 'total' => $total];
    }

    /**
     * Localized request type label in the output language.
     *
     * @param int $type
     * @param string $lang
     * @return string
     */
    private function request_type_label(int $type, string $lang): string {
        $keys = [
            \local_taskflow\local\requests\request_types\types\allowselfnotrelevant::ID => 'notrelevantformedisplayname',
            \local_taskflow\local\requests\request_types\types\allowselfextension::ID => 'requestprolongation',
            \local_taskflow\local\requests\request_types\types\allowuploadevidence::ID => 'requestevidence',
        ];
        return $this->localized_string($keys[$type] ?? 'statusunknown', null, $lang);
    }

    /**
     * Most recent history entries (newest first).
     *
     * @param int $assignmentid
     * @param int $limit
     * @param string $lang
     * @return array<int,array<string,mixed>>
     */
    private function build_history(int $assignmentid, int $limit, string $lang): array {
        global $DB;

        $records = $DB->get_records(
            'local_taskflow_history',
            ['assignmentid' => $assignmentid],
            'timecreated DESC, id DESC',
            '*',
            0,
            $limit
        );
        $names = [];
        $entries = [];
        foreach ($records as $record) {
            $createdby = (int)$record->createdby;
            if (!isset($names[$createdby])) {
                $user = $createdby > 0 ? \core_user::get_user($createdby, '*', IGNORE_MISSING) : null;
                $names[$createdby] = $user ? fullname($user) : '';
            }
            $decoded = json_decode((string)$record->data, true);
            $type = (string)$record->type;
            $entries[] = [
                'id' => (int)$record->id,
                'type' => $type,
                'typelabel' => isset(self::HISTORY_TYPE_STRINGS[$type])
                    ? $this->localized_string(self::HISTORY_TYPE_STRINGS[$type], null, $lang) : $type,
                'data' => is_array($decoded) ? $decoded : [],
                'annotation' => trim((string)($record->annotation ?? '')),
                'createdby' => $createdby,
                'createdbyname' => $names[$createdby],
                'timecreated' => (int)$record->timecreated,
                'timecreated_text' => $this->format_time((int)$record->timecreated),
            ];
        }
        return $entries;
    }

    /**
     * Internal chat: enabled flag, count and a preview of the latest messages.
     *
     * @param int $assignmentid
     * @return array{enabled:bool,total:int,preview:array<int,array<string,mixed>>}
     */
    private function build_chat(int $assignmentid): array {
        $enabled = !empty((int)get_config('local_taskflow', 'allowinternalcommunication'));
        if (!$enabled) {
            return ['enabled' => false, 'total' => 0, 'preview' => []];
        }
        $maxlength = (int)get_config('local_taskflow', 'internalcommunicationpreviewlength');
        if ($maxlength <= 0) {
            $maxlength = 300;
        }
        try {
            $messages = array_values((new internal_messages($assignmentid))->get_all_assignment_messages());
        } catch (\Throwable $e) {
            $messages = [];
        }
        $total = count($messages);
        $preview = [];
        foreach (array_slice($messages, -self::CHAT_PREVIEW_COUNT) as $message) {
            $text = trim(html_to_text((string)($message->message ?? ''), 0, false));
            if (\core_text::strlen($text) > $maxlength) {
                $text = rtrim(\core_text::substr($text, 0, $maxlength)) . '…';
            }
            $preview[] = [
                'id' => (int)$message->id,
                'userid' => (int)($message->usermodified ?? 0),
                'fullname' => trim((string)($message->firstname ?? '') . ' ' . (string)($message->lastname ?? '')),
                'text' => $text,
                'timecreated' => (int)($message->timecreated ?? 0),
                'timecreated_text' => $this->format_time((int)($message->timecreated ?? 0)),
            ];
        }
        return ['enabled' => true, 'total' => $total, 'preview' => array_reverse($preview)];
    }

    /**
     * Pending adhoc tasks of local_taskflow whose customdata addresses this assignment.
     *
     * @param int $assignmentid
     * @return array<int,array<string,mixed>>
     */
    private function build_pending_tasks(int $assignmentid): array {
        global $DB;

        $classnames = array_map(
            static fn(string $name): string => '\\local_taskflow\\task\\' . $name,
            self::ASSIGNMENT_TASKS
        );
        [$insql, $params] = $DB->get_in_or_equal($classnames, SQL_PARAMS_NAMED, 'cls');
        $records = $DB->get_records_select(
            'task_adhoc',
            "classname {$insql}",
            $params,
            'nextruntime ASC, id ASC',
            'id, classname, customdata, nextruntime, timestarted, faildelay'
        );
        $tasks = [];
        foreach ($records as $record) {
            $customdata = json_decode((string)$record->customdata, true);
            if (!is_array($customdata) || (int)($customdata['assignmentid'] ?? 0) !== $assignmentid) {
                continue;
            }
            $tasks[] = [
                'id' => (int)$record->id,
                'classname' => (string)$record->classname,
                'name' => ltrim(substr((string)$record->classname, strrpos((string)$record->classname, '\\')), '\\'),
                'nextruntime' => (int)$record->nextruntime,
                'nextruntime_text' => $this->format_time((int)$record->nextruntime),
                'started' => !empty($record->timestarted),
                'faildelay' => (int)($record->faildelay ?? 0),
                'customdata' => $customdata,
            ];
        }
        return $tasks;
    }

    /**
     * Adapter flags affecting the status model.
     *
     * @return array{name:string,usingprolongedstate:bool,excludedstatuses:array<int,array{id:int,label:string}>}
     */
    private function adapter_flags(): array {
        $adapter = taskflow_settings_catalog::active_adapter();
        $component = 'taskflowadapter_' . $adapter;
        $excluded = [];
        foreach (array_filter(array_map('trim', explode(',', (string)get_config($component, 'excludestatus')))) as $id) {
            if (!is_numeric($id)) {
                continue;
            }
            $excluded[] = ['id' => (int)$id, 'label' => $this->status_label((int)$id)];
        }
        return [
            'name' => $adapter,
            'usingprolongedstate' => !empty(get_config($component, 'usingprolongedstate')),
            'excludedstatuses' => $excluded,
        ];
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
