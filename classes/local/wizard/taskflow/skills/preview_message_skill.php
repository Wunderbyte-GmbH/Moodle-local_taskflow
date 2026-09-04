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
use local_taskflow\local\messages\placeholders\placeholders_factory;
use local_taskflow\local\wizard\engine\skill_risk_class;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\taskflow_input_normalizer;
use local_taskflow\local\wizard\taskflow\taskflow_result_link_builder;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use stdClass;

/**
 * Skill local_taskflow.preview_message: render one message template for one assignment (plan §2 #10).
 *
 * Read-only, R0, native capability local/taskflow:editmessages. The template is decoded exactly
 * like message_base::set_message() does, the placeholders are resolved with the real
 * placeholders_factory for the assignment's rule/user, and recipients plus CC come from
 * message_recipient. NOTHING is sent: no e-mail, no notification, no history entry and no row in
 * {local_taskflow_sent_messages}.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preview_message_skill extends taskflow_skill_base {
    /** Skill name. */
    public const TASK_NAME = 'local_taskflow.preview_message';

    /** Native capability required to read message templates. */
    public const CAPABILITY = 'local/taskflow:editmessages';

    /** Issue code: message template does not exist. */
    public const ISSUE_MESSAGE_NOT_FOUND = 'TASKFLOW_MESSAGE_NOT_FOUND';

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(true, skill_risk_class::R0, [self::CAPABILITY]);
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
            'description' => 'Render ONE taskflow message template for ONE concrete assignment and show the '
                . 'result: subject and body with all placeholders (first name, due date, status, targets, ...) '
                . 'replaced by the real values of that assignment, plus the recipients and CC recipients that '
                . 'would receive it. This is a pure preview: no mail, no notification and no send-log entry is '
                . 'created. Needs the template id (use search_message_templates) and the assignment id.',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'How does message template 5 look for assignment 4711?',
                'Preview the reminder mail for assignment 4711',
                'Show me the rendered text of template 5 for this assignment',
                'Who would receive message 5 for assignment 4711?',
            ],
            'properties' => [
                'messageid' => [
                    'type' => 'integer',
                    'description' => 'Id of the message template.',
                    'required' => true,
                ],
                'assignmentid' => [
                    'type' => 'integer',
                    'description' => 'Id of the assignment the placeholders are resolved for.',
                    'required' => true,
                ],
            ],
            'required' => ['messageid', 'assignmentid'],
        ];
    }

    /**
     * Prompt metadata.
     *
     * @return array<string,mixed>
     */
    protected function prompt_meta(): array {
        return [
            'intent' => 'Render a message template with resolved placeholders for one assignment without sending it.',
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

        $messageid = taskflow_input_normalizer::to_int($input['messageid'] ?? null);
        if ($messageid === null || $messageid <= 0) {
            $errors[] = $this->localized_string('agent_invalid_messageid', null, $lang);
        }
        $assignmentid = taskflow_input_normalizer::to_int($input['assignmentid'] ?? null);
        if ($assignmentid === null || $assignmentid <= 0) {
            $errors[] = $this->localized_string('agent_assignmentid_required', null, $lang);
        }

        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => []];
    }

    /**
     * Preflight: capability gate, structure check, existence of template and assignment.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array{status:string,prepared_input:array,issues:array}
     */
    protected function run_preflight(array $input, int $contextid, int $userid): array {
        $lang = $this->get_output_language($input);
        if (!has_capability(self::CAPABILITY, context_system::instance(), $userid)) {
            return $this->invalid([$this->scope_denied_issue($lang, ['field' => 'messageid'])]);
        }

        $structure = $this->check_structure($input);
        if (!($structure['valid'] ?? false)) {
            $issues = [];
            foreach ((array)($structure['errors'] ?? []) as $error) {
                $issues[] = [
                    'code' => 'VALIDATION_ERROR',
                    'severity' => 'needs_clarification',
                    'message' => (string)$error,
                ];
            }
            return $this->invalid($issues);
        }

        $messageid = (int)taskflow_input_normalizer::to_int($input['messageid']);
        $assignmentid = (int)taskflow_input_normalizer::to_int($input['assignmentid']);

        if ($this->load_template($messageid) === null) {
            return $this->invalid([
                $this->not_found_issue(
                    self::ISSUE_MESSAGE_NOT_FOUND,
                    $this->localized_string('agent_notfound_message', $messageid, $lang),
                    ['field' => 'messageid']
                ),
            ]);
        }
        if ($this->resolve_assignment(['assignmentid' => $assignmentid]) === null) {
            return $this->invalid([
                $this->not_found_issue(
                    self::ISSUE_ASSIGNMENT_NOT_FOUND,
                    $this->localized_string('agent_notfound_assignment', $assignmentid, $lang),
                    ['field' => 'assignmentid']
                ),
            ]);
        }

        $input['messageid'] = $messageid;
        $input['assignmentid'] = $assignmentid;
        return $this->pass($input);
    }

    /**
     * Execute: render the template for the assignment (no side effect whatsoever).
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        $lang = $this->get_output_language($input);
        if (!has_capability(self::CAPABILITY, context_system::instance(), $userid)) {
            return $this->error_result(
                self::ISSUE_SCOPE_DENIED,
                $this->localized_string('agent_scope_denied', null, $lang),
                ['links' => $this->links(null, ['messages', 'messages_placeholders'])]
            );
        }

        $messageid = (int)(taskflow_input_normalizer::to_int($input['messageid'] ?? null) ?? 0);
        $assignmentid = (int)(taskflow_input_normalizer::to_int($input['assignmentid'] ?? null) ?? 0);

        $template = $this->load_template($messageid);
        if ($template === null) {
            return $this->error_result(
                self::ISSUE_MESSAGE_NOT_FOUND,
                $this->localized_string('agent_notfound_message', $messageid, $lang),
                ['links' => $this->links(taskflow_result_link_builder::edit_message_url(), ['messages_templates'])]
            );
        }
        $assignment = $this->resolve_assignment(['assignmentid' => $assignmentid]);
        if ($assignment === null) {
            return $this->error_result(
                self::ISSUE_ASSIGNMENT_NOT_FOUND,
                $this->localized_string('agent_notfound_assignment', $assignmentid, $lang),
                ['links' => $this->links(null, ['assignments'])]
            );
        }

        $assigneeid = (int)($assignment->userid ?? 0);
        $ruleid = (int)($assignment->ruleid ?? 0);

        $rendered = $this->decode_message($template);
        $placeholders = $this->placeholders_in($rendered);
        if (!empty($placeholders)) {
            $rendered = placeholders_factory::render_placeholders($rendered, $ruleid, $assigneeid, $assignment);
        }

        $subject = trim((string)($rendered->message->heading ?? ''));
        $bodyhtml = $this->sanitise_body((string)($rendered->message->body ?? ''));

        $recipientoperator = new message_recipient($assigneeid, $rendered);
        $recipients = $this->recipient_rows($recipientoperator->get_recepient(), 'to');
        $ccrows = $this->recipient_rows($recipientoperator->get_carbon_copy(), 'cc');
        $all = array_merge($recipients, $ccrows);

        $usermessage = $this->localized_string('agent_preview_message_summary', (object)[
            'id' => $messageid,
            'name' => (string)$template->name,
            'assignmentid' => $assignmentid,
            'recipients' => count($recipients),
            'cc' => count($ccrows),
        ], $lang);

        $payload = [
            'messageid' => $messageid,
            'assignmentid' => $assignmentid,
            'ruleid' => $ruleid,
            'userid' => $assigneeid,
            'template' => [
                'id' => $messageid,
                'name' => (string)$template->name,
                'class' => (string)$template->class,
                'priority' => (int)$template->priority,
            ],
            'subject' => $subject,
            'body_html' => $bodyhtml,
            'recipients' => $all,
            'placeholders_used' => $placeholders,
            'sent' => false,
        ];

        $links = $this->links(
            taskflow_result_link_builder::assignment_url($assignmentid),
            ['messages', 'messages_placeholders', 'messages_templates'],
            ['message' => taskflow_result_link_builder::edit_message_url($messageid)]
        );

        $debug = $this->build_task_debug_message(self::TASK_NAME, $input, [
            'Recipients: ' . count($recipients) . ', CC: ' . count($ccrows),
            'Placeholders: ' . implode(', ', $placeholders),
            'Nothing was sent and no sent_messages row was written.',
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
                'type' => taskflow_preview_renderer_factory::TYPE_MESSAGE_PREVIEW,
                'data' => $payload,
                'payload' => ['messageids' => [$messageid], 'assignmentids' => [$assignmentid]],
            ],
        ]);
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
        $record = $DB->get_record('local_taskflow_messages', ['id' => $messageid]);
        return $record ?: null;
    }

    /**
     * Decode the template the way message_base::set_message() does (on a copy of the record).
     *
     * @param stdClass $template
     * @return stdClass Copy whose ->message is the decoded {heading, body} object.
     */
    private function decode_message(stdClass $template): stdClass {
        $copy = clone $template;
        $decoded = json_decode((string)($template->message ?? ''), false);
        if (!is_object($decoded)) {
            $decoded = (object)['heading' => '', 'body' => ''];
        }
        foreach ($decoded as $key => $part) {
            if (is_object($part) && isset($part->text)) {
                $part = $part->text;
            }
            if (!is_string($part)) {
                continue;
            }
            $decoded->{$key} = html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $copy->message = $decoded;
        return $copy;
    }

    /**
     * Placeholder names present in the template that have an implementing class.
     *
     * Mirrors placeholders_factory::get_placeholder() but returns the plain names.
     *
     * @param stdClass $message Decoded template (see decode_message()).
     * @return string[] e.g. ['<firstname>', '<due_date>']
     */
    private function placeholders_in(stdClass $message): array {
        $names = [];
        foreach ((array)$message->message as $part) {
            if (!is_string($part)) {
                continue;
            }
            preg_match_all('/<([a-zA-Z0-9_]+)(?:\s+(?:de|en|fr))?>/', $part, $matches);
            foreach ((array)$matches[1] as $name) {
                $class = 'local_taskflow\\local\\messages\\placeholders\\types\\' . $name;
                if (class_exists($class) && !in_array('<' . $name . '>', $names, true)) {
                    $names[] = '<' . $name . '>';
                }
            }
        }
        sort($names);
        return $names;
    }

    /**
     * Clean the body the way Moodle prepares HTML mail content.
     *
     * @param string $body
     * @return string
     */
    private function sanitise_body(string $body): string {
        return (string)format_text($body, FORMAT_HTML, [
            'context' => context_system::instance(),
            'para' => false,
            'newlines' => false,
        ]);
    }

    /**
     * Normalized recipient rows.
     *
     * @param array $users
     * @param string $role 'to' or 'cc'
     * @return array<int,array{userid:int,fullname:string,email:string,role:string,deliverable:bool}>
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
                'deliverable' => $email !== '' && validate_email($email)
                    && empty($user->suspended) && empty($user->deleted) && empty($user->emailstop),
            ];
        }
        return $rows;
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
        return $usermessage . "\n\nMessage preview payload (JSON):\n" . $json;
    }
}
