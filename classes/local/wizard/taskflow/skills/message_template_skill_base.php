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
use core_tag_tag;
use local_taskflow\local\messages\messages_facade;
use local_taskflow\local\messages\sending_condition\sending_condition_facade;
use local_taskflow\local\messages\types\request;
use local_taskflow\local\messages_form\editmessagesmanager;
use local_taskflow\local\messages_form\message_form_entity;
use local_taskflow\local\wizard\engine\queue_identity_provider_interface;
use local_taskflow\local\wizard\engine\skill_risk_class;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\taskflow_input_normalizer;
use local_taskflow\local\wizard\taskflow\taskflow_result_link_builder;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use stdClass;

/**
 * Shared implementation of the message template mutation skills (plan §2 #30).
 *
 * Both local_taskflow.create_message_template and local_taskflow.update_message_template write
 * through the real editor path and never re-implement it:
 * - editmessagesmanager::validation() decides whether the data is acceptable; its errors become
 *   preflight issues, so an invalid template is refused BEFORE anything is written;
 * - message_form_entity::prepare_message_from_form() performs the insert / update and returns
 *   the id, message_form_entity::prepare_record_for_form() reads the stored record back;
 * - tags (the "message package") are written with core_tag_tag::set_item_tags(), exactly like
 *   the editor page does.
 * The preview rows are rendered with the existing taskflow_message_template_list renderer; its
 * row data comes from the read-only skill local_taskflow.search_message_templates, so neither
 * the timing wording nor the rule usage scan is duplicated here.
 *
 * This class is abstract, so the skill discovery ignores it.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class message_template_skill_base extends taskflow_skill_base implements queue_identity_provider_interface {
    /** Capability of the message template editor. */
    public const CAPABILITY = 'local/taskflow:editmessages';

    /** Issue code: the editor validation rejected the data. */
    public const ISSUE_VALIDATION_FAILED = 'TASKFLOW_MESSAGE_TEMPLATE_INVALID';

    /** Issue code: the message template does not exist. */
    public const ISSUE_MESSAGE_NOT_FOUND = 'TASKFLOW_MESSAGE_NOT_FOUND';

    /** Issue code: the editor form could not be instantiated, so nothing was validated. */
    public const ISSUE_VALIDATOR_UNAVAILABLE = 'TASKFLOW_MESSAGE_VALIDATOR_UNAVAILABLE';

    /** Issue code: the mutation needs an explicit confirmation. */
    public const ISSUE_CONFIRM = 'TASKFLOW_MESSAGE_TEMPLATE_CONFIRM_REQUIRED';

    /** Recipient roles offered by the editor for the recipient list. */
    public const RECIPIENT_ROLES = ['assignee', 'supervisor', 'specificuser'];

    /** Recipient roles offered by the editor for the carbon copy list. */
    public const CARBONCOPY_ROLES = ['assignee', 'supervisor', 'ccspecificuser'];

    /** Time units offered by the editor. */
    public const TIME_UNITS = ['minutes', 'hours', 'days'];

    /** Sending directions offered by the editor. */
    public const SEND_DIRECTIONS = ['before', 'after'];

    /** Editor defaults used for the fields a create call leaves out. */
    private const CREATE_DEFAULTS = [
        'messagetypes' => 'standard',
        'messagename' => '',
        'heading' => '',
        'body' => '',
        'priority' => 2,
        'recipientrole' => [],
        'userid' => 0,
        'carboncopyrole' => [],
        'ccuserid' => 0,
        'senddirection' => 'after',
        'sendstart' => '',
        'sendstartrequest' => '',
        'senddays' => '',
        'timeunit' => 'days',
        'eventlist' => [],
        'sendingcondition' => '',
        'tags' => [],
    ];

    /**
     * Constructor: mutating, R2, hard capability gate on local/taskflow:editmessages.
     */
    public function __construct() {
        parent::__construct(false, skill_risk_class::R2, [self::CAPABILITY]);
    }

    /**
     * Whether the concrete skill updates an existing template.
     *
     * @return bool
     */
    abstract protected function is_update(): bool;

    /**
     * Schema properties shared by both skills (editor fields).
     *
     * @return array<string,array>
     */
    protected function template_properties(): array {
        return [
            'name' => [
                'type' => 'string',
                'description' => 'Internal name of the template shown in the template list.',
                'required' => !$this->is_update(),
            ],
            'type' => [
                'type' => 'string',
                'enum' => array_keys($this->message_types()),
                'description' => 'Message type: standard (scheduled or status driven), request (self service '
                    . 'requests) or chat (internal communication).',
                'required' => false,
            ],
            'subject' => [
                'type' => 'string',
                'description' => 'Subject line of the mail / notification. Placeholders like <firstname> are '
                    . 'resolved when the message is sent.',
                'required' => !$this->is_update(),
            ],
            'body' => [
                'type' => 'string',
                'description' => 'HTML body of the message; may contain the taskflow placeholders.',
                'required' => !$this->is_update(),
            ],
            'recipientrole' => [
                'type' => 'array',
                'items' => ['type' => 'string', 'enum' => self::RECIPIENT_ROLES],
                'description' => 'Recipients: assignee, supervisor and/or specificuser.',
                'required' => false,
            ],
            'specificuser' => [
                'type' => 'integer',
                'description' => 'User id addressed when recipientrole contains specificuser.',
                'required' => false,
            ],
            'carboncopyrole' => [
                'type' => 'array',
                'items' => ['type' => 'string', 'enum' => self::CARBONCOPY_ROLES],
                'description' => 'Carbon copy recipients: assignee, supervisor and/or ccspecificuser.',
                'required' => false,
            ],
            'specificcc' => [
                'type' => 'integer',
                'description' => 'User id addressed when carboncopyrole contains ccspecificuser.',
                'required' => false,
            ],
            'package' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Tags of the template (the message package).',
                'required' => false,
            ],
            'priority' => [
                'type' => 'integer',
                'description' => 'Priority: 1 = low, 2 = medium, 3 = high.',
                'required' => false,
            ],
            'senddirection' => [
                'type' => 'string',
                'enum' => self::SEND_DIRECTIONS,
                'description' => 'Whether the message goes out before or after the anchor date.',
                'required' => false,
            ],
            'sendstart' => [
                'type' => 'string',
                'description' => 'Anchor of a standard message: start, end or status_change.',
                'required' => false,
            ],
            'sendstartrequest' => [
                'type' => 'string',
                'description' => 'Anchor of a request message: onrequestcreated or onrequestclosed.',
                'required' => false,
            ],
            'senddays' => [
                'type' => 'integer',
                'description' => 'Distance to the anchor, counted in the unit given by timeunit.',
                'required' => false,
            ],
            'timeunit' => [
                'type' => 'string',
                'enum' => self::TIME_UNITS,
                'description' => 'Unit of senddays: minutes, hours or days.',
                'required' => false,
            ],
            'eventlist' => [
                'type' => 'array',
                'items' => ['type' => 'integer'],
                'description' => 'Assignment status ids that trigger the message when sendstart is status_change.',
                'required' => false,
            ],
            'sendingcondition' => [
                'type' => 'string',
                'enum' => array_keys($this->sending_conditions()),
                'description' => 'Sending condition of the template.',
                'required' => false,
            ],
        ];
    }

    /**
     * Queue business identity: one template per name / id.
     *
     * @param array $input
     * @return array<string,mixed>
     */
    public function build_queue_business_identity(array $input): array {
        return [
            'task_family' => $this->get_name(),
            'target' => [
                'messageid' => (int)(taskflow_input_normalizer::to_int($input['messageid'] ?? null) ?? 0),
                'name' => strtolower(trim((string)($input['name'] ?? ''))),
            ],
        ];
    }

    /**
     * Structural validation (no DB access) of the fields both skills share.
     *
     * @param array $input
     * @return array<int,string>
     */
    protected function check_template_structure(array $input): array {
        $errors = [];
        $lang = $this->get_output_language($input);

        if (!$this->is_update() || array_key_exists('name', $input)) {
            if (trim((string)($input['name'] ?? '')) === '' && !$this->is_update()) {
                $errors[] = $this->localized_string('agent_message_template_name_required', null, $lang);
            }
        }
        if (!$this->is_update()) {
            if (trim((string)($input['subject'] ?? '')) === '') {
                $errors[] = $this->localized_string('agent_message_template_subject_required', null, $lang);
            }
            if (trim((string)($input['body'] ?? '')) === '') {
                $errors[] = $this->localized_string('agent_message_template_body_required', null, $lang);
            }
        }
        $type = trim((string)($input['type'] ?? ''));
        if ($type !== '' && !array_key_exists($type, $this->message_types())) {
            $errors[] = $this->localized_string('agent_invalid_messagetype', $type, $lang);
        }

        return $errors;
    }

    /**
     * Merge the given input over the stored record (update) or the editor defaults (create).
     *
     * @param array $input
     * @param stdClass|null $stored Result of message_form_entity::prepare_record_for_form().
     * @return array<string,mixed> Form data keyed by the editor field names.
     */
    protected function build_form_values(array $input, ?stdClass $stored): array {
        $values = self::CREATE_DEFAULTS;
        if ($stored !== null) {
            $values['messagetypes'] = (string)($stored->messagetypes ?? $values['messagetypes']);
            $values['messagename'] = (string)($stored->messagename ?? '');
            $values['heading'] = (string)($stored->heading ?? '');
            $values['body'] = (string)(is_array($stored->body ?? null) ? ($stored->body['text'] ?? '') : '');
            $values['priority'] = (int)($stored->priority ?? 2);
            $values['recipientrole'] = array_values((array)($stored->recipientrole ?? []));
            $values['userid'] = (int)($stored->userid ?? 0);
            $values['carboncopyrole'] = array_values((array)($stored->carboncopyrole ?? []));
            $values['ccuserid'] = (int)($stored->ccuserid ?? 0);
            $values['senddirection'] = (string)($stored->senddirection ?? $values['senddirection']);
            $values['sendstart'] = (string)($stored->sendstart ?? '');
            $values['sendstartrequest'] = (string)($stored->sendstartrequest ?? '');
            $values['senddays'] = (string)($stored->senddays ?? '');
            $values['timeunit'] = (string)($stored->timeunit ?? $values['timeunit']);
            $values['eventlist'] = array_values(array_map('intval', (array)($stored->eventlist ?? [])));
            $values['sendingcondition'] = (string)($stored->sendingcondition ?? '');
            $values['tags'] = array_values(array_map('strval', (array)($stored->tags ?? [])));
        }
        if ($values['sendingcondition'] === '') {
            $conditions = array_keys($this->sending_conditions());
            $values['sendingcondition'] = (string)($conditions[0] ?? '');
        }

        $map = [
            'name' => 'messagename',
            'type' => 'messagetypes',
            'subject' => 'heading',
            'body' => 'body',
            'specificuser' => 'userid',
            'specificcc' => 'ccuserid',
            'package' => 'tags',
            'priority' => 'priority',
            'senddirection' => 'senddirection',
            'sendstart' => 'sendstart',
            'sendstartrequest' => 'sendstartrequest',
            'senddays' => 'senddays',
            'timeunit' => 'timeunit',
            'sendingcondition' => 'sendingcondition',
            'recipientrole' => 'recipientrole',
            'carboncopyrole' => 'carboncopyrole',
            'eventlist' => 'eventlist',
        ];
        foreach ($map as $inputkey => $formkey) {
            if (!array_key_exists($inputkey, $input)) {
                continue;
            }
            $value = $input[$inputkey];
            switch ($formkey) {
                case 'recipientrole':
                case 'carboncopyrole':
                case 'tags':
                    $values[$formkey] = array_values(array_filter(array_map(
                        static fn($entry): string => trim((string)$entry),
                        (array)(taskflow_input_normalizer::to_list($value) ?? (array)$value)
                    ), 'strlen'));
                    break;
                case 'eventlist':
                    $values[$formkey] = array_values(array_map(
                        'intval',
                        (array)(taskflow_input_normalizer::to_list($value) ?? (array)$value)
                    ));
                    break;
                case 'priority':
                case 'userid':
                case 'ccuserid':
                    $values[$formkey] = (int)(taskflow_input_normalizer::to_int($value) ?? 0);
                    break;
                case 'senddays':
                    $values[$formkey] = (string)(taskflow_input_normalizer::to_int($value) ?? '');
                    break;
                default:
                    $values[$formkey] = (string)$value;
                    break;
            }
        }

        return $values;
    }

    /**
     * Errors reported by the editor's own validation for the given form values.
     *
     * @param array $values Form values (see build_form_values()).
     * @param int $messageid
     * @return array<string,string>|null Null when the editor form could not be instantiated.
     */
    protected function editor_validation_errors(array $values, int $messageid): ?array {
        try {
            $form = new editmessagesmanager(null, [], 'post', '', null, true, []);
        } catch (\Throwable $e) {
            return null;
        }
        $data = $values;
        $data['id'] = $messageid;
        $data['body'] = ['text' => (string)$values['body'], 'format' => FORMAT_HTML];
        try {
            $errors = $form->validation($data, []);
        } catch (\Throwable $e) {
            return null;
        }
        return array_map('strval', (array)$errors);
    }

    /**
     * Form data object as message_form_entity::prepare_message_from_form() expects it.
     *
     * @param array $values
     * @param int $messageid 0 = insert.
     * @return stdClass
     */
    protected function build_form_entity_data(array $values, int $messageid): stdClass {
        return (object)[
            'id' => $messageid,
            'messagetypes' => (string)$values['messagetypes'],
            'messagename' => (string)$values['messagename'],
            'heading' => (string)$values['heading'],
            'body' => ['text' => (string)$values['body'], 'format' => FORMAT_HTML],
            'priority' => (int)$values['priority'],
            'recipientrole' => (array)$values['recipientrole'],
            'userid' => (int)$values['userid'],
            'carboncopyrole' => (array)$values['carboncopyrole'],
            'ccuserid' => (int)$values['ccuserid'],
            'senddirection' => (string)$values['senddirection'],
            'eventlist' => (array)$values['eventlist'],
            'sendingcondition' => (string)$values['sendingcondition'],
            'sendstart' => (string)$values['sendstart'],
            'sendstartrequest' => (string)$values['sendstartrequest'],
            'senddays' => (string)$values['senddays'],
            'timeunit' => (string)$values['timeunit'],
        ];
    }

    /**
     * Persist the template through the editor entity and write the tags.
     *
     * @param array $values
     * @param int $messageid 0 = insert.
     * @return int Id of the stored template.
     */
    protected function persist(array $values, int $messageid): int {
        $entity = new message_form_entity();
        $storedid = (int)$entity->prepare_message_from_form($this->build_form_entity_data($values, $messageid));
        if ($storedid > 0) {
            core_tag_tag::set_item_tags(
                'local_taskflow',
                'local_taskflow_messages',
                $storedid,
                context_system::instance(),
                array_values((array)$values['tags'])
            );
        }
        return $storedid;
    }

    /**
     * Normalized template row of the read-only search skill (never rebuilt here).
     *
     * @param int $messageid
     * @param int $contextid
     * @param int $userid
     * @param string $lang
     * @return array Empty array when the row is not available.
     */
    protected function template_row(int $messageid, int $contextid, int $userid, string $lang): array {
        if ($messageid <= 0) {
            return [];
        }
        try {
            $result = (new search_message_templates_skill())->execute([
                'query' => (string)$messageid,
                'limit' => search_message_templates_skill::MAX_LIMIT,
                'outputlang' => $lang,
            ], $contextid, $userid);
        } catch (\Throwable $e) {
            return [];
        }
        foreach ((array)($result['templates'] ?? []) as $template) {
            if (is_array($template) && (int)($template['id'] ?? 0) === $messageid) {
                return $template;
            }
        }
        return [];
    }

    /**
     * Preview block for one template row (existing message template list renderer).
     *
     * @param array $row
     * @return array
     */
    protected function template_preview(array $row): array {
        return [
            'type' => taskflow_preview_renderer_factory::TYPE_MESSAGE_TEMPLATE_LIST,
            'data' => ['templates' => $row === [] ? [] : [$row], 'total' => $row === [] ? 0 : 1],
            'payload' => ['messageids' => $row === [] ? [] : [(int)$row['id']]],
        ];
    }

    /**
     * Result links of both skills.
     *
     * @param int $messageid
     * @return array
     */
    protected function template_links(int $messageid): array {
        return $this->links(
            taskflow_result_link_builder::edit_message_url($messageid),
            ['messages', 'messages_templates', 'messages_placeholders']
        );
    }

    /**
     * Tier-3 rows describing the resulting template.
     *
     * @param array $values Form values.
     * @param string $lang
     * @return array<int,array{label:string,value:string}>
     */
    protected function template_rows(array $values, string $lang): array {
        $rows = [
            ['label' => $this->localized_string('name', null, $lang), 'value' => (string)$values['messagename']],
            ['label' => $this->localized_string('type', null, $lang), 'value' => (string)$values['messagetypes']],
            [
                'label' => $this->localized_string('recipientrole', null, $lang),
                'value' => implode(', ', (array)$values['recipientrole']),
            ],
            [
                'label' => $this->localized_string('carboncopyrole', null, $lang),
                'value' => implode(', ', (array)$values['carboncopyrole']),
            ],
            [
                'label' => $this->localized_string('agent_preview_message_subject', null, $lang),
                'value' => (string)$values['heading'],
            ],
            [
                'label' => $this->localized_string('senddirection', null, $lang),
                'value' => trim(
                    (string)$values['senddays'] . ' ' . (string)$values['timeunit'] . ' '
                    . (string)$values['senddirection'] . ' '
                    . ((string)$values['sendstart'] !== ''
                        ? (string)$values['sendstart'] : (string)$values['sendstartrequest'])
                ),
            ],
            [
                'label' => $this->localized_string('messagepriority', null, $lang),
                'value' => (string)$values['priority'],
            ],
        ];
        if (!empty($values['tags'])) {
            $rows[] = [
                'label' => $this->localized_string('messagetags', null, $lang),
                'value' => implode(', ', (array)$values['tags']),
            ];
        }
        if (!empty($values['eventlist'])) {
            $labels = [];
            foreach ((array)$values['eventlist'] as $statusid) {
                $labels[] = $this->status_label((int)$statusid, $lang);
            }
            $rows[] = [
                'label' => $this->localized_string('agent_preview_catalog_statuses', null, $lang),
                'value' => implode(', ', $labels),
            ];
        }

        return array_values(array_filter(
            $rows,
            static fn(array $row): bool => trim((string)$row['value']) !== ''
        ));
    }

    /**
     * Preflight issues built from the editor validation errors.
     *
     * @param array<string,string> $errors
     * @param string $lang
     * @return array<int,array<string,string>>
     */
    protected function validation_issues(array $errors, string $lang): array {
        $issues = [];
        foreach ($errors as $field => $message) {
            $issues[] = [
                'code' => self::ISSUE_VALIDATION_FAILED,
                'severity' => 'needs_clarification',
                'field' => (string)$field,
                'message' => $this->localized_string(
                    'agent_message_template_validation_failed',
                    (object)['field' => (string)$field, 'error' => (string)$message],
                    $lang
                ),
            ];
        }
        return $issues;
    }

    /**
     * Whether the resulting template is a request message (affects the wording of the anchor).
     *
     * @param array $values
     * @return bool
     */
    protected function is_request_type(array $values): bool {
        return (string)$values['messagetypes'] === request::TYPE;
    }

    /**
     * Message type key => title, from the messages facade.
     *
     * @return array<string,string>
     */
    protected function message_types(): array {
        try {
            return (array)messages_facade::get_message_types();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Sending condition key => label, from the sending condition facade.
     *
     * @return array<string,string>
     */
    protected function sending_conditions(): array {
        try {
            return (array)sending_condition_facade::get_all();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
