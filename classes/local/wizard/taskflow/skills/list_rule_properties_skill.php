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
use local_taskflow\local\messages\messages_facade;
use local_taskflow\local\messages\placeholders\placeholders_manager;
use local_taskflow\local\operators\string_compare_operators;
use local_taskflow\local\requests\request_receivers\receiver_facade;
use local_taskflow\local\requests\request_types\requests_manager;
use local_taskflow\local\wizard\engine\skill_risk_class;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;

/**
 * Skill local_taskflow.list_rule_properties — READ-ONLY (R0).
 *
 * Static catalog of everything a taskflow rule can consist of: the rule-step fields, the
 * filter types with their operators (and whether the runtime evaluates them), the target
 * types, request types and receivers, message types and sending-time options, the
 * assignment statuses (incl. adapter exclusions) and the message placeholders. Every list
 * is derived from the plugin classes/forms that define it, never hardcoded ids.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class list_rule_properties_skill extends taskflow_skill_base {
    /** Skill name constant. */
    public const TASK_NAME = 'local_taskflow.list_rule_properties';

    /** Native capability required to inspect rules. */
    public const NATIVE_CAPABILITY = 'local/taskflow:viewrules';

    /** Issue code: acting user lacks the native capability. */
    public const ISSUE_NO_NATIVE_CAPABILITY = 'NO_NATIVE_CAPABILITY';

    /** Filter type key => runtime evaluator class (classes/local/filters/types). */
    private const FILTER_RUNTIME_CLASSES = [
        'user_profile_field' => '\\local_taskflow\\local\\filters\\types\\user_profile_field',
        'user_field' => '\\local_taskflow\\local\\filters\\types\\user_field',
    ];

    /**
     * Rule-step fields as defined in classes/form/rules/rule.php (key, label key, type, options).
     *
     * @var array<int,array<string,mixed>>
     */
    private const RULE_FIELDS = [
        ['key' => 'name', 'labelkey' => 'name', 'component' => 'core', 'type' => 'string', 'required' => true],
        ['key' => 'description', 'labelkey' => 'description', 'component' => 'core', 'type' => 'text', 'required' => false],
        ['key' => 'enabled', 'labelkey' => 'enabled', 'type' => 'boolean', 'required' => false],
        ['key' => 'recursive', 'labelkey' => 'recursive', 'type' => 'boolean', 'required' => false],
        ['key' => 'ruletype', 'labelkey' => 'type', 'type' => 'select', 'required' => true,
            'options' => ['unit_target' => 'unittarget', 'user_target' => 'usertarget']],
        ['key' => 'unitid', 'labelkey' => 'cohort', 'component' => 'cohort', 'type' => 'int', 'required' => false],
        ['key' => 'userid', 'labelkey' => 'user', 'component' => 'core', 'type' => 'int', 'required' => false],
        ['key' => 'inheritance', 'labelkey' => 'inheritance', 'type' => 'boolean', 'required' => false],
        ['key' => 'duedatetype', 'labelkey' => 'duedatetype', 'type' => 'select', 'required' => true,
            'options' => ['duration' => 'duration', 'fixeddate' => 'fixeddate']],
        ['key' => 'fixeddate', 'labelkey' => 'fixeddate', 'type' => 'timestamp', 'required' => false],
        ['key' => 'duration', 'labelkey' => 'duration', 'type' => 'seconds', 'required' => false],
        ['key' => 'extensionperiod', 'labelkey' => 'extensionperiod', 'type' => 'seconds', 'required' => false],
        ['key' => 'cyclicvalidation', 'labelkey' => 'cyclicvalidation', 'type' => 'boolean', 'required' => false],
        ['key' => 'cyclicduration', 'labelkey' => 'cyclicduration', 'type' => 'seconds', 'required' => false],
        ['key' => 'activationdelay', 'labelkey' => 'activationdelay', 'type' => 'seconds', 'required' => false],
    ];

    /** Target type key => label key; bookingoption only when mod_booking is installed. */
    private const TARGET_TYPES = [
        'moodlecourse' => 'targettype:moodlecourse',
        'competency' => 'targettype:competency',
        'bookingoption' => 'targettype:bookingoption',
    ];

    /**
     * Message sending-time options (editmessagesmanager::set_messagesettings / return_sendingoptions).
     *
     * @var array<string,array<string,array<string,string>>>
     */
    private const MESSAGE_TIMING = [
        'sendstart' => ['start' => 'startdate', 'end' => 'enddate', 'status_change' => 'onstatuschange'],
        'sendstartrequest' => ['onrequestcreated' => 'onrequestcreated', 'onrequestclosed' => 'onrequestclosed'],
        'senddirection' => ['before' => 'beforecourseend', 'after' => 'aftercourseend'],
        'timeunit' => ['minutes' => 'core:minutes', 'hours' => 'core:hours', 'days' => 'core:days'],
    ];

    /**
     * Constructor — read-only, R0.
     */
    public function __construct() {
        parent::__construct(true, skill_risk_class::R0, [self::NATIVE_CAPABILITY]);
    }

    /**
     * Return skill name.
     *
     * @return string
     */
    public function get_name(): string {
        return self::TASK_NAME;
    }

    /**
     * Return the raw skill schema.
     *
     * @return array
     */
    protected function define_schema(): array {
        return [
            'version' => 1,
            'description' => 'List the building blocks of a taskflow rule: rule fields (due date model,'
                . ' cyclic validation, activation delay, ...), filter types and their operators with exact'
                . ' semantics, target types, request types and receivers, message types and sending-time'
                . ' options, assignment statuses and message placeholders. Read-only reference — use it'
                . ' before creating or updating a rule, or when the user asks which options a rule has.',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'Which properties can a taskflow rule have?',
                'What filter operators are available for taskflow rules?',
                'Which assignment statuses exist in taskflow?',
                'Which placeholders can I use in taskflow messages?',
            ],
            'properties' => [],
        ];
    }

    /**
     * Prompt metadata overrides.
     *
     * @return array<string,mixed>
     */
    protected function prompt_meta(): array {
        return [
            'intent' => 'Reference catalog of taskflow rule properties, operators, targets, statuses and placeholders.',
            'input_fields_for_prompt' => [],
            'anchor_fields' => [],
        ];
    }

    /**
     * Preflight: enforce the native capability.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    protected function run_preflight(array $input, int $contextid, int $userid): array {
        if (!has_capability(self::NATIVE_CAPABILITY, context_system::instance(), $userid)) {
            return $this->invalid([[
                'code' => self::ISSUE_NO_NATIVE_CAPABILITY,
                'severity' => 'needs_clarification',
                'message' => get_string('nopermissions', 'error', self::NATIVE_CAPABILITY),
            ]]);
        }
        return $this->pass(['outputlang' => $this->get_output_language($input)]);
    }

    /**
     * Execute: assemble the property catalog.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        $lang = $this->get_output_language($input);

        if (!has_capability(self::NATIVE_CAPABILITY, context_system::instance(), $userid)) {
            return $this->error_result(
                self::ISSUE_NO_NATIVE_CAPABILITY,
                get_string('nopermissions', 'error', self::NATIVE_CAPABILITY),
                ['debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input)]
            );
        }

        $catalog = [
            'rule_fields' => $this->rule_fields($lang),
            'filter_types' => $this->filter_types($lang),
            'operators' => $this->operators($lang),
            'target_types' => $this->target_types($lang),
            'request_types' => $this->request_types($lang),
            'request_receivers' => $this->request_receivers(),
            'message_types' => $this->message_types(),
            'message_timing' => $this->message_timing($lang),
            'statuses' => $this->statuses($lang),
            'placeholders' => $this->placeholders(),
        ];

        $links = $this->links(null, [
            'rules', 'rules_rule_step', 'rules_filters', 'rules_targets', 'rules_messages_step', 'rules_requests_step',
            'messages_placeholders', 'assignments_status_lifecycle',
        ]);

        $counts = (object)[
            'fields' => count($catalog['rule_fields']),
            'operators' => count($catalog['operators']),
            'statuses' => count($catalog['statuses']),
            'placeholders' => count($catalog['placeholders']),
        ];
        $usermessage = $this->localized_string('agent_list_rule_properties_summary', $counts, $lang);

        return $this->base_result(self::STATUS_EXECUTED, $catalog + [
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'observation_full' => $this->build_observation($catalog),
            'links' => $links,
            'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, [
                'operators: ' . $counts->operators,
                'statuses: ' . $counts->statuses,
            ]),
            'preview' => [
                'type' => taskflow_preview_renderer_factory::TYPE_CATALOG,
                'data' => $this->build_preview_data($catalog, $links, $lang),
                'payload' => [],
            ],
        ]);
    }

    /**
     * Rule-step fields with localized labels.
     *
     * @param string $lang
     * @return array<int,array<string,mixed>>
     */
    private function rule_fields(string $lang): array {
        $fields = [];
        foreach (self::RULE_FIELDS as $field) {
            $options = [];
            foreach ((array)($field['options'] ?? []) as $key => $labelkey) {
                $options[] = ['key' => (string)$key, 'label' => $this->localized_string($labelkey, null, $lang)];
            }
            $component = (string)($field['component'] ?? '');
            $label = $component === ''
                ? $this->localized_string($field['labelkey'], null, $lang)
                : get_string($field['labelkey'], $component, null, $lang === '' ? null : $lang);
            $fields[] = [
                'key' => $field['key'],
                'label' => $label,
                'type' => $field['type'],
                'required' => (bool)$field['required'],
                'options' => $options,
            ];
        }
        return $fields;
    }

    /**
     * Filter types offered by the rule form with the fields they filter on and their runtime support.
     *
     * runtime_supported derives from the existence of the evaluator class in
     * classes/local/filters/types (the same lookup filter_factory::instance() performs).
     *
     * @param string $lang
     * @return array<int,array<string,mixed>>
     */
    private function filter_types(string $lang): array {
        $userfieldclass = self::FILTER_RUNTIME_CLASSES['user_field'];
        $userfields = [];
        if (class_exists($userfieldclass) && defined($userfieldclass . '::ALLOWED_FIELDS')) {
            foreach ((array)constant($userfieldclass . '::ALLOWED_FIELDS') as $shortname) {
                $userfields[] = [
                    'key' => (string)$shortname,
                    'label' => $this->localized_string('filteruserfield' . $shortname, null, $lang),
                ];
            }
        }

        $profilefields = [];
        foreach ($this->custom_profile_fields() as $field) {
            $profilefields[] = ['key' => (string)$field->shortname, 'label' => format_string((string)$field->name)];
        }

        return [
            [
                'key' => 'user_profile_field',
                'label' => $this->localized_string('filteruserprofilefield', null, $lang),
                'value_fields' => ['user_profile_field_userprofilefield', 'user_profile_field_operator',
                    'user_profile_field_value'],
                'fields' => $profilefields,
                'runtime_supported' => class_exists(self::FILTER_RUNTIME_CLASSES['user_profile_field']),
            ],
            [
                'key' => 'user_field',
                'label' => $this->localized_string('filteruserfield', null, $lang),
                'value_fields' => ['user_field_userfield', 'user_field_operator', 'user_field_value'],
                'fields' => $userfields,
                'runtime_supported' => class_exists($userfieldclass),
            ],
        ];
    }

    /**
     * Custom user profile fields (shortname, name), empty when none exist.
     *
     * @return array<int,object>
     */
    private function custom_profile_fields(): array {
        global $CFG;
        require_once($CFG->dirroot . '/user/profile/lib.php');
        return array_values((array)profile_get_custom_fields());
    }

    /**
     * Comparison operators of the filter step with label, semantics and runtime support.
     *
     * runtime_supported = the key is part of string_compare_operators::get_operator_keys()
     * (the list validate() evaluates); the form offers get_operator_keys_and_values().
     *
     * @param string $lang
     * @return array<int,array<string,mixed>>
     */
    private function operators(string $lang): array {
        $operators = new string_compare_operators();
        $runtimekeys = $operators->get_operator_keys();
        $rows = [];
        foreach ($operators->get_operator_keys_and_values() as $key => $label) {
            $key = (string)$key;
            $rows[] = [
                'key' => $key,
                'label' => (string)$label,
                'semantics' => $this->localized_string('agent_operator_semantics_' . $key, null, $lang),
                'runtime_supported' => in_array($key, $runtimekeys, true),
            ];
        }
        return $rows;
    }

    /**
     * Target types of the target step; bookingoption is offered only when mod_booking is installed.
     *
     * @param string $lang
     * @return array<int,array<string,mixed>>
     */
    private function target_types(string $lang): array {
        $rows = [];
        foreach (self::TARGET_TYPES as $key => $labelkey) {
            $rows[] = [
                'key' => $key,
                'label' => $this->localized_string($labelkey, null, $lang),
                'available' => $key !== 'bookingoption' || class_exists('mod_booking\\booking'),
            ];
        }
        return $rows;
    }

    /**
     * Request types (requests_manager) with id, key, title and whether the site setting enables them.
     *
     * @param string $lang
     * @return array<int,array<string,mixed>>
     */
    private function request_types(string $lang): array {
        $rows = [];
        foreach ((new requests_manager())->get_request_types_with_ids() as $id => $type) {
            $type = (string)$type;
            $rows[] = [
                'id' => (int)$id,
                'key' => $type,
                'label' => $this->localized_string($type . '_title', null, $lang),
                'active' => (bool)get_config('local_taskflow', $type),
            ];
        }
        return $rows;
    }

    /**
     * Request receivers (receiver_facade) with id, key and description.
     *
     * @return array<int,array<string,mixed>>
     */
    private function request_receivers(): array {
        $rows = [];
        foreach (receiver_facade::get_request_receivers() as $id => $receiver) {
            $rows[] = [
                'id' => (int)$id,
                'key' => defined(get_class($receiver) . '::SETTINGKEY') ? (string)$receiver::SETTINGKEY : '',
                'label' => (string)$receiver->get_description(),
            ];
        }
        return $rows;
    }

    /**
     * Message types (messages_facade::get_message_types(); chat only with internal communication).
     *
     * @return array<int,array<string,string>>
     */
    private function message_types(): array {
        $rows = [];
        foreach (messages_facade::get_message_types() as $type => $title) {
            $rows[] = ['key' => (string)$type, 'label' => (string)$title];
        }
        return $rows;
    }

    /**
     * Sending-time options of a message template (sendstart, senddirection, timeunit, senddays).
     *
     * @param string $lang
     * @return array<int,array<string,mixed>>
     */
    private function message_timing(string $lang): array {
        $rows = [];
        foreach (self::MESSAGE_TIMING as $field => $options) {
            $optionrows = [];
            foreach ($options as $key => $labelkey) {
                $optionrows[] = ['key' => (string)$key, 'label' => $this->timing_label($labelkey, $lang)];
            }
            $rows[] = ['key' => $field, 'options' => $optionrows];
        }
        $rows[] = ['key' => 'senddays', 'label' => $this->localized_string('senddays', null, $lang), 'options' => []];
        return $rows;
    }

    /**
     * Label of a timing option: 'core:<key>' resolves against Moodle core, else local_taskflow.
     *
     * @param string $labelkey
     * @param string $lang
     * @return string
     */
    private function timing_label(string $labelkey, string $lang): string {
        if (strpos($labelkey, 'core:') === 0) {
            return get_string(substr($labelkey, 5), 'moodle', null, $lang === '' ? null : $lang);
        }
        return $this->localized_string($labelkey, null, $lang);
    }

    /**
     * Assignment statuses from the status type classes, incl. user choice and adapter exclusion.
     *
     * @param string $lang
     * @return array<int,array<string,mixed>>
     */
    private function statuses(string $lang): array {
        $userchoices = assignment_status_facade::get_userchoices();
        $rows = [];
        foreach (assignment_status_facade::get_all() as $id => $status) {
            $id = (int)$id;
            $rows[] = [
                'id' => $id,
                'name' => (string)($status['label'] ?? ''),
                'label' => $this->status_label($id, $lang),
                'active' => (bool)($status['active'] ?? false),
                'userchoice' => array_key_exists($id, $userchoices),
                'excluded' => (bool)assignment_status_facade::check_excluded((string)$id),
            ];
        }
        usort($rows, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
        return $rows;
    }

    /**
     * Message placeholder names from placeholders_manager (the <name> tokens of the editor list).
     *
     * @return string[]
     */
    private function placeholders(): array {
        $html = (string)(new placeholders_manager())->get_list_of_placeholders();
        preg_match_all('/data-id="([^"]+)"/', $html, $matches);
        $names = array_values(array_unique(array_map('strval', $matches[1] ?? [])));
        sort($names);
        return $names;
    }

    /**
     * Observation text for the synchronizer.
     *
     * @param array $catalog
     * @return string
     */
    private function build_observation(array $catalog): string {
        $lines = ['Taskflow rule property catalog:'];

        $lines[] = 'Rule fields: ' . implode(', ', array_map(static function (array $field): string {
            $options = array_map(static fn(array $o): string => $o['key'], $field['options']);
            return $field['key'] . ' (' . $field['type'] . ($field['required'] ? ', required' : '')
                . (empty($options) ? '' : ': ' . implode('|', $options)) . ')';
        }, $catalog['rule_fields']));

        foreach ($catalog['filter_types'] as $type) {
            $lines[] = 'Filter type ' . $type['key'] . ' (' . $type['label'] . '): '
                . ($type['runtime_supported'] ? 'evaluated at runtime' : 'NOT evaluated at runtime')
                . (empty($type['fields']) ? '' : '; fields: '
                    . implode(', ', array_map(static fn(array $f): string => $f['key'], $type['fields'])));
        }

        $lines[] = 'Operators:';
        foreach ($catalog['operators'] as $operator) {
            $lines[] = '- ' . $operator['key'] . ' (' . $operator['label'] . '): ' . $operator['semantics']
                . ($operator['runtime_supported'] ? '' : ' [NOT evaluated at runtime]');
        }

        $lines[] = 'Target types: ' . implode(', ', array_map(
            static fn(array $t): string => $t['key'] . ($t['available'] ? '' : ' (not available)'),
            $catalog['target_types']
        ));
        $lines[] = 'Request types: ' . implode(', ', array_map(
            static fn(array $r): string => $r['key'] . ' (id ' . $r['id'] . ', ' . ($r['active'] ? 'enabled' : 'disabled') . ')',
            $catalog['request_types']
        ));
        $lines[] = 'Request receivers: ' . implode(', ', array_map(
            static fn(array $r): string => $r['key'] . ' (id ' . $r['id'] . ')',
            $catalog['request_receivers']
        ));
        $lines[] = 'Message types: '
            . implode(', ', array_map(static fn(array $m): string => $m['key'], $catalog['message_types']));
        foreach ($catalog['message_timing'] as $timing) {
            $lines[] = 'Message timing ' . $timing['key'] . ': ' . (empty($timing['options'])
                ? 'integer'
                : implode('|', array_map(static fn(array $o): string => $o['key'], $timing['options'])));
        }
        $lines[] = 'Statuses:';
        foreach ($catalog['statuses'] as $status) {
            $flags = [$status['active'] ? 'active' : 'inactive'];
            if ($status['userchoice']) {
                $flags[] = 'user choice';
            }
            if ($status['excluded']) {
                $flags[] = 'excluded by adapter';
            }
            $lines[] = '- ' . $status['id'] . ' ' . $status['name'] . ' (' . $status['label'] . '): ' . implode(', ', $flags);
        }
        $lines[] = 'Placeholders: '
            . implode(', ', array_map(static fn(string $p): string => '<' . $p . '>', $catalog['placeholders']));

        return implode("\n", $lines);
    }

    /**
     * Data for the taskflow_catalog preview: one collapsible section per catalog group.
     *
     * @param array $catalog
     * @param array $links
     * @param string $lang
     * @return array
     */
    private function build_preview_data(array $catalog, array $links, string $lang): array {
        $unsupported = $this->localized_string('agent_preview_catalog_runtime_unsupported', null, $lang);
        $sections = [];

        $sections[] = ['title' => $this->localized_string('agent_preview_catalog_rule_fields', null, $lang), 'open' => true,
            'rows' => array_map(static fn(array $f): array => [
                'label' => $f['key'], 'code' => true, 'value' => $f['label'],
                'hint' => $f['type'] . (empty($f['options']) ? '' : ': '
                    . implode(' | ', array_map(static fn(array $o): string => $o['key'], $f['options']))),
            ], $catalog['rule_fields'])];

        $sections[] = ['title' => $this->localized_string('agent_preview_catalog_filter_types', null, $lang), 'open' => false,
            'rows' => array_map(static fn(array $t): array => [
                'label' => $t['key'], 'code' => true, 'value' => $t['label'],
                'hint' => implode(', ', array_map(static fn(array $f): string => $f['key'], $t['fields'])),
                'badge' => $t['runtime_supported'] ? '' : $unsupported, 'badgeclass' => 'bg-warning text-dark',
            ], $catalog['filter_types'])];

        $sections[] = ['title' => $this->localized_string('agent_preview_catalog_operators', null, $lang), 'open' => true,
            'rows' => array_map(static fn(array $o): array => [
                'label' => $o['key'], 'code' => true, 'value' => $o['label'], 'hint' => $o['semantics'],
                'badge' => $o['runtime_supported'] ? '' : $unsupported, 'badgeclass' => 'bg-warning text-dark',
            ], $catalog['operators'])];

        $sections[] = ['title' => $this->localized_string('agent_preview_catalog_target_types', null, $lang), 'open' => false,
            'rows' => array_map(fn(array $t): array => [
                'label' => $t['key'], 'code' => true, 'value' => $t['label'], 'hint' => '',
                'badge' => $t['available'] ? '' : $this->localized_string('agent_preview_catalog_unavailable', null, $lang),
                'badgeclass' => 'bg-secondary',
            ], $catalog['target_types'])];

        $requestrows = array_map(static fn(array $r): array => [
            'label' => $r['key'], 'code' => true, 'value' => $r['label'], 'hint' => 'id ' . $r['id'],
            'badge' => $r['active'] ? '' : 'inactive', 'badgeclass' => 'bg-secondary',
        ], $catalog['request_types']);
        foreach ($catalog['request_receivers'] as $receiver) {
            $requestrows[] = ['label' => $receiver['key'], 'code' => true, 'value' => $receiver['label'],
                'hint' => 'id ' . $receiver['id']];
        }
        $sections[] = ['title' => $this->localized_string('agent_preview_catalog_requests', null, $lang), 'open' => false,
            'rows' => $requestrows];

        $timingrows = array_map(static fn(array $m): array => [
            'label' => $m['key'], 'code' => true, 'value' => $m['label'], 'hint' => '',
        ], $catalog['message_types']);
        foreach ($catalog['message_timing'] as $timing) {
            $timingrows[] = ['label' => $timing['key'], 'code' => true,
                'value' => empty($timing['options']) ? (string)($timing['label'] ?? '') : implode(' | ', array_map(
                    static fn(array $o): string => $o['key'] . ' (' . $o['label'] . ')',
                    $timing['options']
                )), 'hint' => ''];
        }
        $sections[] = ['title' => $this->localized_string('agent_preview_catalog_message_timing', null, $lang), 'open' => false,
            'rows' => $timingrows];

        $sections[] = ['title' => $this->localized_string('agent_preview_catalog_statuses', null, $lang), 'open' => false,
            'rows' => array_map(fn(array $s): array => [
                'label' => (string)$s['id'], 'code' => true, 'statusid' => $s['id'], 'value' => $s['label'],
                'hint' => implode(' · ', array_filter([
                    $this->localized_string($s['active'] ? 'activityactive' : 'activityinactive', null, $lang),
                    $s['userchoice'] ? $this->localized_string('agent_preview_catalog_status_userchoice', null, $lang) : '',
                    $s['excluded'] ? $this->localized_string('agent_preview_catalog_status_excluded', null, $lang) : '',
                ])),
            ], $catalog['statuses'])];

        $sections[] = ['title' => $this->localized_string('agent_preview_catalog_placeholders', null, $lang), 'open' => false,
            'rows' => array_map(static fn(string $p): array => [
                'label' => '<' . $p . '>', 'code' => true, 'value' => '', 'hint' => '',
            ], $catalog['placeholders'])];

        $previewlinks = [];
        foreach ((array)($links['docs'] ?? []) as $url) {
            $previewlinks[] = ['url' => $url, 'label' => $this->localized_string('agent_preview_open_docs', null, $lang)];
        }

        return [
            'title' => $this->localized_string('agent_preview_catalog_rule_properties', null, $lang),
            'badge' => '',
            'count' => 0,
            'sections' => $sections,
            'links' => $previewlinks,
            'empty' => '',
        ];
    }
}
