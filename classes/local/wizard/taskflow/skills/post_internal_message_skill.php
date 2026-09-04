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

use local_taskflow\local\internal_messages\internal_messages;
use local_taskflow\local\supervisor\supervisor;
use local_taskflow\local\wizard\engine\queue_identity_provider_interface;
use local_taskflow\local\wizard\engine\skill_risk_class;
use local_taskflow\local\wizard\taskflow\taskflow_input_normalizer;
use local_taskflow\local\wizard\taskflow\taskflow_permission_resolver;
use local_taskflow\local\wizard\taskflow\taskflow_result_link_builder;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;

/**
 * Mutating skill local_taskflow.post_internal_message (implementation plan §2 #26).
 *
 * Posts one message into the internal chat of an assignment through
 * internal_messages::set_new_assignment_message(), which stores the row, triggers the event
 * new_assignment_message (the Moodle notification of assignee and supervisor) and updates the
 * seen marker. The skill adds the setting gate (allowinternalcommunication), the scope check
 * (assignee, supervisor or admin) and the post-write verification of the stored row (plan §3.4).
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class post_internal_message_skill extends taskflow_skill_base implements queue_identity_provider_interface {
    /** Skill name. */
    public const TASK_NAME = 'local_taskflow.post_internal_message';

    /** Issue code: internal communication is switched off site wide. */
    public const ISSUE_CHAT_DISABLED = 'TASKFLOW_INTERNAL_CHAT_DISABLED';

    /** Issue code: the message text is missing. */
    public const ISSUE_TEXT_REQUIRED = 'TASKFLOW_MESSAGE_TEXT_REQUIRED';

    /** Issue code: the message awaits the user's confirmation. */
    public const ISSUE_CONFIRM_REQUIRED = 'TASKFLOW_INTERNAL_MESSAGE_CONFIRM_REQUIRED';

    /** Maximum length of the message text shown in the confirmation preview. */
    public const PREVIEW_TEXT_LENGTH = 200;

    /** Number of history entries carried in the result preview. */
    private const PREVIEW_HISTORY_LIMIT = 5;

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(false, skill_risk_class::R1);
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
            'description' => 'Post a message into the internal chat of one taskflow assignment. '
                . 'Assignee and supervisor are notified by Moodle.',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'Post "please upload your certificate" in the chat of assignment 4711',
                'Answer in the internal chat of assignment 4711 that the extension was granted',
            ],
            'properties' => [
                'assignmentid' => [
                    'type' => 'integer',
                    'description' => 'Id of the assignment (find it with local_taskflow.search_assignments).',
                    'required' => true,
                ],
                'text' => [
                    'type' => 'string',
                    'description' => 'Message text posted in the internal chat.',
                    'required' => true,
                ],
            ],
            'required' => ['assignmentid', 'text'],
        ];
    }

    /**
     * Prompt metadata.
     *
     * @return array<string,mixed>
     */
    protected function prompt_meta(): array {
        return [
            'intent' => 'Post a message in the internal chat of one identified assignment.',
            'input_fields_for_prompt' => ['assignmentid', 'text'],
            'anchor_fields' => ['assignmentid'],
        ];
    }

    /**
     * Example input for the planner contract.
     *
     * @return array
     */
    public function get_example_input(): array {
        return ['assignmentid' => 4711, 'text' => 'Please upload the missing certificate.'];
    }

    /**
     * Queue business identity for deduplication.
     *
     * @param array $input
     * @return array<string,mixed>
     */
    public function build_queue_business_identity(array $input): array {
        return [
            'task_family' => self::TASK_NAME,
            'target' => [
                'assignmentid' => taskflow_input_normalizer::to_int($input['assignmentid'] ?? null) ?? 0,
            ],
            'change' => [
                'text' => trim((string)($input['text'] ?? '')),
            ],
        ];
    }

    /**
     * Structural check.
     *
     * @param array $input
     * @return array{valid:bool,errors:string[],ambiguities:string[]}
     */
    public function check_structure(array $input): array {
        $lang = $this->get_output_language($input);
        $errors = [];
        if ((taskflow_input_normalizer::to_int($input['assignmentid'] ?? null) ?? 0) <= 0) {
            $errors[] = $this->localized_string('agent_assignmentid_required', null, $lang);
        }
        if (trim((string)($input['text'] ?? '')) === '') {
            $errors[] = $this->localized_string('agent_post_message_text_required', null, $lang);
        }
        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => []];
    }

    /**
     * Preflight: setting gate, assignment, scope, then confirmation.
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
                    'message' => (string)$error,
                ];
            }
            return $this->invalid($issues);
        }
        if (!$this->chat_enabled()) {
            return $this->invalid([[
                'code' => self::ISSUE_CHAT_DISABLED,
                'severity' => 'needs_clarification',
                'message' => $this->localized_string('agent_post_message_disabled', null, $lang),
            ]]);
        }

        $assignmentid = (int)taskflow_input_normalizer::to_int($input['assignmentid']);
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
        $scope = $this->permissions()->scope_for_assignment($assignmentid, $userid);
        if ($scope === taskflow_permission_resolver::SCOPE_NONE) {
            return $this->invalid([$this->scope_denied_issue($lang, ['field' => 'assignmentid'])]);
        }

        $prepared = $input;
        $prepared['assignmentid'] = $assignmentid;
        $prepared['text'] = trim((string)$input['text']);

        return $this->confirmable($prepared, [[
            'code' => self::ISSUE_CONFIRM_REQUIRED,
            'severity' => 'needs_confirmation',
            'user_question' => $this->localized_string('agent_post_message_confirm', (object)[
                'id' => $assignmentid,
                'fullname' => (string)$assignment->fullname,
            ], $lang),
        ]]);
    }

    /**
     * Tier-3 confirmation preview: assignment, recipients and the message text.
     *
     * @param array $input Prepared input.
     * @return array{title:string,summary:string,rows:array}|null
     */
    public function describe_proposed_action(array $input): ?array {
        $lang = $this->get_output_language($input);
        $assignmentid = taskflow_input_normalizer::to_int($input['assignmentid'] ?? null) ?? 0;
        $assignment = $this->resolve_assignment(['assignmentid' => $assignmentid]);
        $text = trim((string)($input['text'] ?? ''));
        if ($assignment === null || $text === '') {
            return null;
        }
        $recipients = $this->recipient_names($assignment);

        $rows = [
            [
                'label' => $this->localized_string('assignment', null, $lang),
                'value' => '#' . $assignmentid . ' ' . (string)$assignment->name,
            ],
            [
                'label' => $this->localized_string('agent_post_message_row_recipients', null, $lang),
                'value' => implode(', ', $recipients),
            ],
            [
                'label' => $this->localized_string('internalcommunication', null, $lang),
                'value' => \core_text::strlen($text) > self::PREVIEW_TEXT_LENGTH
                    ? rtrim(\core_text::substr($text, 0, self::PREVIEW_TEXT_LENGTH)) . '…'
                    : $text,
            ],
        ];

        return [
            'title' => $this->localized_string('agent_post_message_title', (object)[
                'id' => $assignmentid,
                'fullname' => (string)$assignment->fullname,
            ], $lang),
            'summary' => $this->localized_string('agent_post_message_summary', (object)[
                'recipients' => implode(', ', $recipients),
            ], $lang),
            'rows' => $rows,
        ];
    }

    /**
     * Execute: store the message through internal_messages and verify the row.
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
        $text = trim((string)($input['text'] ?? ''));
        $debug = $this->build_task_debug_message(self::TASK_NAME, $input);

        if (!$this->chat_enabled()) {
            return $this->error_result(
                self::ISSUE_CHAT_DISABLED,
                $this->localized_string('agent_post_message_disabled', null, $lang),
                ['debugmessage' => $debug]
            );
        }
        if ($text === '') {
            return $this->error_result(
                self::ISSUE_TEXT_REQUIRED,
                $this->localized_string('agent_post_message_text_required', null, $lang),
                ['debugmessage' => $debug]
            );
        }
        $assignment = $this->resolve_assignment(['assignmentid' => $assignmentid]);
        if ($assignment === null) {
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

        $before = (int)$DB->count_records(internal_messages::TABLENAME, ['assignmentid' => $assignmentid]);
        try {
            (new internal_messages($assignmentid))->set_new_assignment_message($text);
        } catch (\Throwable $e) {
            return $this->error_result(self::ISSUE_VERIFICATION_FAILED, $e->getMessage(), ['debugmessage' => $debug]);
        }

        $stored = $this->latest_message($assignmentid);
        $recipients = $this->recipient_names($assignment);
        $links = $this->links(
            taskflow_result_link_builder::assignment_url($assignmentid),
            ['messages_internal_communication', 'assignments_detail_page']
        );
        $usermessage = $this->localized_string('agent_post_message_result', (object)[
            'id' => $assignmentid,
            'fullname' => (string)$assignment->fullname,
            'recipients' => implode(', ', $recipients),
        ], $lang);

        $details = $this->assignment_details($assignmentid, $contextid, $userid, $lang);
        $observation = [
            $usermessage,
            'assignmentid=' . $assignmentid . ', internalmessageid=' . (int)($stored['id'] ?? 0)
                . ', messages_before=' . $before . ', messages_after=' . ($before + 1),
            'recipients=' . implode(', ', $recipients),
            'text=' . $text,
        ];

        return $this->verified_result(
            ['assignmentid' => $assignmentid, 'message' => $text],
            fn(): array => $stored,
            [
                'detail' => $usermessage,
                'usermessage' => $usermessage,
                'observation_full' => implode("\n", $observation),
                'resultid' => (int)($stored['id'] ?? 0),
                'assignment' => $details['assignment'] ?? [],
                'internalmessageid' => (int)($stored['id'] ?? 0),
                'messages_total' => $before + 1,
                'recipients' => $recipients,
                'links' => $links,
                'debugmessage' => $debug,
                'preview' => $details['preview'],
            ],
            $lang
        );
    }

    /**
     * Whether internal communication is switched on site wide.
     *
     * @return bool
     */
    private function chat_enabled(): bool {
        return !empty((int)get_config('local_taskflow', 'allowinternalcommunication'));
    }

    /**
     * Newest stored chat row of the assignment ([] when none).
     *
     * @param int $assignmentid
     * @return array<string,mixed>
     */
    private function latest_message(int $assignmentid): array {
        global $DB;

        $records = $DB->get_records(
            internal_messages::TABLENAME,
            ['assignmentid' => $assignmentid],
            'id DESC',
            '*',
            0,
            1
        );
        $record = reset($records);
        return $record ? (array)$record : [];
    }

    /**
     * People notified by the chat message: the assignee and, where set, the supervisor.
     *
     * @param \stdClass $assignment Assignment data.
     * @return string[]
     */
    private function recipient_names(\stdClass $assignment): array {
        $names = [];
        $fullname = trim((string)($assignment->fullname ?? ''));
        if ($fullname !== '') {
            $names[] = $fullname;
        }
        try {
            $supervisor = supervisor::get_supervisor_for_user((int)($assignment->userid ?? 0));
        } catch (\Throwable $e) {
            $supervisor = null;
        }
        if (is_object($supervisor) && !empty($supervisor->id)) {
            $names[] = fullname($supervisor);
        }
        return $names;
    }

    /**
     * Assignment payload and preview block, reused from the read-only details skill.
     *
     * @param int $assignmentid
     * @param int $contextid
     * @param int $userid
     * @param string $lang
     * @return array{assignment:array,preview:array|null}
     */
    private function assignment_details(int $assignmentid, int $contextid, int $userid, string $lang): array {
        try {
            $details = (new get_assignment_details_skill())->execute([
                'assignmentid' => $assignmentid,
                'historylimit' => self::PREVIEW_HISTORY_LIMIT,
                'outputlang' => $lang,
            ], $contextid, $userid);
        } catch (\Throwable $e) {
            return ['assignment' => [], 'preview' => null];
        }
        return [
            'assignment' => is_array($details['assignment'] ?? null) ? (array)$details['assignment'] : [],
            'preview' => is_array($details['preview'] ?? null) ? (array)$details['preview'] : null,
        ];
    }
}
