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
use local_taskflow\local\requests\request_receivers\receiver_facade;
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

    /** Delivery state: the send log or the history records a delivery of this template. */
    public const STATE_SENT = 'sent';
    /** Delivery state: nothing sent yet, a send task is queued. */
    public const STATE_QUEUED = 'queued';
    /** Delivery state: neither sent nor queued. */
    public const STATE_NOT_SENT = 'not_sent';

    /** Upper bound of templates diagnosed in one call when the rule decides (wave 32). */
    public const MAX_TEMPLATES = 10;

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
            // First 240 characters carry the discrimination against the booking diagnosis skills (#471).
            'description' => 'Diagnose why a taskflow message (reminder, overdue notice, completion or request notification '
                . 'defined as a message template) was or was not delivered for an assignment or person. Use it for every "was/why '
                . 'was (not) the message X delivered to Y" question about taskflow assignments: it checks whether the template '
                . 'exists, is attached to the rule of the assignment and its sending condition allows sending, which recipients '
                . '(assignee, supervisor, deputies, specific users) resolve, whether a send-log entry already exists (the dedupe '
                . 'that suppresses a repeat), whether history entries exist, whether a send task is still queued, and whether the '
                . 'recipients have usable accounts and notification preferences (deputy setting included). Returns a checklist, '
                . 'the blockers and a verdict (deliverable, blocked, already sent).',
            'is' => 'Taskflow messages about an assignment.',
            'not' => 'Mails a booking activity sends (mod_booking.analyze_rules).',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'Why did message 5 not arrive for assignment 4711?',
                'Was the "Reminder 7 days" mail sent for assignment 4711?',
                'Is the overdue notice for Anna still queued or blocked?',
                'Why does the supervisor not receive the escalation for this assignment?',
                'Has the completion message been sent twice for this person?',
            ],
            // Wave 32 (DMD-1, runs L30-L41): a user names the person and the TRAINING, rarely the template. The
            // training went into messagequery ("Datenschutz-Unterweisung") and matched no template name; from L40 on
            // the constructor asked for the template name instead. The rule fields carry the training; the person and
            // the rule find the assignment, and without a named template the templates of that rule are checked.
            // Every description below fits the 160-character cut of the agent's schema projection (574147c).
            'properties' => [
                'messageid' => [
                    'type' => 'integer',
                    'description' => 'Id of the message template to diagnose (takes precedence over messagequery).',
                    'required' => false,
                ],
                'messagequery' => [
                    'type' => 'string',
                    'description' => 'Words of the template NAME as the user wrote them; a number is the id (messageid). '
                        . 'The name of a training or rule goes into rulequery.',
                    'required' => false,
                ],
                'class' => [
                    'type' => 'string',
                    'enum' => taskflow_message_resolver::MESSAGE_CLASSES,
                    'description' => 'Kind of message when no template is named: standard = scheduled reminder, onevent = '
                        . 'status change or completion, request = request mails.',
                    'required' => false,
                ],
                'assignmentid' => [
                    'type' => 'integer',
                    'description' => 'Optional id of the assignment the message belongs to. Without it only the '
                        . 'template itself and the global settings are checked.',
                    'required' => false,
                ],
                'ruleid' => [
                    'type' => 'integer',
                    'description' => 'Id of the rule (training) the message belongs to; with a person it finds the assignment.',
                    'required' => false,
                ],
                'rulequery' => [
                    'type' => 'string',
                    'description' => 'Name of the rule or training the message is about. With a person it finds the '
                        . 'assignment; without a template all templates of the rule are checked.',
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
            'when' => 'The user asks whether or why a reminder, overdue notice or request mail for an assignment or person'
                . ' was or was not delivered.',
            // The prompt_meta block keeps its established shape even where only the group is declared: the
            // contract test asserts both keys on every skill that carries prompt_meta at all, and an
            // empty list is what the readers saw before this block existed.
            'input_fields_for_prompt' => [],
            'anchor_fields' => [],
            // Mirrors check_structure(): a template reference, or the assignment it belongs to - by id, or by the
            // person and the training (wave 32, DMD-1). The numeric validation of a given messageid is no
            // requirement of an empty input.
            'required_groups' => [
                ['messageid', 'messagequery', 'assignmentid', 'ruleid', 'rulequery', 'userid', 'userquery'],
            ],
        ];
    }

    /**
     * Example input.
     *
     * @return array
     */
    public function get_example_input(): array {
        // The constructor only sees example VALUES: advertise the name query, not an id the user
        // never has at hand (#469).
        return ['messagequery' => 'reminder 7 days', 'assignmentid' => 4711];
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
        } else if (
            trim((string)($input['messagequery'] ?? '')) === ''
            && !$this->has_assignment_reference($input)
        ) {
            $errors[] = $this->localized_string('agent_message_reference_missing', null, $lang);
        }

        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => []];
    }

    /**
     * Whether the input refers to an assignment or a rule (id, person, rule) - structural, no DB access.
     *
     * @param array $input
     * @return bool
     */
    private function has_assignment_reference(array $input): bool {
        foreach (['assignmentid', 'ruleid', 'userid'] as $key) {
            if ((taskflow_input_normalizer::to_int($input[$key] ?? null) ?? 0) > 0) {
                return true;
            }
        }
        foreach (['rulequery', 'userquery'] as $key) {
            if (trim((string)($input[$key] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolve assignment, rule and the template(s) to diagnose (shared by preflight and execute).
     *
     * Order: the assignment (id, or person + rule, or a person alone whose assignments are the choices), else a rule
     * alone; then the template: a named one (id or name), narrowed to the rule when the name is ambiguous. When the
     * user named no template, or the name matches none, the templates attached to the rule are diagnosed (optionally
     * one class) - the answer then covers every message of that rule instead of asking which one (wave 32, DMD-1).
     * Only DB facts decide; the user's wording is never interpreted.
     *
     * @param array $input
     * @param int $userid Acting user.
     * @param string $lang
     * @return array{issue:?array,assignment:?stdClass,assignmentid:int,ruleid:int,templates:stdClass[],unmatched:string}
     */
    private function resolve_target(array $input, int $userid, string $lang): array {
        $out = ['issue' => null, 'assignment' => null, 'assignmentid' => 0, 'ruleid' => 0, 'templates' => [],
            'unmatched' => ''];

        // A template "name" that no template carries but exactly one rule does is the rule (DMD-1: the training
        // arrived as messagequery in 7 of 10 runs). Decided by the stored names only - never by the wording.
        $messagequery = trim((string)($input['messagequery'] ?? ''));
        if (
            $messagequery !== ''
            && !ctype_digit($messagequery)
            && trim((string)($input['rulequery'] ?? '')) === ''
            && (taskflow_input_normalizer::to_int($input['ruleid'] ?? null) ?? 0) <= 0
            && empty(taskflow_message_resolver::candidates($messagequery, 1))
            && count($this->search_rule_candidates($messagequery, 2)) === 1
        ) {
            $input['rulequery'] = $messagequery;
            unset($input['messagequery']);
            $out['unmatched'] = $messagequery;
        }

        $hasperson = (taskflow_input_normalizer::to_int($input['userid'] ?? null) ?? 0) > 0
            || trim((string)($input['userquery'] ?? '')) !== '';
        $hasrule = (taskflow_input_normalizer::to_int($input['ruleid'] ?? null) ?? 0) > 0
            || trim((string)($input['rulequery'] ?? '')) !== '';
        $hastemplate = (taskflow_input_normalizer::to_int($input['messageid'] ?? null) ?? 0) > 0
            || trim((string)($input['messagequery'] ?? '')) !== ''
            || !empty($input['messageids']);
        $assignmentid = taskflow_input_normalizer::to_int($input['assignmentid'] ?? null) ?? 0;

        // 1. The assignment - or, without a person, the rule alone.
        if ($assignmentid > 0 || ($hasperson && ($hasrule || !$hastemplate))) {
            $target = $this->resolve_assignment_target($input, $userid, $lang);
            if ($target['issue'] !== null) {
                $out['issue'] = $target['issue'];
                return $out;
            }
            $out['assignmentid'] = (int)$target['assignmentid'];
            $out['assignment'] = $this->resolve_assignment(['assignmentid' => $out['assignmentid']]);
            $out['ruleid'] = (int)($out['assignment']->ruleid ?? 0);
        } else if ($hasrule) {
            $ruleid = $this->resolve_ruleid($input);
            if ($ruleid <= 0 || empty($this->resolve_rule($ruleid))) {
                $out['issue'] = $this->rule_lookup_issue($input, $lang);
                return $out;
            }
            $out['ruleid'] = $ruleid;
        }
        $ruledocument = $out['ruleid'] > 0 ? (array)($this->resolve_rule($out['ruleid'])['rule'] ?? []) : [];
        // An unknown class is ignored like an unknown status filter (status_filter()): the user text never lists schema
        // fields or allowed values (HARD RULE 2026-09-14 point 3).
        $class = strtolower(trim((string)($input['class'] ?? '')));
        if (!in_array($class, taskflow_message_resolver::MESSAGE_CLASSES, true)) {
            $class = '';
        }

        // 2. The template(s). The prepared input of the preflight carries the resolved list.
        if (!empty($input['messageids']) && is_array($input['messageids'])) {
            foreach ($input['messageids'] as $id) {
                $template = taskflow_message_resolver::load((int)$id);
                if ($template !== null) {
                    $out['templates'][] = $template;
                }
            }
            if (!empty($out['templates'])) {
                return $out;
            }
        }

        $resolution = taskflow_message_resolver::resolve($input);
        $status = (string)$resolution['status'];
        if ($status === taskflow_message_resolver::STATUS_FOUND) {
            $out['templates'] = [$resolution['template']];
            return $out;
        }
        if (!empty($ruledocument)) {
            if ($status === taskflow_message_resolver::STATUS_AMBIGUOUS) {
                // Several templates carry the name: the rule's own ones (and the class) decide; several of them
                // are all diagnosed - they are the rule's messages that carry the name.
                $narrowed = taskflow_message_resolver::narrow_to_rule($resolution, $ruledocument, $class);
                $out['templates'] = $this->load_candidates((array)$narrowed['candidates']);
            } else if (
                $status === taskflow_message_resolver::STATUS_MISSING
                || ($status === taskflow_message_resolver::STATUS_NOT_FOUND
                    && strpos((string)($resolution['query'] ?? ''), '#') !== 0)
            ) {
                $inferred = taskflow_message_resolver::resolve_from_rule($ruledocument, $class);
                $out['templates'] = $this->load_candidates((array)$inferred['candidates']);
                if (!empty($out['templates']) && $status === taskflow_message_resolver::STATUS_NOT_FOUND) {
                    $out['unmatched'] = (string)$resolution['query'];
                }
                if (empty($out['templates'])) {
                    $resolution = $inferred;
                }
            }
            if (!empty($out['templates'])) {
                return $out;
            }
        }

        $out['issue'] = taskflow_message_resolver::issue(
            $resolution,
            'messageid',
            $lang,
            $status === taskflow_message_resolver::STATUS_MISSING ? $out['assignmentid'] : 0
        );
        return $out;
    }

    /**
     * Template records of resolver candidates, in their order, capped at MAX_TEMPLATES.
     *
     * @param array $candidates Rows {id, name, class}.
     * @return stdClass[]
     */
    private function load_candidates(array $candidates): array {
        $templates = [];
        foreach (array_slice($candidates, 0, self::MAX_TEMPLATES) as $candidate) {
            $template = taskflow_message_resolver::load((int)($candidate['id'] ?? 0));
            if ($template !== null) {
                $templates[] = $template;
            }
        }
        return $templates;
    }

    /**
     * Preflight: structure, template(s), assignment or rule, target person and the access gate.
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

        $target = $this->resolve_target($input, $userid, $lang);
        if ($target['issue'] !== null) {
            return $this->invalid([$target['issue']]);
        }

        $targetuserid = $this->target_userid($input, $target['assignment']);
        if ($targetuserid < 0) {
            return $this->invalid([$this->user_lookup_issue($input, $lang)]);
        }

        if (!$this->may_diagnose($userid, $targetuserid)) {
            return $this->invalid([$this->scope_denied_issue($lang)]);
        }

        $ids = array_map(static fn(stdClass $template): int => (int)$template->id, $target['templates']);
        unset($input['messagequery'], $input['messageids'], $input['rulequery'], $input['class']);
        if (count($ids) === 1) {
            $input['messageid'] = $ids[0];
        } else {
            unset($input['messageid']);
            $input['messageids'] = $ids;
        }
        if ($target['unmatched'] !== '') {
            $input['template_query_unmatched'] = $target['unmatched'];
        }
        if ($target['assignmentid'] > 0) {
            $input['assignmentid'] = $target['assignmentid'];
        } else {
            unset($input['assignmentid']);
        }
        if ($target['ruleid'] > 0) {
            $input['ruleid'] = $target['ruleid'];
        }
        $input['userid'] = $targetuserid;
        return $this->pass($input);
    }

    /**
     * Execute: run every check per template and build the checklist plus the verdict.
     *
     * One template: the established single-template result. Several (the rule decided because the user named no
     * template): one block per template with its delivery state, plus a summary across them.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        $lang = $this->get_output_language($input);

        // Resolution recomputed: execute() must be safe without preflight (read path).
        $target = $this->resolve_target($input, $userid, $lang);
        if ($target['issue'] !== null) {
            return $this->error_result(
                (string)$target['issue']['code'],
                (string)$target['issue']['message'],
                [
                    'candidates' => (array)($target['issue']['candidates'] ?? []),
                    'links' => $this->links(taskflow_result_link_builder::edit_message_url(), ['messages']),
                ]
            );
        }
        $assignment = $target['assignment'];
        $assignmentid = (int)$target['assignmentid'];
        $ruleid = (int)$target['ruleid'];
        $unmatched = trim((string)($input['template_query_unmatched'] ?? $target['unmatched']));

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

        // Assignment- and site-wide facts, shared by every template.
        $historyrows = $this->history_rows($assignmentid);
        $settings = [
            'sendmailstodeputy' => (bool)get_config('local_taskflow', 'sendmailstodeputy'),
            'sendmanualmailsmultipletimes' => (bool)get_config('local_taskflow', 'sendmanualmailsmultipletimes'),
        ];

        $diagnoses = [];
        foreach ($target['templates'] as $template) {
            $diagnoses[] = $this->diagnose_template(
                $template,
                $ruleid,
                $assignmentid,
                $targetuserid,
                $historyrows,
                $settings,
                $lang
            );
        }

        $settingsrow = $this->row(self::OK, 'agent_diagnose_message_settings', $lang, (object)[
            'deputy' => $this->onoff($settings['sendmailstodeputy'], $lang),
            'multiple' => $this->onoff($settings['sendmanualmailsmultipletimes'], $lang),
        ]);
        $unmatchedrow = $unmatched === ''
            ? []
            : [$this->row(self::WARN, 'agent_diagnose_message_query_unmatched', $lang, $unmatched)];

        $links = $this->links(
            $assignmentid > 0
                ? taskflow_result_link_builder::assignment_url($assignmentid)
                : taskflow_result_link_builder::edit_message_url(count($diagnoses) === 1
                    ? (int)$diagnoses[0]['template']['id'] : 0),
            ['messages', 'messages_templates', 'rules_messages_step']
        );
        $messageids = array_map(static fn(array $d): int => (int)$d['template']['id'], $diagnoses);
        $ids = [
            'userids' => $targetuserid > 0 ? [$targetuserid] : [],
            'ruleids' => $ruleid > 0 ? [$ruleid] : [],
            'assignmentids' => $assignmentid > 0 ? [$assignmentid] : [],
        ];

        if (count($diagnoses) === 1) {
            $one = $diagnoses[0];
            $messageid = (int)$one['template']['id'];
            $rows = array_merge($unmatchedrow, $one['rows'], [$settingsrow]);
            $usermessage = $this->localized_string('agent_diagnose_message_delivery_summary', (object)[
                'id' => $messageid,
                'name' => $one['template']['name'],
                'verdict' => $this->localized_string('agent_preview_verdict_' . $one['verdict'], null, $lang),
                'blockers' => count($one['blockers']),
            ], $lang);
            $payload = [
                'template' => $one['template'],
                'sending_condition' => $one['sending_condition'],
                'attached_to_rule' => $one['attached_to_rule'],
                'ruleid' => $ruleid,
                'assignmentid' => $assignmentid,
                'userid' => $targetuserid,
                'resolved_recipients' => $one['resolved_recipients'],
                'requests' => $one['requests'],
                'sent_log' => $one['sent_log'],
                'history' => $historyrows,
                'history_for_template' => $one['history_for_template'],
                'pending_tasks' => $one['pending_tasks'],
                'delivery_state' => $one['delivery_state'],
                'settings_in_effect' => $settings,
                'blockers' => $one['blockers'],
                'verdict' => $one['verdict'],
                'template_query_unmatched' => $unmatched,
                'checks' => $rows,
            ];
            $title = $this->localized_string('agent_diagnose_message_delivery_title', (object)[
                'id' => $messageid,
                'name' => $one['template']['name'],
            ], $lang);
            $verdict = [
                'code' => $one['verdict'],
                'label' => $this->localized_string('agent_preview_verdict_' . $one['verdict'], null, $lang),
                'class' => self::VERDICT_ROW_STATUS[$one['verdict']] ?? self::WARN,
            ];
            $debugextra = [
                'Verdict: ' . $one['verdict'],
                'Blockers: ' . implode(', ', $one['blockers']),
                'Recipients: ' . count($one['resolved_recipients']) . ', sent log: ' . count($one['sent_log'])
                    . ', pending tasks: ' . count($one['pending_tasks']),
            ];
        } else {
            $rows = $unmatchedrow;
            $list = [];
            $summaries = [];
            foreach ($diagnoses as $d) {
                $header = $this->localized_string('agent_diagnose_message_template_header', (object)[
                    'id' => (int)$d['template']['id'],
                    'name' => $d['template']['name'],
                    'state' => $d['state_text'],
                    'verdict' => $this->localized_string('agent_preview_verdict_' . $d['verdict'], null, $lang),
                ], $lang);
                $rows[] = [
                    'status' => self::VERDICT_ROW_STATUS[$d['verdict']] ?? self::WARN,
                    'check' => $header,
                    'detail' => '',
                    'url' => taskflow_result_link_builder::edit_message_url((int)$d['template']['id']),
                ];
                $rows = array_merge($rows, $d['rows']);
                $list[] = $header;
                $summaries[] = [
                    'template' => $d['template'],
                    'delivery_state' => $d['delivery_state'],
                    'verdict' => $d['verdict'],
                    'blockers' => $d['blockers'],
                    'attached_to_rule' => $d['attached_to_rule'],
                    'sent_log' => $d['sent_log'],
                    'history_for_template' => $d['history_for_template'],
                    'pending_tasks' => $d['pending_tasks'],
                    'resolved_recipients' => $d['resolved_recipients'],
                    'requests' => $d['requests'],
                ];
            }
            $rows[] = $settingsrow;
            $usermessage = $this->localized_string('agent_diagnose_message_multi_summary', (object)[
                'count' => count($diagnoses),
                'list' => implode('; ', $list),
            ], $lang);
            $payload = [
                'templates' => $summaries,
                'ruleid' => $ruleid,
                'assignmentid' => $assignmentid,
                'userid' => $targetuserid,
                'history' => $historyrows,
                'settings_in_effect' => $settings,
                'template_query_unmatched' => $unmatched,
                'checks' => $rows,
            ];
            $title = $this->localized_string('agent_diagnose_message_multi_title', count($diagnoses), $lang);
            $verdict = null;
            $debugextra = array_map(
                static fn(array $d): string => '#' . (int)$d['template']['id'] . ': ' . $d['delivery_state'] . ', '
                    . $d['verdict'],
                $diagnoses
            );
        }

        $debug = $this->build_task_debug_message(self::TASK_NAME, $input, $debugextra);

        $data = [
            'title' => $title,
            'rows' => $rows,
            'links' => $links,
            'ids' => $ids,
        ];
        if ($verdict !== null) {
            $data['verdict'] = $verdict;
        }

        return $this->base_result(self::STATUS_EXECUTED, $payload + [
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'observation_full' => $this->build_observation_full($usermessage, $payload),
            'resultid' => $messageids[0] ?? 0,
            'links' => $links,
            'debugmessage' => $debug,
            'outputlang' => $lang,
            'preview' => [
                'type' => taskflow_preview_renderer_factory::TYPE_DIAGNOSTIC_CHECKLIST,
                'data' => $data,
                'payload' => [
                    'messageids' => $messageids,
                    'assignmentids' => $assignmentid > 0 ? [$assignmentid] : [],
                ],
            ],
        ]);
    }

    /**
     * Every check for one template: attachment, sending condition, recipients, send log, history, queued tasks.
     *
     * @param stdClass $template
     * @param int $ruleid 0 = no rule known.
     * @param int $assignmentid 0 = no assignment known.
     * @param int $targetuserid 0 = no person involved.
     * @param array $historyrows mail_send history of the assignment (all templates).
     * @param array $settings Settings in effect.
     * @param string $lang
     * @return array<string,mixed>
     */
    private function diagnose_template(
        stdClass $template,
        int $ruleid,
        int $assignmentid,
        int $targetuserid,
        array $historyrows,
        array $settings,
        string $lang
    ): array {
        $messageid = (int)$template->id;
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
        $requests = [];
        if ($targetuserid > 0 && (string)$template->class === 'request') {
            // Request mails never read the template's recipient roles: types\request::send_message() sends to the
            // request's receiver (supervisor + deputies, or HR - the request's forhr flag) while it is untreated,
            // and to the assignee once it is treated. Resolving them through the recipient roles reported
            // "no recipient" for every request mail (DMD-2, L41 thread 12663 and L43 thread 13263: "reached
            // neither the supervisor nor the deputy" next to two send-log entries).
            $requests = $this->request_receivers($targetuserid, $assignmentid);
            $users = [];
            foreach ($requests as $request) {
                $users = array_merge($users, $request['users']);
            }
            $recipients = $this->recipient_rows($users, 'to');
            $operator = new message_recipient($targetuserid, $this->recipient_input($template));
            $ccrecipients = $this->recipient_rows($operator->get_carbon_copy(), 'cc');
        } else if ($targetuserid > 0) {
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

        // History entries: mail_send entries record the template NAME (message_base::log_message_in_history()),
        // so the entries of this template are told apart by that stored value (DMD-3: two history entries of
        // another template read as "sent" next to an empty send log).
        $historyfortemplate = array_values(array_filter(
            $historyrows,
            fn(array $entry): bool => $this->history_names_template($entry, (string)$template->name)
        ));
        if (!empty($historyrows)) {
            $rows[] = $this->row(self::OK, 'agent_diagnose_message_history', $lang, count($historyrows));
        }
        if (!empty($historyfortemplate)) {
            $rows[] = $this->row(self::OK, 'agent_diagnose_message_history_template', $lang, count($historyfortemplate));
        }

        // Queued adhoc send tasks.
        $pending = $this->pending_tasks($messageid, $ruleid, $targetuserid);
        if (!empty($pending)) {
            $rows[] = $this->row(self::WARN, 'agent_diagnose_message_pending', $lang, (object)[
                'count' => count($pending),
                'next' => userdate((int)$pending[0]['nextruntime']),
            ]);
        }

        $blockers = array_values(array_unique($blockers));
        if (!empty($blockers)) {
            $verdict = self::VERDICT_BLOCKED;
        } else if (!empty($sentlog) && !$settings['sendmanualmailsmultipletimes']) {
            $verdict = self::VERDICT_ALREADY_SENT;
        } else {
            $verdict = self::VERDICT_DELIVERABLE;
        }

        // Delivery state: what actually happened, independent of the verdict (which says what WOULD happen).
        $lastsent = 0;
        foreach ($sentlog as $entry) {
            $lastsent = max($lastsent, (int)$entry['timesent']);
        }
        foreach ($historyfortemplate as $entry) {
            $lastsent = max($lastsent, (int)$entry['timecreated']);
        }
        if ($lastsent > 0) {
            $state = self::STATE_SENT;
            $statetext = $this->localized_string('agent_diagnose_message_state_sent', userdate($lastsent), $lang);
        } else if (!empty($pending)) {
            $state = self::STATE_QUEUED;
            $statetext = $this->localized_string(
                'agent_diagnose_message_state_queued',
                userdate((int)$pending[0]['nextruntime']),
                $lang
            );
        } else {
            $state = self::STATE_NOT_SENT;
            $statetext = $this->localized_string('agent_diagnose_message_state_notsent', null, $lang);
        }

        return [
            'template' => $templateinfo,
            'sending_condition' => $conditioninfo,
            'attached_to_rule' => $attached,
            'resolved_recipients' => $resolved,
            'requests' => array_map(static function (array $request): array {
                unset($request['users']);
                return $request;
            }, $requests),
            'sent_log' => $sentlog,
            'history_for_template' => $historyfortemplate,
            'pending_tasks' => $pending,
            'blockers' => $blockers,
            'verdict' => $verdict,
            'delivery_state' => $state,
            'state_text' => $statetext,
            'rows' => $rows,
        ];
    }

    /**
     * Whether a mail_send history entry names this template (the stored value is the template name).
     *
     * @param array $entry Row of history_rows().
     * @param string $name Template name.
     * @return bool
     */
    private function history_names_template(array $entry, string $name): bool {
        $decoded = json_decode((string)($entry['data'] ?? ''), true);
        if (!is_array($decoded)) {
            return false;
        }
        // The history::log() call stores the data array as JSON: action mail_send, data = the template name.
        $value = $decoded['data'] ?? null;
        return is_string($value) && $name !== '' && trim($value) === trim($name);
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
     * Receivers of the person's requests, resolved exactly the way types\request::send_message() does it.
     *
     * Untreated request: the receiver the request names (forhr: HR users, otherwise supervisor and deputies).
     * Treated request: the assignee. Decided by the stored request row only.
     *
     * @param int $userid The person who issued the request(s).
     * @param int $assignmentid 0 = every request of the person.
     * @return array<int,array<string,mixed>> Rows {requestid, assignmentid, treated, forhr, receiverids, users}.
     */
    private function request_receivers(int $userid, int $assignmentid): array {
        global $DB;

        $conditions = ['userid' => $userid];
        if ($assignmentid > 0) {
            $conditions['assignmentid'] = $assignmentid;
        }
        $out = [];
        foreach ($DB->get_records('local_taskflow_requests', $conditions, 'id ASC') as $request) {
            $assignment = $DB->get_record('local_taskflow_assignment', ['id' => (int)$request->assignmentid]);
            if (!$assignment) {
                continue;
            }
            if ((int)$request->treated !== 0) {
                $users = [\core_user::get_user((int)$assignment->userid)];
            } else {
                $users = receiver_facade::get_request_receiver((int)$request->forhr, $assignment);
            }
            $users = array_values(array_filter((array)$users, static fn($user): bool => is_object($user)
                && !empty($user->id)));
            $out[] = [
                'requestid' => (int)$request->id,
                'assignmentid' => (int)$request->assignmentid,
                'treated' => (int)$request->treated !== 0,
                'forhr' => (int)($request->forhr ?? 0) === 1,
                'receiverids' => array_map(static fn($user): int => (int)$user->id, $users),
                'users' => $users,
            ];
        }
        return $out;
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
