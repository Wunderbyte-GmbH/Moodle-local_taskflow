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
use local_taskflow\local\external_adapter\external_api_base;
use local_taskflow\local\history\history;
use local_taskflow\local\messages\message_recipient;
use local_taskflow\local\messages\sending_condition\sending_condition_facade;
use local_taskflow\local\wizard\engine\skill_risk_class;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\taskflow_input_normalizer;
use local_taskflow\local\wizard\taskflow\taskflow_message_resolver;
use local_taskflow\local\wizard\taskflow\taskflow_permission_resolver;
use local_taskflow\local\wizard\taskflow\taskflow_result_link_builder;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use local_taskflow\plugininfo\taskflowadapter;
use stdClass;

/**
 * Skill local_taskflow.diagnose_message_delivery: why a taskflow message did (not) arrive (plan §2 #15).
 *
 * Read-only, R0. Every statement derives from engine/DB state: the template row and its class,
 * the rule the assignment belongs to, the sending condition object, the recipients resolved by
 * message_recipient, the dedupe table {local_taskflow_sent_messages}, the history entries of type
 * mail_send, queued send_taskflow_message adhoc tasks, the two relevant plugin settings and the
 * message-provider preferences plus account state of every resolved recipient.
 *
 * Access: local/taskflow:editmessages, or being the (deputy) supervisor of the assignee.
 *
 * The template is addressed by messageid or by messagequery (unique substring of the template
 * name, resolved through taskflow_message_resolver like search_message_templates does).
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class diagnose_message_delivery_skill extends taskflow_skill_base {
    /** Skill name. */
    public const TASK_NAME = 'local_taskflow.diagnose_message_delivery';

    /** Capability of the message template editor. */
    public const CAPABILITY = 'local/taskflow:editmessages';

    /** Issue code: message template does not exist. */
    public const ISSUE_MESSAGE_NOT_FOUND = taskflow_message_resolver::ISSUE_MESSAGE_NOT_FOUND;

    /** Issue code: several templates match the query. */
    public const ISSUE_MESSAGE_AMBIGUOUS = taskflow_message_resolver::ISSUE_MESSAGE_AMBIGUOUS;

    /** Adhoc task class that actually sends a taskflow message. */
    public const SEND_TASK = 'local_taskflow\task\send_taskflow_message';

    /** Verdict: nothing blocks the delivery. */
    public const VERDICT_DELIVERABLE = 'deliverable';
    /** Verdict: at least one blocker prevents the delivery. */
    public const VERDICT_BLOCKED = 'blocked';
    /** Verdict: the dedupe table already holds a row, so it will not be sent again. */
    public const VERDICT_ALREADY_SENT = 'already_sent';

    /** Checklist row status: check passed. */
    private const OK = 'ok';
    /** Checklist row status: check failed. */
    private const FAIL = 'fail';
    /** Checklist row status: noteworthy, not fatal. */
    private const WARN = 'warn';

    /** Verdict => checklist status class used for the verdict badge. */
    private const VERDICT_ROW_STATUS = [
        self::VERDICT_DELIVERABLE => self::OK,
        self::VERDICT_BLOCKED => self::FAIL,
        self::VERDICT_ALREADY_SENT => self::WARN,
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
            'description' => 'Diagnose whether a taskflow message (reminder, overdue notice, completion or '
                . 'request notification defined as a message template) was sent, is still queued, or is blocked '
                . 'for a given assignment or person, and why: does the template exist, is it attached to the rule '
                . 'of the assignment, does its sending condition allow sending, which recipients (assignee, '
                . 'supervisor, deputies, specific users) resolve, is there already a send-log entry (the dedupe '
                . 'that suppresses a repeat), are there history entries, is a send task still queued, and do the '
                . 'recipients have usable accounts and notification preferences (deputy setting included). '
                . 'Returns a checklist, the blockers and a verdict (deliverable, blocked, already sent). Use it '
                . 'for every "was/why was (not) the message X delivered to Y" question about taskflow assignments.',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'Why did message 5 not arrive for assignment 4711?',
                'Was the "Reminder 7 days" mail sent for assignment 4711?',
                'Is the overdue notice for Anna still queued or blocked?',
                'Why does the supervisor not receive the escalation for this assignment?',
                'Has the completion message been sent twice for this person?',
            ],
            'properties' => [
                'messageid' => [
                    'type' => 'integer',
                    'description' => 'Id of the message template to diagnose (takes precedence over messagequery).',
                    'required' => false,
                ],
                'messagequery' => [
                    'type' => 'string',
                    'description' => 'Distinctive part of the template NAME (case-insensitive substring); must '
                        . 'match exactly one template. Alternative to messageid.',
                    'required' => false,
                ],
                'assignmentid' => [
                    'type' => 'integer',
                    'description' => 'Optional id of the assignment the message belongs to. Without it only the '
                        . 'template itself and the global settings are checked.',
                    'required' => false,
                ],
                'userid' => [
                    'type' => 'integer',
                    'description' => 'Optional id of the person the message is meant for (defaults to the '
                        . 'assignee of the assignment).',
                    'required' => false,
                ],
                'userquery' => [
                    'type' => 'string',
                    'description' => 'Optional user search text (id, e-mail, username or name) instead of userid.',
                    'required' => false,
                ],
            ],
            'required' => [],
        ];
    }

    /**
     * Prompt metadata.
     *
     * @return array<string,mixed>
     */
    protected function prompt_meta(): array {
        return [
            'intent' => 'Explain deterministically whether a taskflow message can be or was delivered.',
        ];
    }

    /**
     * Example input.
     *
     * @return array
     */
    public function get_example_input(): array {
        return ['messageid' => 5, 'assignmentid' => 4711];
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

        $rawmessageid = $input['messageid'] ?? null;
        $hasid = $rawmessageid !== null && trim((string)$rawmessageid) !== '';
        if ($hasid) {
            $messageid = taskflow_input_normalizer::to_int($rawmessageid);
            if ($messageid === null || $messageid <= 0) {
                $errors[] = $this->localized_string('agent_invalid_messageid', null, $lang);
            }
        } else if (trim((string)($input['messagequery'] ?? '')) === '') {
            $errors[] = $this->localized_string('agent_message_reference_missing', null, $lang);
        }

        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => []];
    }

    /**
     * Preflight: structure, template existence, assignment existence and the access gate.
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
                    'field' => 'messageid',
                    'message' => (string)$error,
                ];
            }
            return $this->invalid($issues);
        }

        $resolution = taskflow_message_resolver::resolve($input);
        $issue = taskflow_message_resolver::issue($resolution, 'messageid', $lang);
        if ($issue !== null) {
            return $this->invalid([$issue]);
        }
        $messageid = (int)$resolution['messageid'];

        $assignmentid = taskflow_input_normalizer::to_int($input['assignmentid'] ?? null);
        $assignment = null;
        if ($assignmentid !== null && $assignmentid > 0) {
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
        }

        $targetuserid = $this->target_userid($input, $assignment);
        if ($targetuserid < 0) {
            return $this->invalid([
                $this->not_found_issue(
                    self::ISSUE_USER_NOT_FOUND,
                    $this->localized_string('agent_user_notfound', (string)($input['userquery'] ?? ''), $lang),
                    ['field' => 'userquery']
                ),
            ]);
        }

        if (!$this->may_diagnose($userid, $targetuserid)) {
            return $this->invalid([$this->scope_denied_issue($lang)]);
        }

        $input['messageid'] = $messageid;
        unset($input['messagequery']);
        if ($assignmentid !== null && $assignmentid > 0) {
            $input['assignmentid'] = $assignmentid;
        } else {
            unset($input['assignmentid']);
        }
        $input['userid'] = $targetuserid;
        return $this->pass($input);
    }

    /**
     * Execute: run every check and build the checklist plus the verdict.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        $lang = $this->get_output_language($input);

        $assignmentid = (int)(taskflow_input_normalizer::to_int($input['assignmentid'] ?? null) ?? 0);

        $resolution = taskflow_message_resolver::resolve($input);
        $issue = taskflow_message_resolver::issue($resolution, 'messageid', $lang);
        if ($issue !== null) {
            return $this->error_result(
                (string)$issue['code'],
                (string)$issue['message'],
                [
                    'candidates' => (array)($issue['candidates'] ?? []),
                    'links' => $this->links(taskflow_result_link_builder::edit_message_url(), ['messages']),
                ]
            );
        }
        $template = $resolution['template'];
        $messageid = (int)$template->id;

        $assignment = $assignmentid > 0 ? $this->resolve_assignment(['assignmentid' => $assignmentid]) : null;
        $targetuserid = $this->target_userid($input, $assignment);
        if ($targetuserid < 0) {
            return $this->error_result(
                self::ISSUE_USER_NOT_FOUND,
                $this->localized_string('agent_user_notfound', (string)($input['userquery'] ?? ''), $lang),
                ['links' => $this->links(null, ['messages'])]
            );
        }
        if (!$this->may_diagnose($userid, $targetuserid)) {
            return $this->error_result(
                self::ISSUE_SCOPE_DENIED,
                $this->localized_string('agent_scope_denied', null, $lang),
                ['links' => $this->links(null, ['messages'])]
            );
        }

        $ruleid = (int)($assignment->ruleid ?? 0);
        $sending = $this->sending_settings($template);
        $rows = [];
        $blockers = [];

        $templateinfo = [
            'id' => $messageid,
            'name' => (string)$template->name,
            'class' => (string)$template->class,
            'priority' => (int)$template->priority,
            'recipientroles' => array_values(array_map('strval', (array)($sending['recipientrole'] ?? []))),
            'carboncopyroles' => array_values(array_map('strval', (array)($sending['carboncopyrole'] ?? []))),
            'sendingcondition' => (string)($sending['sendingcondition'] ?? ''),
            'sendstart' => (string)($sending['sendstart'] ?? ''),
        ];
        $rows[] = $this->row(self::OK, 'agent_diagnose_message_check_template', $lang, (object)[
            'name' => $templateinfo['name'],
            'class' => $templateinfo['class'],
        ], taskflow_result_link_builder::edit_message_url($messageid));

        // Attachment to the rule of the assignment.
        $attached = null;
        if ($ruleid > 0) {
            $attached = $this->is_attached_to_rule($messageid, $ruleid);
            $rows[] = $this->row(
                $attached ? self::OK : self::FAIL,
                $attached ? 'agent_diagnose_message_attached' : 'agent_diagnose_message_notattached',
                $lang,
                $ruleid,
                taskflow_result_link_builder::edit_rule_url($ruleid)
            );
            if (!$attached) {
                $blockers[] = 'not_attached_to_rule';
            }
        }

        // Sending condition.
        $condition = sending_condition_facade::create((string)($sending['sendingcondition'] ?? ''));
        $conditioninfo = [
            'identifier' => (string)$condition->get_identifier(),
            'label' => (string)$condition->get_label(),
            'allows_manual' => (bool)$condition->can_send(true),
            'allows_automatic' => (bool)$condition->can_send(false),
        ];
        $restricted = !$conditioninfo['allows_manual'] || !$conditioninfo['allows_automatic'];
        $rows[] = $this->row(
            $restricted ? self::WARN : self::OK,
            'agent_diagnose_message_condition',
            $lang,
            $conditioninfo['label']
        );

        // Recipients.
        $recipients = [];
        $ccrecipients = [];
        if ($targetuserid > 0) {
            $operator = new message_recipient($targetuserid, $this->recipient_input($template));
            $recipients = $this->recipient_rows($operator->get_recepient(), 'to');
            $ccrecipients = $this->recipient_rows($operator->get_carbon_copy(), 'cc');
        }
        $resolved = array_merge($recipients, $ccrecipients);
        $wantssupervisor = in_array('supervisor', $templateinfo['recipientroles'], true)
            || in_array('supervisor', $templateinfo['carboncopyroles'], true);
        $supervisorresolved = $targetuserid > 0 && $this->resolve_supervisor($targetuserid) !== null;
        if (empty($recipients)) {
            $blockers[] = 'no_recipients';
            $rows[] = $this->row(self::FAIL, 'agent_diagnose_message_norecipients', $lang);
        } else {
            $rows[] = $this->row(self::OK, 'agent_diagnose_message_recipients', $lang, (object)[
                'to' => count($recipients),
                'cc' => count($ccrecipients),
            ]);
        }
        if ($wantssupervisor && !$supervisorresolved) {
            $blockers[] = 'supervisor_missing';
            $rows[] = $this->row(self::FAIL, 'agent_diagnose_message_supervisor_missing', $lang);
        }

        // Account state and notification preferences of every resolved recipient.
        foreach ($resolved as $row) {
            if (!$row['deliverable']) {
                $blockers[] = 'recipient_undeliverable';
                $rows[] = $this->row(self::FAIL, 'agent_diagnose_message_undeliverable', $lang, $row['fullname']);
            }
            if (!empty($row['disabled_providers'])) {
                $rows[] = $this->row(self::WARN, 'agent_diagnose_message_provider_off', $lang, (object)[
                    'fullname' => $row['fullname'],
                    'providers' => implode(', ', $row['disabled_providers']),
                ]);
            }
        }

        // Send log (dedupe).
        $sentlog = $this->sent_log($messageid, $ruleid, $targetuserid);
        if (empty($sentlog)) {
            $rows[] = $this->row(self::OK, 'agent_diagnose_message_notsentyet', $lang);
        } else {
            $rows[] = $this->row(self::WARN, 'agent_diagnose_message_sentlog', $lang, (object)[
                'count' => count($sentlog),
                'last' => userdate((int)$sentlog[0]['timesent']),
            ]);
        }

        // History entries.
        $historyrows = $this->history_rows($assignmentid);
        if (!empty($historyrows)) {
            $rows[] = $this->row(self::OK, 'agent_diagnose_message_history', $lang, count($historyrows));
        }

        // Queued adhoc send tasks.
        $pending = $this->pending_tasks($messageid, $ruleid, $targetuserid);
        if (!empty($pending)) {
            $rows[] = $this->row(self::WARN, 'agent_diagnose_message_pending', $lang, (object)[
                'count' => count($pending),
                'next' => userdate((int)$pending[0]['nextruntime']),
            ]);
        }

        // Settings in effect.
        $settings = [
            'sendmailstodeputy' => (bool)get_config('local_taskflow', 'sendmailstodeputy'),
            'sendmanualmailsmultipletimes' => (bool)get_config('local_taskflow', 'sendmanualmailsmultipletimes'),
        ];
        $rows[] = $this->row(self::OK, 'agent_diagnose_message_settings', $lang, (object)[
            'deputy' => $this->onoff($settings['sendmailstodeputy'], $lang),
            'multiple' => $this->onoff($settings['sendmanualmailsmultipletimes'], $lang),
        ]);

        $blockers = array_values(array_unique($blockers));
        if (!empty($blockers)) {
            $verdict = self::VERDICT_BLOCKED;
        } else if (!empty($sentlog) && !$settings['sendmanualmailsmultipletimes']) {
            $verdict = self::VERDICT_ALREADY_SENT;
        } else {
            $verdict = self::VERDICT_DELIVERABLE;
        }

        $usermessage = $this->localized_string('agent_diagnose_message_delivery_summary', (object)[
            'id' => $messageid,
            'name' => $templateinfo['name'],
            'verdict' => $this->localized_string('agent_preview_verdict_' . $verdict, null, $lang),
            'blockers' => count($blockers),
        ], $lang);

        $payload = [
            'template' => $templateinfo,
            'sending_condition' => $conditioninfo,
            'attached_to_rule' => $attached,
            'ruleid' => $ruleid,
            'assignmentid' => $assignmentid,
            'userid' => $targetuserid,
            'resolved_recipients' => $resolved,
            'sent_log' => $sentlog,
            'history' => $historyrows,
            'pending_tasks' => $pending,
            'settings_in_effect' => $settings,
            'blockers' => $blockers,
            'verdict' => $verdict,
            'checks' => $rows,
        ];

        $links = $this->links(
            $assignmentid > 0
                ? taskflow_result_link_builder::assignment_url($assignmentid)
                : taskflow_result_link_builder::edit_message_url($messageid),
            ['messages', 'messages_templates', 'rules_messages_step']
        );

        $debug = $this->build_task_debug_message(self::TASK_NAME, $input, [
            'Verdict: ' . $verdict,
            'Blockers: ' . implode(', ', $blockers),
            'Recipients: ' . count($resolved) . ', sent log: ' . count($sentlog)
                . ', pending tasks: ' . count($pending),
        ]);

        return $this->base_result(self::STATUS_EXECUTED, $payload + [
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'observation_full' => $this->build_observation_full($usermessage, $payload),
            'resultid' => $messageid,
            'links' => $links,
            'debugmessage' => $debug,
            'outputlang' => $lang,
            'preview' => [
                'type' => taskflow_preview_renderer_factory::TYPE_DIAGNOSTIC_CHECKLIST,
                'data' => [
                    'title' => $this->localized_string('agent_diagnose_message_delivery_title', (object)[
                        'id' => $messageid,
                        'name' => $templateinfo['name'],
                    ], $lang),
                    'rows' => $rows,
                    'verdict' => [
                        'code' => $verdict,
                        'label' => $this->localized_string('agent_preview_verdict_' . $verdict, null, $lang),
                        'class' => self::VERDICT_ROW_STATUS[$verdict] ?? self::WARN,
                    ],
                    'links' => $links,
                    'ids' => [
                        'userids' => $targetuserid > 0 ? [$targetuserid] : [],
                        'ruleids' => $ruleid > 0 ? [$ruleid] : [],
                        'assignmentids' => $assignmentid > 0 ? [$assignmentid] : [],
                    ],
                ],
                'payload' => [
                    'messageids' => [$messageid],
                    'assignmentids' => $assignmentid > 0 ? [$assignmentid] : [],
                ],
            ],
        ]);
    }

    /**
     * Whether the acting user may diagnose messages for the target user.
     *
     * @param int $userid Acting user.
     * @param int $targetuserid 0 when no person is involved.
     * @return bool
     */
    private function may_diagnose(int $userid, int $targetuserid): bool {
        if (has_capability(self::CAPABILITY, context_system::instance(), $userid)) {
            return true;
        }
        if ($targetuserid <= 0) {
            return false;
        }
        $scope = $this->permissions()->scope_for_user($targetuserid, $userid);
        return $scope === taskflow_permission_resolver::SCOPE_ADMIN
            || $scope === taskflow_permission_resolver::SCOPE_SUPERVISOR;
    }

    /**
     * Target user: explicit userid/userquery, else the assignee of the assignment, else 0.
     *
     * @param array $input
     * @param stdClass|null $assignment
     * @return int -1 when a user query matched nothing (or was ambiguous).
     */
    private function target_userid(array $input, ?stdClass $assignment): int {
        $userid = taskflow_input_normalizer::to_int($input['userid'] ?? null);
        if ($userid !== null && $userid > 0) {
            return $userid;
        }
        $query = trim((string)($input['userquery'] ?? ''));
        if ($query !== '') {
            $candidates = $this->search_user_candidates($query, 2);
            return count($candidates) === 1 ? (int)$candidates[0]['userid'] : -1;
        }
        return (int)($assignment->userid ?? 0);
    }

    /**
     * Decoded sending settings of a template.
     *
     * @param stdClass $template
     * @return array
     */
    private function sending_settings(stdClass $template): array {
        $decoded = json_decode((string)($template->sending_settings ?? ''), true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Minimal message-like object for message_recipient (it only reads sending_settings).
     *
     * @param stdClass $template
     * @return stdClass
     */
    private function recipient_input(stdClass $template): stdClass {
        return (object)['sending_settings' => (string)($template->sending_settings ?? '{}')];
    }

    /**
     * Whether the rule document references the template in one of its actions.
     *
     * @param int $messageid
     * @param int $ruleid
     * @return bool
     */
    private function is_attached_to_rule(int $messageid, int $ruleid): bool {
        $rule = $this->resolve_rule($ruleid);
        if (empty($rule)) {
            return false;
        }
        return in_array($messageid, taskflow_message_resolver::rule_message_ids((array)($rule['rule'] ?? [])), true);
    }

    /**
     * The supervisor of a user, resolved exactly the way message_recipient does it.
     *
     * @param int $userid
     * @return stdClass|null Null when the mapped profile field holds no user id.
     */
    private function resolve_supervisor(int $userid): ?stdClass {
        try {
            $user = get_complete_user_data('id', $userid);
            $shortname = external_api_base::return_shortname_for_functionname(
                taskflowadapter::TRANSLATOR_USER_SUPERVISOR
            );
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_object($user) || !isset($user->profile[$shortname]) || !is_number($user->profile[$shortname])) {
            return null;
        }
        $supervisor = \core_user::get_user((int)$user->profile[$shortname]);
        return $supervisor ?: null;
    }

    /**
     * Normalized recipient rows including account state and disabled message providers.
     *
     * @param array $users
     * @param string $role 'to' or 'cc'
     * @return array<int,array<string,mixed>>
     */
    private function recipient_rows(array $users, string $role): array {
        $rows = [];
        $seen = [];
        foreach ($users as $user) {
            if (!is_object($user) || empty($user->id)) {
                continue;
            }
            $id = (int)$user->id;
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $email = (string)($user->email ?? '');
            $rows[] = [
                'userid' => $id,
                'fullname' => fullname($user),
                'email' => $email,
                'role' => $role,
                'suspended' => !empty($user->suspended),
                'emailstop' => !empty($user->emailstop),
                'deliverable' => $email !== '' && validate_email($email)
                    && empty($user->suspended) && empty($user->deleted) && empty($user->emailstop),
                'disabled_providers' => $this->disabled_providers($id),
            ];
        }
        return $rows;
    }

    /**
     * Message-provider preferences of local_taskflow the user switched off.
     *
     * @param int $userid
     * @return string[] Preference names.
     */
    private function disabled_providers(int $userid): array {
        global $DB;

        $like = $DB->sql_like('name', ':name');
        $records = $DB->get_records_select(
            'user_preferences',
            'userid = :userid AND ' . $like,
            ['userid' => $userid, 'name' => 'message_provider_local_taskflow_%'],
            'name ASC',
            'id, name, value'
        );

        $off = [];
        foreach ($records as $record) {
            $value = strtolower(trim((string)$record->value));
            if ($value === '' || $value === 'none') {
                $off[] = (string)$record->name;
            }
        }
        return $off;
    }

    /**
     * Rows of the dedupe table for this template (newest first).
     *
     * @param int $messageid
     * @param int $ruleid 0 = any rule.
     * @param int $userid 0 = any user.
     * @return array<int,array{id:int,messageid:int,ruleid:int,userid:int,timesent:int}>
     */
    private function sent_log(int $messageid, int $ruleid, int $userid): array {
        global $DB;

        $conditions = ['messageid' => $messageid];
        if ($ruleid > 0) {
            $conditions['ruleid'] = $ruleid;
        }
        if ($userid > 0) {
            $conditions['userid'] = $userid;
        }
        $records = $DB->get_records('local_taskflow_sent_messages', $conditions, 'timesent DESC, id DESC');

        $rows = [];
        foreach ($records as $record) {
            $rows[] = [
                'id' => (int)$record->id,
                'messageid' => (int)$record->messageid,
                'ruleid' => (int)$record->ruleid,
                'userid' => (int)$record->userid,
                'timesent' => (int)$record->timesent,
            ];
        }
        return $rows;
    }

    /**
     * History entries of type mail_send for the assignment (newest first).
     *
     * @param int $assignmentid
     * @return array<int,array{id:int,timecreated:int,createdby:int,data:string}>
     */
    private function history_rows(int $assignmentid): array {
        global $DB;

        if ($assignmentid <= 0) {
            return [];
        }
        $records = $DB->get_records(
            'local_taskflow_history',
            ['assignmentid' => $assignmentid, 'type' => history::TYPE_MAIL_SEND],
            'timecreated DESC, id DESC'
        );

        $rows = [];
        foreach ($records as $record) {
            $rows[] = [
                'id' => (int)$record->id,
                'timecreated' => (int)$record->timecreated,
                'createdby' => (int)$record->createdby,
                'data' => (string)$record->data,
            ];
        }
        return $rows;
    }

    /**
     * Queued send_taskflow_message adhoc tasks whose custom data names this template.
     *
     * @param int $messageid
     * @param int $ruleid 0 = any rule.
     * @param int $userid 0 = any user.
     * @return array<int,array{id:int,nextruntime:int,userid:int,ruleid:int}>
     */
    private function pending_tasks(int $messageid, int $ruleid, int $userid): array {
        global $DB;

        $tasks = $DB->get_records_list(
            'task_adhoc',
            'classname',
            ['\\' . self::SEND_TASK, self::SEND_TASK],
            'nextruntime ASC, id ASC',
            'id, nextruntime, customdata'
        );

        $rows = [];
        foreach ($tasks as $task) {
            $data = json_decode((string)$task->customdata, true);
            if (!is_array($data) || (int)($data['messageid'] ?? 0) !== $messageid) {
                continue;
            }
            if ($ruleid > 0 && (int)($data['ruleid'] ?? 0) !== $ruleid) {
                continue;
            }
            if ($userid > 0 && (int)($data['userid'] ?? 0) !== $userid) {
                continue;
            }
            $rows[] = [
                'id' => (int)$task->id,
                'nextruntime' => (int)$task->nextruntime,
                'userid' => (int)($data['userid'] ?? 0),
                'ruleid' => (int)($data['ruleid'] ?? 0),
            ];
        }
        return $rows;
    }

    /**
     * Localized on/off label of a boolean setting.
     *
     * @param bool $value
     * @param string $lang
     * @return string
     */
    private function onoff(bool $value, string $lang): string {
        return $this->localized_string($value ? 'agent_preview_setting_on' : 'agent_preview_setting_off', null, $lang);
    }

    /**
     * Checklist row {status, check, detail, url} as expected by the diagnostic checklist preview.
     *
     * @param string $status ok|fail|warn
     * @param string $identifier Lang key of the finding sentence.
     * @param string $lang
     * @param mixed $a Placeholder data of the lang string.
     * @param string $url Optional deep link.
     * @return array{status:string,check:string,detail:string,url:string}
     */
    private function row(string $status, string $identifier, string $lang, $a = null, string $url = ''): array {
        return [
            'status' => $status,
            'check' => $this->localized_string($identifier, $a, $lang),
            'detail' => '',
            'url' => $url,
        ];
    }

    /**
     * Observation payload for follow-up reasoning steps (message + JSON).
     *
     * @param string $usermessage
     * @param array $payload
     * @return string
     */
    private function build_observation_full(string $usermessage, array $payload): string {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || $json === '') {
            return $usermessage;
        }
        return $usermessage . "\n\nMessage delivery diagnosis (JSON):\n" . $json;
    }
}
