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
use local_taskflow\local\requests;
use local_taskflow\local\requests\request_receivers\receiver_facade;
use local_taskflow\local\requests\request_receivers\receivers\supervisor_receiver;
use local_taskflow\local\supervisor\supervisor;
use local_taskflow\local\wizard\engine\skill_risk_class;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\taskflow_input_normalizer;
use local_taskflow\local\wizard\taskflow\taskflow_result_link_builder;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;

/**
 * Read-only skill local_taskflow.supervisor_overview (implementation plan §2 #12).
 *
 * Team dashboard of one supervisor: per subordinate the number of open, overdue and
 * completed assignments, the open requests addressed to the supervisor and the unread
 * internal chat messages. The team is resolved with supervisor::get_visible_subordinate_ids()
 * (direct reports plus deputy delegation); status ids come from assignment_status_facade
 * (overdue = the overdue status or an active status with a due date in the past, exactly as
 * in local_taskflow.search_assignments), so no numeric status id is hard-coded.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class supervisor_overview_skill extends taskflow_skill_base {
    /** Skill name. */
    public const TASK_NAME = 'local_taskflow.supervisor_overview';

    /** Capability marking a supervisor (own team). */
    public const CAP_ISSUPERVISOR = 'local/taskflow:issupervisor';

    /** Capability granting the team overview of any supervisor. */
    public const CAP_VIEWREPORTS = 'local/taskflow:viewreports';

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
            'description' => 'Team overview of a supervisor: per subordinate the open, overdue and completed '
                . 'assignments, the open requests addressed to the supervisor and the unread internal chat messages. '
                . 'Without supervisorid the team of the acting user is described.',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'How is my team doing?',
                'Who in my team has overdue assignments?',
                'Show the team overview of Emily Smith',
                'Are there requests waiting for me?',
                'Which of my subordinates wrote me a message?',
            ],
            'properties' => [
                'supervisorid' => [
                    'type' => 'integer',
                    'description' => 'User id of the supervisor. Defaults to the acting user.',
                    'required' => false,
                ],
                'supervisorquery' => [
                    'type' => 'string',
                    'description' => 'Supervisor matching this e-mail, username or name (used when the id is unknown). '
                        . 'A query that matches nobody or several people is rejected, never replaced by the acting user.',
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
            'intent' => 'Summarize the taskflow state of a supervisor team, per person and in total.',
            'input_fields_for_prompt' => ['supervisorid or supervisorquery (omit for the acting user)'],
            'anchor_fields' => ['supervisorid', 'supervisorquery'],
        ];
    }

    /**
     * Example input for the planner contract.
     *
     * @return array
     */
    public function get_example_input(): array {
        return [];
    }

    /**
     * Preflight: resolve the supervisor and check the capability (via resolve_input()).
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
     * Resolve the supervisor and check the scope (shared by preflight and execute).
     *
     * A numeric supervisorid is taken as user id; a non-numeric supervisorid or a
     * supervisorquery is resolved through the user lookup. A person filter that matches
     * nobody or several people is a hard stop and never falls back to the acting user.
     * Without any supervisor input the acting user's own team is described.
     *
     * @param array $input Raw or prepared input.
     * @param int $userid Acting user.
     * @return array{prepared:array,issues:array}
     */
    private function resolve_input(array $input, int $userid): array {
        $lang = $this->get_output_language($input);
        $supervisorid = taskflow_input_normalizer::to_int($input['supervisorid'] ?? null) ?? 0;

        $query = trim((string)($input['supervisorquery'] ?? ''));
        if ($query === '' && $supervisorid <= 0 && is_string($input['supervisorid'] ?? null)) {
            // A non-numeric supervisorid is a person query, not "no supervisor given".
            $query = trim((string)$input['supervisorid']);
        }
        if ($supervisorid <= 0 && $query !== '') {
            $lookup = ['userquery' => $query];
            $supervisorid = $this->resolve_userid($lookup, 0);
            if ($supervisorid <= 0) {
                return ['prepared' => [], 'issues' => [$this->user_lookup_issue($lookup, $lang)]];
            }
        }
        if ($supervisorid <= 0) {
            $supervisorid = $userid;
        }

        $user = $supervisorid > 0 ? \core_user::get_user($supervisorid, '*', IGNORE_MISSING) : null;
        if (!$user || !empty($user->deleted)) {
            return ['prepared' => [], 'issues' => [
                $this->not_found_issue(
                    self::ISSUE_USER_NOT_FOUND,
                    $this->localized_string('agent_user_notfound', (string)$supervisorid, $lang),
                    ['field' => 'supervisorid']
                ),
            ]];
        }

        if (!$this->may_read($supervisorid, $userid)) {
            return ['prepared' => [], 'issues' => [$this->scope_denied_issue($lang, ['field' => 'supervisorid'])]];
        }

        $prepared = $input;
        $prepared['supervisorid'] = $supervisorid;
        unset($prepared['supervisorquery']);
        return ['prepared' => $prepared, 'issues' => []];
    }

    /**
     * Execute: collect the per-subordinate counters.
     *
     * @param array $input Prepared input.
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        $lang = $this->get_output_language($input);
        $debug = $this->build_task_debug_message(self::TASK_NAME, $input);

        // Supervisor resolution and scope recomputed: execute() must be safe without preflight.
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
        $supervisorid = (int)$input['supervisorid'];
        $user = \core_user::get_user($supervisorid, '*', MUST_EXIST);

        $subordinateids = $this->subordinate_ids($supervisorid);
        $assignments = $this->assignment_counters($subordinateids);
        $openrequests = $this->open_request_counters($subordinateids);
        $unreadchats = $this->unread_chat_counters($subordinateids, $supervisorid);
        $names = $this->user_names($subordinateids);

        $rows = [];
        $totals = ['open' => 0, 'overdue' => 0, 'completed' => 0, 'open_requests' => 0, 'unread_chats' => 0];
        foreach ($subordinateids as $subordinateid) {
            $counters = $assignments[$subordinateid] ?? ['open' => 0, 'overdue' => 0, 'completed' => 0];
            $row = [
                'userid' => $subordinateid,
                'fullname' => $names[$subordinateid] ?? (string)$subordinateid,
                'open' => (int)$counters['open'],
                'overdue' => (int)$counters['overdue'],
                'completed' => (int)$counters['completed'],
                'open_requests' => (int)($openrequests[$subordinateid] ?? 0),
                'unread_chats' => (int)($unreadchats[$subordinateid] ?? 0),
                'url' => taskflow_result_link_builder::user_url($subordinateid),
            ];
            foreach (array_keys($totals) as $key) {
                $totals[$key] += (int)$row[$key];
            }
            $rows[] = $row;
        }

        // Overdue first, then by name (deterministic ordering).
        usort($rows, static function (array $a, array $b): int {
            if ($a['overdue'] !== $b['overdue']) {
                return $b['overdue'] <=> $a['overdue'];
            }
            if ($a['open'] !== $b['open']) {
                return $b['open'] <=> $a['open'];
            }
            return strcmp($a['fullname'], $b['fullname']);
        });

        $supervisorname = fullname($user);
        $usermessage = $this->localized_string('agent_supervisor_overview_summary', (object)[
            'fullname' => $supervisorname,
            'count' => count($rows),
            'open' => $totals['open'],
            'overdue' => $totals['overdue'],
        ], $lang);

        $observation = [$usermessage];
        $observation[] = sprintf(
            '%s: %s=%d, %s=%d, %s=%d, %s=%d, %s=%d',
            $this->localized_string('agent_preview_totals', null, $lang),
            $this->localized_string('agent_preview_open', null, $lang),
            $totals['open'],
            $this->localized_string('agent_preview_overdue', null, $lang),
            $totals['overdue'],
            $this->localized_string('agent_preview_completed', null, $lang),
            $totals['completed'],
            $this->localized_string('agent_preview_open_requests', null, $lang),
            $totals['open_requests'],
            $this->localized_string('agent_preview_unread_chats', null, $lang),
            $totals['unread_chats']
        );
        foreach ($rows as $row) {
            $observation[] = sprintf(
                '%s (userid %d): %s %d, %s %d, %s %d, %s %d, %s %d',
                $row['fullname'],
                $row['userid'],
                $this->localized_string('agent_preview_open', null, $lang),
                $row['open'],
                $this->localized_string('agent_preview_overdue', null, $lang),
                $row['overdue'],
                $this->localized_string('agent_preview_completed', null, $lang),
                $row['completed'],
                $this->localized_string('agent_preview_open_requests', null, $lang),
                $row['open_requests'],
                $this->localized_string('agent_preview_unread_chats', null, $lang),
                $row['unread_chats']
            );
        }

        return $this->base_result(self::STATUS_EXECUTED, [
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'observation_full' => implode("\n", $observation),
            'resultid' => $supervisorid,
            'supervisor' => [
                'id' => $supervisorid,
                'fullname' => $supervisorname,
                'url' => taskflow_result_link_builder::user_url($supervisorid),
            ],
            'subordinates' => $rows,
            'totals' => $totals,
            'links' => $this->links(taskflow_result_link_builder::dashboard_url(), ['dashboard', 'units_and_users']),
            'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, [
                'Supervisor: ' . $supervisorid,
                'Subordinates: ' . implode(',', $subordinateids),
            ]),
            'preview' => [
                'type' => taskflow_preview_renderer_factory::TYPE_SUPERVISOR_OVERVIEW,
                'data' => [
                    'supervisor' => ['id' => $supervisorid, 'fullname' => $supervisorname],
                    'subordinates' => $rows,
                    'totals' => $totals,
                ],
                'payload' => [
                    'userids' => array_merge([$supervisorid], array_column($rows, 'userid')),
                ],
            ],
        ]);
    }

    /**
     * Whether the acting user may read the team of that supervisor.
     *
     * @param int $supervisorid
     * @param int $userid Acting user.
     * @return bool
     */
    private function may_read(int $supervisorid, int $userid): bool {
        if ($userid <= 0) {
            return false;
        }
        $context = context_system::instance();
        if (has_capability(self::CAP_VIEWREPORTS, $context, $userid)) {
            return true;
        }
        if ($this->permissions()->is_admin($userid)) {
            return true;
        }
        return $supervisorid === $userid && has_capability(self::CAP_ISSUPERVISOR, $context, $userid);
    }

    /**
     * Visible subordinates of a supervisor (direct reports plus deputy delegation).
     *
     * @param int $supervisorid
     * @return int[]
     */
    private function subordinate_ids(int $supervisorid): array {
        try {
            $ids = array_map('intval', supervisor::get_visible_subordinate_ids($supervisorid));
        } catch (\Throwable $e) {
            return [];
        }
        $ids = array_values(array_unique(array_filter(
            $ids,
            static fn(int $id): bool => $id > 0 && $id !== $supervisorid
        )));
        sort($ids);
        return $ids;
    }

    /**
     * Open, overdue and completed assignment counts per user.
     *
     * @param int[] $userids
     * @return array<int,array{open:int,overdue:int,completed:int}>
     */
    private function assignment_counters(array $userids): array {
        global $DB;

        if (empty($userids)) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'sub');
        $records = $DB->get_records_sql(
            "SELECT id, userid, status, duedate FROM {local_taskflow_assignment} WHERE userid {$insql}",
            $params
        );

        $activestates = array_map('intval', assignment_status_facade::get_all_active_states());
        $overduestatus = assignment_status_facade::get_status_identifier('overdue');
        $completedstatus = assignment_status_facade::get_status_identifier('completed');
        $now = time();

        $counters = [];
        foreach ($records as $record) {
            $subordinateid = (int)$record->userid;
            if (!isset($counters[$subordinateid])) {
                $counters[$subordinateid] = ['open' => 0, 'overdue' => 0, 'completed' => 0];
            }
            $status = (int)$record->status;
            $duedate = (int)($record->duedate ?? 0);
            $isactive = in_array($status, $activestates, true);
            if ($isactive) {
                $counters[$subordinateid]['open']++;
            }
            if ($status === $overduestatus || ($isactive && $duedate > 0 && $duedate < $now)) {
                $counters[$subordinateid]['overdue']++;
            }
            if ($status === $completedstatus) {
                $counters[$subordinateid]['completed']++;
            }
        }
        return $counters;
    }

    /**
     * Open requests addressed to the supervisor receiver, per requesting user.
     *
     * @param int[] $userids
     * @return array<int,int>
     */
    private function open_request_counters(array $userids): array {
        global $DB;

        if (empty($userids)) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'sub');
        $params['treated'] = requests::TREATED_STATUS_UNTREATED;
        $params['receiver'] = $this->supervisor_receiver_id();
        $records = $DB->get_records_sql(
            "SELECT userid, COUNT(id) AS cnt
               FROM {local_taskflow_requests}
              WHERE userid {$insql} AND treated = :treated AND forhr = :receiver
           GROUP BY userid",
            $params
        );
        $counters = [];
        foreach ($records as $record) {
            $counters[(int)$record->userid] = (int)$record->cnt;
        }
        return $counters;
    }

    /**
     * Unread internal chat messages per subordinate for one reader.
     *
     * Unread = an internal message on an assignment of that subordinate, written by somebody
     * else, newer than the reader's last_seen timestamp on that assignment (missing row = 0);
     * the same comparison the notification_internal_messages task performs.
     *
     * @param int[] $userids
     * @param int $readerid
     * @return array<int,int>
     */
    private function unread_chat_counters(array $userids, int $readerid): array {
        global $DB;

        if (empty($userids)) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'sub');
        $params['readerid'] = $readerid;
        $params['senderid'] = $readerid;
        $records = $DB->get_records_sql(
            "SELECT ic.id, a.userid
               FROM {local_taskflow_int_com} ic
               JOIN {local_taskflow_assignment} a ON a.id = ic.assignmentid
          LEFT JOIN {local_taskflow_last_seen} ls ON ls.assignmentid = ic.assignmentid AND ls.userid = :readerid
              WHERE a.userid {$insql}
                AND ic.usermodified <> :senderid
                AND ic.timecreated > COALESCE(ls.lastseen, 0)",
            $params
        );
        $counters = [];
        foreach ($records as $record) {
            $subordinateid = (int)$record->userid;
            $counters[$subordinateid] = ($counters[$subordinateid] ?? 0) + 1;
        }
        return $counters;
    }

    /**
     * Full names of the given users.
     *
     * @param int[] $userids
     * @return array<int,string>
     */
    private function user_names(array $userids): array {
        global $DB;

        if (empty($userids)) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'usr');
        $userfields = \core_user\fields::for_name()->get_sql('u')->selects;
        $records = $DB->get_records_sql(
            "SELECT u.id {$userfields} FROM {user} u WHERE u.id {$insql}",
            $params
        );
        $names = [];
        foreach ($records as $record) {
            $names[(int)$record->id] = fullname($record);
        }
        return $names;
    }

    /**
     * Receiver id of the supervisor receiver (engine state, never hard-coded).
     *
     * @return int
     */
    private function supervisor_receiver_id(): int {
        try {
            foreach (receiver_facade::get_request_receivers() as $id => $receiver) {
                if ($receiver instanceof supervisor_receiver) {
                    return (int)$id;
                }
            }
        } catch (\Throwable $e) {
            return 0;
        }
        return 0;
    }
}
