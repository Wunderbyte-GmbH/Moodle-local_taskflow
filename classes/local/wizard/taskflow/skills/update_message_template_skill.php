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
use local_taskflow\local\messages_form\message_form_entity;
use local_taskflow\local\wizard\taskflow\taskflow_input_normalizer;
use stdClass;

/**
 * Mutating skill local_taskflow.update_message_template (implementation plan §2 #30).
 *
 * Updates ONE existing message template: every field that is not given keeps the stored value,
 * read back with message_form_entity::prepare_record_for_form(). Validation and persistence use
 * the editor path (see message_template_skill_base), so nothing is re-implemented here.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_message_template_skill extends message_template_skill_base {
    /** Skill name. */
    public const TASK_NAME = 'local_taskflow.update_message_template';

    /**
     * Skill name.
     *
     * @return string
     */
    public function get_name(): string {
        return self::TASK_NAME;
    }

    /**
     * This skill updates an existing template.
     *
     * @return bool
     */
    protected function is_update(): bool {
        return true;
    }

    /**
     * Input schema: the template id plus the fields that should change.
     *
     * @return array
     */
    protected function define_schema(): array {
        $properties = ['messageid' => [
            'type' => 'integer',
            'description' => 'Id of the message template to update.',
            'required' => true,
        ]] + $this->template_properties();

        return [
            'version' => 1,
            'description' => 'Update an EXISTING taskflow message template. Only the fields that are handed over '
                . 'change; everything else keeps its stored value. Rules that reference the template use the new '
                . 'text for future sendings.',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'Change the subject of message template 5',
                'Send template 5 three days earlier',
                'Add the supervisor as CC to the reminder template',
                'Rename message template 5',
            ],
            'properties' => $properties,
            'required' => ['messageid'],
        ];
    }

    /**
     * Prompt metadata.
     *
     * @return array<string,mixed>
     */
    protected function prompt_meta(): array {
        return [
            'intent' => 'Change single fields of an existing taskflow message template.',
            'anchor_fields' => ['messageid'],
        ];
    }

    /**
     * Example input.
     *
     * @return array
     */
    public function get_example_input(): array {
        return ['messageid' => 5, 'senddays' => 3];
    }

    /**
     * Tier-3 confirmation preview: only the fields that actually change (old to new).
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
        $stored = $this->stored_record($messageid);
        $before = $this->build_form_values([], $stored);
        $values = is_array($input['formvalues'] ?? null)
            ? (array)$input['formvalues']
            : $this->build_form_values($input, $stored);

        $rows = [];
        foreach ($this->template_rows($values, $lang) as $row) {
            $rows[] = $row;
        }
        $changes = $this->changed_fields($before, $values);
        if (!empty($changes)) {
            $rows[] = [
                'label' => $this->localized_string('agent_preview_change', null, $lang),
                'value' => implode('; ', $changes),
            ];
        }
        $usage = (int)($input['usagecount'] ?? 0);
        $rows[] = [
            'label' => $this->localized_string('agent_preview_effect', null, $lang),
            'value' => $usage > 0
                ? $this->localized_string('agent_message_template_notice_used', $usage, $lang)
                : $this->localized_string('agent_message_template_notice_unused', null, $lang),
        ];

        return [
            'title' => $this->localized_string('agent_update_message_template_title', (object)[
                'id' => $messageid,
                'name' => (string)$values['messagename'],
            ], $lang),
            'summary' => $this->localized_string('agent_update_message_template_proposed', (object)[
                'id' => $messageid,
                'count' => count($changes),
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
        $messageid = taskflow_input_normalizer::to_int($input['messageid'] ?? null);
        if ($messageid === null || $messageid <= 0) {
            $errors[] = $this->localized_string(
                'agent_invalid_messageid',
                null,
                $this->get_output_language($input)
            );
        }
        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => []];
    }

    /**
     * Preflight: capability, existence, merged values, editor validation, confirmation.
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
            return $this->invalid([$this->scope_denied_issue($lang, ['field' => 'messageid'])]);
        }

        $messageid = (int)taskflow_input_normalizer::to_int($input['messageid']);
        $stored = $this->stored_record($messageid);
        if ($stored === null) {
            return $this->invalid([
                $this->not_found_issue(
                    self::ISSUE_MESSAGE_NOT_FOUND,
                    $this->localized_string('agent_notfound_message', $messageid, $lang),
                    ['field' => 'messageid']
                ),
            ]);
        }

        $values = $this->build_form_values($input, $stored);
        $errors = $this->editor_validation_errors($values, $messageid);
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
        $prepared['messageid'] = $messageid;
        $prepared['formvalues'] = $values;
        $currentrow = $this->template_row($messageid, $contextid, $userid, $lang);
        $prepared['usagecount'] = count((array)($currentrow['used_in_rules'] ?? []));

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
     * Execute: update through the editor entity and verify the stored record.
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
        if (!has_capability(self::CAPABILITY, context_system::instance(), $userid)) {
            return $this->error_result(
                self::ISSUE_SCOPE_DENIED,
                $this->localized_string('agent_scope_denied', null, $lang),
                ['links' => $this->template_links($messageid)]
            );
        }

        $stored = $this->stored_record($messageid);
        if ($stored === null) {
            return $this->error_result(
                self::ISSUE_MESSAGE_NOT_FOUND,
                $this->localized_string('agent_notfound_message', $messageid, $lang),
                ['links' => $this->template_links(0)]
            );
        }

        $values = is_array($input['formvalues'] ?? null)
            ? (array)$input['formvalues']
            : $this->build_form_values($input, $stored);

        $errors = $this->editor_validation_errors($values, $messageid);
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
                ['links' => $this->template_links($messageid)]
            );
        }

        $before = $this->build_form_values([], $stored);
        $storedid = $this->persist($values, $messageid);
        if ($storedid !== $messageid) {
            return $this->error_result(
                self::ISSUE_MESSAGE_NOT_FOUND,
                $this->localized_string('agent_message_template_not_stored', null, $lang),
                ['links' => $this->template_links($messageid)]
            );
        }

        $row = $this->template_row($messageid, $contextid, $userid, $lang);
        $changes = $this->changed_fields($before, $values);
        $usage = count((array)($row['used_in_rules'] ?? []));
        $usermessage = $this->localized_string('agent_message_template_updated_summary', (object)[
            'id' => $messageid,
            'name' => (string)$values['messagename'],
        ], $lang);
        $notice = $usage > 0
            ? $this->localized_string('agent_message_template_notice_used', $usage, $lang)
            : $this->localized_string('agent_message_template_notice_unused', null, $lang);

        return $this->verified_result(
            [
                'id' => $messageid,
                'name' => (string)$values['messagename'],
                'heading' => (string)$values['heading'],
            ],
            static function () use ($messageid): ?array {
                $data = (new message_form_entity())->prepare_record_for_form($messageid);
                if ($data === null) {
                    return null;
                }
                return [
                    'id' => (int)$data->id,
                    'name' => (string)$data->messagename,
                    'heading' => (string)$data->heading,
                ];
            },
            [
                'detail' => $usermessage,
                'usermessage' => $usermessage,
                'observation_full' => $usermessage . "\n" . $notice
                    . (empty($changes) ? '' : "\n" . implode('; ', $changes)),
                'resultid' => $messageid,
                'template' => $row,
                'changes' => $changes,
                'links' => $this->template_links($messageid),
                'outputlang' => $lang,
                'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, [
                    'Changed fields: ' . (empty($changes) ? '-' : implode(', ', array_keys($changes))),
                    'Message class: ' . (string)$DB->get_field('local_taskflow_messages', 'class', ['id' => $messageid]),
                ]),
                'preview' => $this->template_preview($row),
            ],
            $lang
        );
    }

    /**
     * Stored template as the editor reads it, or null when it does not exist.
     *
     * @param int $messageid
     * @return stdClass|null
     */
    private function stored_record(int $messageid): ?stdClass {
        if ($messageid <= 0) {
            return null;
        }
        $data = (new message_form_entity())->prepare_record_for_form($messageid);
        return $data === null ? null : $data;
    }

    /**
     * Field wise "old to new" list of the values that actually change.
     *
     * @param array $before
     * @param array $after
     * @return array<string,string>
     */
    private function changed_fields(array $before, array $after): array {
        $changes = [];
        foreach ($after as $key => $value) {
            $old = $before[$key] ?? null;
            $oldtext = is_array($old) ? implode(', ', $old) : (string)$old;
            $newtext = is_array($value) ? implode(', ', $value) : (string)$value;
            if ($oldtext === $newtext) {
                continue;
            }
            $changes[(string)$key] = $key . ': ' . ($oldtext === '' ? '-' : $oldtext)
                . ' -> ' . ($newtext === '' ? '-' : $newtext);
        }
        return $changes;
    }
}
