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

/**
 * Mutating skill local_taskflow.create_message_template (implementation plan §2 #30).
 *
 * Creates one message template through the editor path (validation by editmessagesmanager,
 * persistence by message_form_entity, tags by core_tag_tag); see message_template_skill_base.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_message_template_skill extends message_template_skill_base {
    /** Skill name. */
    public const TASK_NAME = 'local_taskflow.create_message_template';

    /**
     * Skill name.
     *
     * @return string
     */
    public function get_name(): string {
        return self::TASK_NAME;
    }

    /**
     * This skill inserts a new template.
     *
     * @return bool
     */
    protected function is_update(): bool {
        return false;
    }

    /**
     * Input schema.
     *
     * @return array
     */
    protected function define_schema(): array {
        return [
            'version' => 1,
            'description' => 'Create a NEW taskflow message template: the reusable mail / notification text a '
                . 'rule sends to assignees, supervisors or a specific user. The template only takes effect once '
                . 'a rule references it. Name, subject and body are mandatory; every other field follows the '
                . 'message template editor.',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'Create a reminder template that goes out 7 days before the due date',
                'Add a new message template for overdue assignments',
                'Create a mail to the supervisor when an assignment becomes overdue',
            ],
            'properties' => $this->template_properties(),
            'required' => ['name', 'subject', 'body'],
        ];
    }

    /**
     * Prompt metadata.
     *
     * @return array<string,mixed>
     */
    protected function prompt_meta(): array {
        return [
            'intent' => 'Create a taskflow message template through the message template editor.',
            'anchor_fields' => ['name'],
        ];
    }

    /**
     * Example input.
     *
     * @return array
     */
    public function get_example_input(): array {
        return [
            'name' => 'Reminder 7 days',
            'subject' => 'Reminder: <rulename>',
            'body' => '<p>Dear <firstname>, your assignment is due on <duedate>.</p>',
            'recipientrole' => ['assignee'],
            'senddirection' => 'before',
            'sendstart' => 'end',
            'senddays' => 7,
            'timeunit' => 'days',
        ];
    }

    /**
     * Tier-3 confirmation preview of the template that would be created.
     *
     * @param array $input Prepared input.
     * @return array|null
     */
    public function describe_proposed_action(array $input): ?array {
        $lang = $this->get_output_language($input);
        $values = is_array($input['formvalues'] ?? null)
            ? (array)$input['formvalues']
            : $this->build_form_values($input, null);
        $rows = $this->template_rows($values, $lang);
        if (empty($rows)) {
            return null;
        }
        $rows[] = [
            'label' => $this->localized_string('agent_preview_effect', null, $lang),
            'value' => $this->localized_string('agent_message_template_notice_unused', null, $lang),
        ];

        return [
            'title' => $this->localized_string(
                'agent_create_message_template_title',
                (string)$values['messagename'],
                $lang
            ),
            'summary' => $this->localized_string('agent_create_message_template_proposed', (object)[
                'name' => (string)$values['messagename'],
                'type' => (string)$values['messagetypes'],
            ], $lang),
            'rows' => $rows,
        ];
    }

    /**
     * Structural validation (no DB access).
     *
     * @param array $input
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>}
     */
    public function check_structure(array $input): array {
        $errors = $this->check_template_structure($input);
        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => []];
    }

    /**
     * Preflight: capability gate, structure, editor validation, confirmation.
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
                    'message' => (string)$error,
                ];
            }
            return $this->invalid($issues);
        }

        if (!has_capability(self::CAPABILITY, context_system::instance(), $userid)) {
            return $this->invalid([$this->scope_denied_issue($lang, ['field' => 'name'])]);
        }

        $values = $this->build_form_values($input, null);
        $errors = $this->editor_validation_errors($values, 0);
        if ($errors === null) {
            return $this->invalid([[
                'code' => self::ISSUE_VALIDATOR_UNAVAILABLE,
                'severity' => 'needs_clarification',
                'message' => $this->localized_string('agent_message_template_validator_unavailable', null, $lang),
            ]]);
        }
        if (!empty($errors)) {
            return $this->invalid($this->validation_issues($errors, $lang));
        }

        $prepared = $input;
        $prepared['formvalues'] = $values;

        return $this->confirmable($prepared, [[
            'code' => self::ISSUE_CONFIRM,
            'severity' => 'needs_confirmation',
            'user_question' => $this->localized_string(
                'agent_message_template_confirm',
                (string)$values['messagename'],
                $lang
            ),
        ]]);
    }

    /**
     * Execute: validate again, insert through the editor entity and verify the stored record.
     *
     * @param array $input Prepared input.
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
                ['links' => $this->template_links(0)]
            );
        }

        $values = is_array($input['formvalues'] ?? null)
            ? (array)$input['formvalues']
            : $this->build_form_values($input, null);

        $errors = $this->editor_validation_errors($values, 0);
        if ($errors === null || !empty($errors)) {
            $message = $errors === null
                ? $this->localized_string('agent_message_template_validator_unavailable', null, $lang)
                : $this->localized_string('agent_message_template_validation_failed', (object)[
                    'field' => (string)array_key_first($errors),
                    'error' => (string)reset($errors),
                ], $lang);
            return $this->error_result(
                $errors === null ? self::ISSUE_VALIDATOR_UNAVAILABLE : self::ISSUE_VALIDATION_FAILED,
                $message,
                ['links' => $this->template_links(0)]
            );
        }

        $messageid = $this->persist($values, 0);
        if ($messageid <= 0) {
            return $this->error_result(
                self::ISSUE_MESSAGE_NOT_FOUND,
                $this->localized_string('agent_message_template_not_stored', null, $lang),
                ['links' => $this->template_links(0)]
            );
        }

        $row = $this->template_row($messageid, $contextid, $userid, $lang);
        $usermessage = $this->localized_string('agent_message_template_created_summary', (object)[
            'id' => $messageid,
            'name' => (string)$values['messagename'],
        ], $lang);

        return $this->verified_result(
            [
                'id' => $messageid,
                'name' => (string)$values['messagename'],
            ],
            static function () use ($DB, $messageid): ?array {
                $record = $DB->get_record('local_taskflow_messages', ['id' => $messageid], '*', IGNORE_MISSING);
                return $record ? (array)$record : null;
            },
            [
                'detail' => $usermessage,
                'usermessage' => $usermessage,
                'observation_full' => $usermessage . "\n"
                    . $this->localized_string('agent_message_template_notice_unused', null, $lang),
                'resultid' => $messageid,
                'template' => $row,
                'links' => $this->template_links($messageid),
                'outputlang' => $lang,
                'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, [
                    'Stored message id: ' . $messageid,
                    'Message class: ' . (string)$DB->get_field('local_taskflow_messages', 'class', ['id' => $messageid]),
                ]),
                'preview' => $this->template_preview($row),
            ],
            $lang
        );
    }
}
