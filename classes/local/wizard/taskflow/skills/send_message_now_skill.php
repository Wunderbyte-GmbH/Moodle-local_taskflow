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
use local_taskflow\local\messages\message_recipient;
use local_taskflow\local\messages\messages_factory;
use local_taskflow\local\wizard\engine\queue_identity_provider_interface;
use local_taskflow\local\wizard\engine\skill_risk_class;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\taskflow_input_normalizer;
use local_taskflow\local\wizard\taskflow\taskflow_result_link_builder;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use stdClass;

/**
 * Mutating skill local_taskflow.send_message_now (implementation plan §2 #30 / #31).
 *
 * Sends ONE message template immediately for a list of assignments. The sending itself is the
 * plugin's own path and is not re-implemented: messages_factory::instance($record, $userid,
 * $ruleid, true) builds the message object with manualchanged = true, was_already_send() decides
 * whether the send log (and the setting sendmanualmailsmultipletimes) suppresses a repeat, and
 * send_and_save_message() sends and writes the {local_taskflow_sent_messages} row.
 *
 * The result is per assignment (sent | skipped_already_sent | error); a single failed send turns
 * the overall status into 'error', never a blanket success.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_message_now_skill extends taskflow_skill_base implements queue_identity_provider_interface {
    /** Skill name. */
    public const TASK_NAME = 'local_taskflow.send_message_now';

    /** Capability of the message template editor. */
    public const CAPABILITY = 'local/taskflow:editmessages';

    /** Maximum number of assignments per call. */
    public const MAX_ASSIGNMENTS = 50;

    /** Per assignment outcome: the mail was sent. */
    public const OUTCOME_SENT = 'sent';

    /** Per assignment outcome: the send log already holds an entry. */
    public const OUTCOME_SKIPPED = 'skipped_already_sent';

    /** Per assignment outcome: the send failed. */
    public const OUTCOME_ERROR = 'error';

    /** Issue code: the message template does not exist. */
    public const ISSUE_MESSAGE_NOT_FOUND = 'TASKFLOW_MESSAGE_NOT_FOUND';

    /** Issue code: no assignment id was given. */
    public const ISSUE_NO_ASSIGNMENTS = 'TASKFLOW_SEND_NO_ASSIGNMENTS';

    /** Issue code: more assignments than the hard cap. */
    public const ISSUE_TOO_MANY = 'TASKFLOW_SEND_TOO_MANY_ASSIGNMENTS';

    /** Issue code: an assignment id could not be resolved. */
    public const ISSUE_ASSIGNMENT_UNRESOLVED = 'TASKFLOW_SEND_ASSIGNMENT_UNRESOLVED';

    /** Issue code: every assignment would be skipped, so nothing would go out. */
    public const ISSUE_NOTHING_TO_SEND = 'TASKFLOW_SEND_NOTHING_TO_SEND';

    /** Issue code: at least one send failed. */
    public const ISSUE_SEND_FAILED = 'TASKFLOW_SEND_FAILED';

    /** Issue code: the mutation needs an explicit confirmation. */
    public const ISSUE_CONFIRM = 'TASKFLOW_SEND_MESSAGE_CONFIRM_REQUIRED';

    /**
     * Constructor: mutating, R2, hard capability gate on local/taskflow:editmessages.
     */
    public function __construct() {
        parent::__construct(false, skill_risk_class::R2, [self::CAPABILITY]);
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
            'description' => 'Send ONE taskflow message template immediately for a list of assignments. The mail '
                . 'goes out at once and cannot be recalled. Recipients follow the template configuration '
                . '(assignee, supervisor, specific user, CC). Assignments whose send log already holds an entry '
                . 'are skipped unless the setting sendmanualmailsmultipletimes is switched on.',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'Send the reminder template to assignment 4711 now',
                'Send message template 5 to these seven assignments',
                'Remind everybody on this list immediately',
            ],
            'properties' => [
                'messageid' => [
                    'type' => 'integer',
                    'description' => 'Id of the message template to send.',
                    'required' => true,
                ],
                'assignmentids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Ids of the assignments the message is sent for (max '
                        . self::MAX_ASSIGNMENTS . ').',
                    'required' => true,
                ],
            ],
            'required' => ['messageid', 'assignmentids'],
        ];
    }

    /**
     * Prompt metadata.
     *
     * @return array<string,mixed>
     */
    protected function prompt_meta(): array {
        return [
            'intent' => 'Send one taskflow message template immediately for a list of assignments.',
            'anchor_fields' => ['messageid'],
        ];
    }

    /**
     * Example input.
     *
     * @return array
     */
    public function get_example_input(): array {
        return ['messageid' => 5, 'assignmentids' => [4711, 4720]];
    }

    /**
     * Queue business identity: one send per template and assignment set.
     *
     * @param array $input
     * @return array<string,mixed>
     */
    public function build_queue_business_identity(array $input): array {
        $ids = $this->normalize_assignment_ids($input['assignmentids'] ?? null);
        sort($ids);
        return [
            'task_family' => self::TASK_NAME,
            'target' => [
                'messageid' => (int)(taskflow_input_normalizer::to_int($input['messageid'] ?? null) ?? 0),
                'assignmentids' => $ids,
            ],
        ];
    }

    /**
     * Tier-3 confirmation preview: template, recipients, skipped, the number of mails going out.
     *
     * @param array $input Prepared input.
     * @return array|null
     */
    public function describe_proposed_action(array $input): ?array {
        $lang = $this->get_output_language($input);
        $messageid = (int)(taskflow_input_normalizer::to_int($input['messageid'] ?? null) ?? 0);
        if ($messageid <= 0) {
            return null;
        }
        $plan = is_array($input['plan'] ?? null) ? (array)$input['plan'] : [];
        $willsend = 0;
        $skipped = 0;
        $recipients = [];
        foreach ($plan as $entry) {
            $entry = (array)$entry;
            if (!empty($entry['skipped'])) {
                $skipped++;
                continue;
            }
            $willsend++;
            foreach ((array)($entry['recipients'] ?? []) as $recipient) {
                $name = trim((string)(((array)$recipient)['fullname'] ?? ''));
                if ($name !== '' && !in_array($name, $recipients, true)) {
                    $recipients[] = $name;
                }
            }
        }
        $shown = array_slice($recipients, 0, 10);
        $recipienttext = implode(', ', $shown);
        if (count($recipients) > count($shown)) {
            $recipienttext .= ' (+' . (count($recipients) - count($shown)) . ')';
        }

        $rows = [
            [
                'label' => $this->localized_string('agent_preview_message_title', (object)[
                    'name' => (string)($input['messagename'] ?? ''),
                    'id' => $messageid,
                ], $lang),
                'value' => (string)($input['subject'] ?? ''),
            ],
            [
                'label' => $this->localized_string('agent_send_message_now_willsend', null, $lang),
                'value' => (string)$willsend,
            ],
            [
                'label' => $this->localized_string('agent_preview_message_to', null, $lang),
                'value' => $recipienttext,
            ],
            [
                'label' => $this->localized_string('agent_send_message_now_skipped', null, $lang),
                'value' => (string)$skipped,
            ],
            [
                'label' => $this->localized_string('agent_preview_warning', null, $lang),
                'value' => $this->localized_string('agent_send_message_now_warning', null, $lang),
            ],
        ];

        return [
            'title' => $this->localized_string('agent_send_message_now_title', (object)[
                'name' => (string)($input['messagename'] ?? ''),
                'count' => count($plan),
            ], $lang),
            'summary' => $this->localized_string('agent_send_message_now_proposed', (object)[
                'willsend' => $willsend,
                'skipped' => $skipped,
            ], $lang),
            'rows' => array_values(array_filter(
                $rows,
                static fn(array $row): bool => trim((string)$row['value']) !== ''
            )),
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
        $lang = $this->get_output_language($input);

        $messageid = taskflow_input_normalizer::to_int($input['messageid'] ?? null);
        if ($messageid === null || $messageid <= 0) {
            $errors[] = $this->localized_string('agent_invalid_messageid', null, $lang);
        }
        $ids = $this->normalize_assignment_ids($input['assignmentids'] ?? null);
        if (empty($ids)) {
            $errors[] = $this->localized_string('agent_assignmentids_required', null, $lang);
        } else if (count($ids) > self::MAX_ASSIGNMENTS) {
            $errors[] = $this->localized_string('agent_assignmentids_toomany', self::MAX_ASSIGNMENTS, $lang);
        }

        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => []];
    }

    /**
     * Preflight: resolve template and assignments, plan sent vs. skipped, ask for confirmation.
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
                    'code' => count($this->normalize_assignment_ids($input['assignmentids'] ?? null)) > self::MAX_ASSIGNMENTS
                        ? self::ISSUE_TOO_MANY
                        : self::ISSUE_NO_ASSIGNMENTS,
                    'severity' => 'needs_clarification',
                    'message' => (string)$error,
                ];
            }
            return $this->invalid($issues);
        }

        if (!has_capability(self::CAPABILITY, context_system::instance(), $userid)) {
            return $this->invalid([$this->scope_denied_issue($lang, ['field' => 'messageid'])]);
        }

        $messageid = (int)taskflow_input_normalizer::to_int($input['messageid']);
        $template = $this->load_template($messageid);
        if ($template === null) {
            return $this->invalid([
                $this->not_found_issue(
                    self::ISSUE_MESSAGE_NOT_FOUND,
                    $this->localized_string('agent_notfound_message', $messageid, $lang),
                    ['field' => 'messageid']
                ),
            ]);
        }

        $ids = $this->normalize_assignment_ids($input['assignmentids']);
        $plan = [];
        $missing = [];
        foreach ($ids as $assignmentid) {
            $assignment = $this->resolve_assignment(['assignmentid' => $assignmentid]);
            if ($assignment === null) {
                $missing[] = $assignmentid;
                continue;
            }
            $plan[] = $this->plan_entry($template, $assignment, $assignmentid, $lang);
        }

        if (!empty($missing)) {
            return $this->invalid([[
                'code' => self::ISSUE_ASSIGNMENT_UNRESOLVED,
                'severity' => 'needs_clarification',
                'field' => 'assignmentids',
                'message' => $this->localized_string(
                    'agent_notfound_assignment',
                    implode(', ', $missing),
                    $lang
                ),
            ]]);
        }

        $willsend = count(array_filter($plan, static fn(array $entry): bool => empty($entry['skipped'])));
        if ($willsend === 0) {
            return $this->invalid([[
                'code' => self::ISSUE_NOTHING_TO_SEND,
                'severity' => 'needs_clarification',
                'field' => 'assignmentids',
                'message' => $this->localized_string('agent_send_message_now_nothing', count($plan), $lang),
            ]]);
        }

        $prepared = $input;
        $prepared['messageid'] = $messageid;
        $prepared['assignmentids'] = $ids;
        $prepared['messagename'] = (string)$template->name;
        $prepared['subject'] = $this->template_subject($template);
        $prepared['plan'] = $plan;

        return $this->confirmable($prepared, [[
            'code' => self::ISSUE_CONFIRM,
            'severity' => 'needs_confirmation',
            'user_question' => $this->localized_string('agent_send_message_now_confirm', (object)[
                'name' => (string)$template->name,
                'willsend' => $willsend,
                'skipped' => count($plan) - $willsend,
            ], $lang),
        ]]);
    }

    /**
     * Execute: send per assignment and verify each {local_taskflow_sent_messages} row.
     *
     * @param array $input Prepared input.
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        global $DB;

        $lang = $this->get_output_language($input);
        $messageid = (int)(taskflow_input_normalizer::to_int($input['messageid'] ?? null) ?? 0);
        $links = $this->links(
            taskflow_result_link_builder::edit_message_url($messageid),
            ['messages', 'messages_templates']
        );

        if (!has_capability(self::CAPABILITY, context_system::instance(), $userid)) {
            return $this->error_result(
                self::ISSUE_SCOPE_DENIED,
                $this->localized_string('agent_scope_denied', null, $lang),
                ['links' => $links]
            );
        }

        $template = $this->load_template($messageid);
        if ($template === null) {
            return $this->error_result(
                self::ISSUE_MESSAGE_NOT_FOUND,
                $this->localized_string('agent_notfound_message', $messageid, $lang),
                ['links' => $links]
            );
        }

        $ids = $this->normalize_assignment_ids($input['assignmentids'] ?? null);
        if (empty($ids)) {
            return $this->error_result(
                self::ISSUE_NO_ASSIGNMENTS,
                $this->localized_string('agent_assignmentids_required', null, $lang),
                ['links' => $links]
            );
        }
        if (count($ids) > self::MAX_ASSIGNMENTS) {
            return $this->error_result(
                self::ISSUE_TOO_MANY,
                $this->localized_string('agent_assignmentids_toomany', self::MAX_ASSIGNMENTS, $lang),
                ['links' => $links]
            );
        }

        $outcomes = [];
        $recipients = [];
        $sent = 0;
        $skipped = 0;
        $failed = 0;
        foreach ($ids as $assignmentid) {
            $assignment = $this->resolve_assignment(['assignmentid' => $assignmentid]);
            if ($assignment === null) {
                $failed++;
                $outcomes[] = $this->outcome_row($assignmentid, 0, '', self::OUTCOME_ERROR, $lang, $messageid);
                continue;
            }
            $assigneeid = (int)($assignment->userid ?? 0);
            $ruleid = (int)($assignment->ruleid ?? 0);
            $fullname = $this->fullname_of($assigneeid);

            $message = $this->build_message($messageid, $assigneeid, $ruleid);
            if ($message === null) {
                $failed++;
                $outcomes[] = $this->outcome_row(
                    $assignmentid,
                    $assigneeid,
                    $fullname,
                    self::OUTCOME_ERROR,
                    $lang,
                    $messageid
                );
                continue;
            }
            if ($message->was_already_send()) {
                $skipped++;
                $outcomes[] = $this->outcome_row(
                    $assignmentid,
                    $assigneeid,
                    $fullname,
                    self::OUTCOME_SKIPPED,
                    $lang,
                    $messageid
                );
                continue;
            }

            $before = (int)$DB->count_records('local_taskflow_sent_messages', [
                'messageid' => $messageid,
                'ruleid' => $ruleid,
                'userid' => $assigneeid,
            ]);
            try {
                $message->send_and_save_message();
                $ok = (int)$DB->count_records('local_taskflow_sent_messages', [
                    'messageid' => $messageid,
                    'ruleid' => $ruleid,
                    'userid' => $assigneeid,
                ]) > $before;
            } catch (\Throwable $e) {
                $ok = false;
            }
            if ($ok) {
                $sent++;
                $outcomes[] = $this->outcome_row($assignmentid, $assigneeid, $fullname, self::OUTCOME_SENT, $lang, $messageid);
                foreach ($this->recipients_of($template, $assigneeid) as $recipient) {
                    $key = $recipient['role'] . ':' . $recipient['userid'];
                    $recipients[$key] = $recipient;
                }
            } else {
                $failed++;
                $outcomes[] = $this->outcome_row(
                    $assignmentid,
                    $assigneeid,
                    $fullname,
                    self::OUTCOME_ERROR,
                    $lang,
                    $messageid
                );
            }
        }

        $usermessage = $this->localized_string('agent_send_message_now_summary', (object)[
            'id' => $messageid,
            'name' => (string)$template->name,
            'sent' => $sent,
            'skipped' => $skipped,
            'errors' => $failed,
        ], $lang);

        $observation = [$usermessage];
        foreach ($outcomes as $outcome) {
            $observation[] = sprintf(
                '#%d %s: %s',
                $outcome['assignmentid'],
                $outcome['fullname'],
                $outcome['outcomelabel']
            );
        }

        $payload = [
            'messageid' => $messageid,
            'template' => [
                'id' => $messageid,
                'name' => (string)$template->name,
                'class' => (string)$template->class,
                'priority' => (int)$template->priority,
            ],
            'subject' => $this->template_subject($template),
            'body_html' => '',
            'recipients' => array_values($recipients),
            'placeholders_used' => [],
            'sent' => $sent > 0,
        ];

        $result = $this->base_result($failed > 0 ? self::STATUS_ERROR : self::STATUS_EXECUTED, [
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'observation_full' => implode("\n", $observation),
            'resultid' => $messageid,
            'outcomes' => $outcomes,
            'sent' => $sent,
            'skipped' => $skipped,
            'errors' => $failed,
            'links' => $links,
            'outputlang' => $lang,
            'issue_codes' => $failed > 0 ? [self::ISSUE_SEND_FAILED] : [],
            'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, [
                'Sent: ' . $sent . ', skipped: ' . $skipped . ', errors: ' . $failed,
            ]),
            'preview' => [
                'type' => taskflow_preview_renderer_factory::TYPE_MESSAGE_PREVIEW,
                'data' => $payload,
                'payload' => ['messageids' => [$messageid], 'assignmentids' => $ids],
            ],
        ]);

        return $result;
    }

    /**
     * Normalized, unique list of positive assignment ids.
     *
     * @param mixed $value
     * @return int[]
     */
    private function normalize_assignment_ids($value): array {
        $list = taskflow_input_normalizer::to_list($value);
        if ($list === null) {
            $list = is_array($value) ? $value : ($value === null ? [] : [$value]);
        }
        $ids = [];
        foreach ((array)$list as $entry) {
            $id = taskflow_input_normalizer::to_int($entry);
            if ($id !== null && $id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * Raw template record, or null when it does not exist.
     *
     * @param int $messageid
     * @return stdClass|null
     */
    private function load_template(int $messageid): ?stdClass {
        global $DB;

        if ($messageid <= 0) {
            return null;
        }
        $record = $DB->get_record('local_taskflow_messages', ['id' => $messageid], '*', IGNORE_MISSING);
        return $record ?: null;
    }

    /**
     * Subject line stored in the template ('' when unset).
     *
     * @param stdClass $template
     * @return string
     */
    private function template_subject(stdClass $template): string {
        $decoded = json_decode((string)($template->message ?? ''), false);
        return is_object($decoded) ? trim((string)($decoded->heading ?? '')) : '';
    }

    /**
     * Message object of the plugin for one assignee, or null when the type is unknown.
     *
     * @param int $messageid
     * @param int $assigneeid
     * @param int $ruleid
     * @return object|null
     */
    private function build_message(int $messageid, int $assigneeid, int $ruleid) {
        try {
            return messages_factory::instance((object)['messageid' => $messageid], $assigneeid, $ruleid, true);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Preflight plan entry: would this assignment receive the mail, and who would get it.
     *
     * @param stdClass $template
     * @param stdClass $assignment
     * @param int $assignmentid
     * @param string $lang
     * @return array
     */
    private function plan_entry(stdClass $template, stdClass $assignment, int $assignmentid, string $lang): array {
        $assigneeid = (int)($assignment->userid ?? 0);
        $ruleid = (int)($assignment->ruleid ?? 0);
        $message = $this->build_message((int)$template->id, $assigneeid, $ruleid);
        $skipped = $message === null ? true : (bool)$message->was_already_send();

        return [
            'assignmentid' => $assignmentid,
            'userid' => $assigneeid,
            'fullname' => $this->fullname_of($assigneeid),
            'ruleid' => $ruleid,
            'skipped' => $skipped,
            'reason' => $skipped
                ? $this->localized_string('agent_preview_verdict_already_sent', null, $lang)
                : '',
            'recipients' => $skipped ? [] : $this->recipients_of($template, $assigneeid),
        ];
    }

    /**
     * Recipient rows (To and CC) the template resolves for one assignee.
     *
     * @param stdClass $template
     * @param int $assigneeid
     * @return array<int,array{userid:int,fullname:string,email:string,role:string}>
     */
    private function recipients_of(stdClass $template, int $assigneeid): array {
        try {
            $operator = new message_recipient($assigneeid, $template);
            $rows = [];
            foreach ($operator->get_recepient() as $user) {
                $row = $this->recipient_row($user, 'to');
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
            foreach ($operator->get_carbon_copy() as $user) {
                $row = $this->recipient_row($user, 'cc');
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
            return $rows;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Normalized recipient row, or null for an unusable user object.
     *
     * @param mixed $user
     * @param string $role
     * @return array{userid:int,fullname:string,email:string,role:string}|null
     */
    private function recipient_row($user, string $role): ?array {
        if (!is_object($user) || empty($user->id)) {
            return null;
        }
        return [
            'userid' => (int)$user->id,
            'fullname' => fullname($user),
            'email' => (string)($user->email ?? ''),
            'role' => $role,
        ];
    }

    /**
     * One row of the send report.
     *
     * @param int $assignmentid
     * @param int $assigneeid
     * @param string $fullname
     * @param string $outcome
     * @param string $lang
     * @param int $messageid
     * @return array
     */
    private function outcome_row(
        int $assignmentid,
        int $assigneeid,
        string $fullname,
        string $outcome,
        string $lang,
        int $messageid
    ): array {
        $labels = [
            self::OUTCOME_SENT => 'agent_send_message_now_outcome_sent',
            self::OUTCOME_SKIPPED => 'agent_send_message_now_outcome_skipped',
            self::OUTCOME_ERROR => 'agent_send_message_now_outcome_error',
        ];
        return [
            'assignmentid' => $assignmentid,
            'userid' => $assigneeid,
            'fullname' => $fullname,
            'messageid' => $messageid,
            'outcome' => $outcome,
            'outcomelabel' => $this->localized_string($labels[$outcome], null, $lang),
            'url' => taskflow_result_link_builder::assignment_url($assignmentid),
        ];
    }

    /**
     * Full name of a user id ('' when unknown).
     *
     * @param int $userid
     * @return string
     */
    private function fullname_of(int $userid): string {
        if ($userid <= 0) {
            return '';
        }
        $user = \core_user::get_user($userid, '*', IGNORE_MISSING);
        return $user ? fullname($user) : '';
    }
}
