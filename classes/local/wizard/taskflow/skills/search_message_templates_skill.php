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
use local_taskflow\local\wizard\engine\skill_risk_class;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\taskflow_input_normalizer;
use local_taskflow\local\wizard\taskflow\taskflow_result_link_builder;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;

/**
 * Skill local_taskflow.search_message_templates: list message templates (plan §2 #9).
 *
 * Read-only, R0. Rows come from {local_taskflow_messages}; every hit is normalized through
 * message_form_entity::prepare_record_for_form() so recipients, CC, timing, priority and tags
 * (the "message package") are read exactly the way the template editor reads them. Rule usage
 * is derived by scanning the rule documents for actions[].messages[].messageid.
 *
 * Access is granted by local/taskflow:editmessages OR local/taskflow:viewrules, so the gate is
 * evaluated in run_preflight() instead of being declared as a hard native capability.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_message_templates_skill extends taskflow_skill_base {
    /** Skill name. */
    public const TASK_NAME = 'local_taskflow.search_message_templates';

    /** Capability of the message template editor. */
    public const CAPABILITY_MESSAGES = 'local/taskflow:editmessages';

    /** Capability of the rule dashboard (message templates are part of a rule configuration). */
    public const CAPABILITY_RULES = 'local/taskflow:viewrules';

    /** Issue code: unknown message type filter. */
    public const ISSUE_INVALID_MESSAGETYPE = 'TASKFLOW_INVALID_MESSAGETYPE';

    /** Form-level message types (message_form_entity::map_class_to_form_type()). */
    public const MESSAGE_TYPES = ['standard', 'request', 'chat'];

    /** Persisted classes mapped to the form type 'standard'. */
    private const CLASSES_STANDARD = ['standard', 'onevent'];

    /** Persisted classes mapped to the form type 'request'. */
    private const CLASSES_REQUEST = ['request', 'onrequestcreated', 'onrequestclosed'];

    /** Default number of templates returned. */
    public const DEFAULT_LIMIT = 25;

    /** Hard cap of templates returned. */
    public const MAX_LIMIT = 100;

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
            'description' => 'Search and list taskflow MESSAGE TEMPLATES (the reusable mail/notification texts '
                . 'that rules send to assignees, supervisors or specific users). Filters: name text and message '
                . 'type (standard, request, chat). Returns id, name, type, recipients, CC, sending time, '
                . 'priority, message package (tags) and the rules that use the template.',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'Which message templates exist?',
                'Show me all reminder templates',
                'List the request message templates',
                'Which rules use the template "Reminder 7 days"?',
                'Search message templates containing "overdue"',
            ],
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Optional text matched against the template name (case-insensitive '
                        . 'substring). A pure number is also matched against the template id.',
                    'required' => false,
                ],
                'type' => [
                    'type' => 'string',
                    'enum' => self::MESSAGE_TYPES,
                    'description' => 'Optional message type: standard (scheduled or status driven), request '
                        . '(self-service requests) or chat (internal communication).',
                    'required' => false,
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of templates to return (default 25, max 100).',
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
            'intent' => 'List or find taskflow message templates and show where they are used.',
        ];
    }

    /**
     * Example input.
     *
     * @return array
     */
    public function get_example_input(): array {
        return ['query' => 'reminder'];
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

        if (isset($input['query']) && !is_string($input['query']) && !is_numeric($input['query'])) {
            $errors[] = $this->localized_string('agent_search_rules_query_must_be_string', null, $lang);
        }
        if (isset($input['type']) && trim((string)$input['type']) !== '') {
            $type = strtolower(trim((string)$input['type']));
            if (!in_array($type, self::MESSAGE_TYPES, true)) {
                $errors[] = $this->localized_string('agent_invalid_messagetype', $type, $lang);
            }
        }

        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => []];
    }

    /**
     * Preflight: capability gate, structure check, input normalization.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array{status:string,prepared_input:array,issues:array}
     */
    protected function run_preflight(array $input, int $contextid, int $userid): array {
        $lang = $this->get_output_language($input);
        if (!$this->may_read($userid)) {
            return $this->invalid([$this->scope_denied_issue($lang)]);
        }

        $structure = $this->check_structure($input);
        if (!($structure['valid'] ?? false)) {
            $issues = [];
            foreach ((array)($structure['errors'] ?? []) as $error) {
                $issues[] = [
                    'code' => isset($input['type']) ? self::ISSUE_INVALID_MESSAGETYPE : 'VALIDATION_ERROR',
                    'severity' => 'needs_clarification',
                    'message' => (string)$error,
                ];
            }
            return $this->invalid($issues);
        }

        return $this->pass($this->normalize_input($input));
    }

    /**
     * Execute: query template rows, normalize them and add the rule usage.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        global $DB;

        $lang = $this->get_output_language($input);
        if (!$this->may_read($userid)) {
            return $this->error_result(
                self::ISSUE_SCOPE_DENIED,
                $this->localized_string('agent_scope_denied', null, $lang),
                ['links' => $this->links(null, ['messages', 'messages_templates'])]
            );
        }

        $input = $this->normalize_input($input);
        $query = (string)($input['query'] ?? '');
        $type = (string)($input['type'] ?? '');
        $limit = (int)($input['limit'] ?? self::DEFAULT_LIMIT);

        [$where, $params] = $this->build_where($query);
        $rows = $DB->get_records_select('local_taskflow_messages', $where, $params, 'name ASC, id ASC', 'id, class');

        $matched = [];
        foreach ($rows as $row) {
            $formtype = $this->form_type((string)$row->class);
            if ($type !== '' && $formtype !== $type) {
                continue;
            }
            $matched[] = (int)$row->id;
        }

        $total = count($matched);
        $shownids = array_slice($matched, 0, $limit);
        $usage = $this->rule_usage($shownids);

        $templates = [];
        foreach ($shownids as $id) {
            $template = $this->build_template($id, $lang);
            if ($template === null) {
                continue;
            }
            $template['used_in_rules'] = $usage[$id] ?? [];
            $templates[] = $template;
        }

        $links = $this->links(
            taskflow_result_link_builder::edit_message_url(),
            ['messages', 'messages_templates', 'rules_messages_step']
        );

        if (empty($templates)) {
            $usermessage = $this->localized_string('agent_search_message_templates_none', null, $lang);
        } else {
            $usermessage = $this->localized_string('agent_search_message_templates_summary', (object)[
                'count' => count($templates),
                'total' => $total,
            ], $lang);
        }

        $messageids = array_map(static fn(array $row): int => (int)$row['id'], $templates);
        $debug = $this->build_task_debug_message(self::TASK_NAME, $input, [
            'Results: ' . count($templates) . ' of ' . $total,
            'Template ids: ' . implode(', ', $messageids),
        ]);

        return $this->base_result(self::STATUS_EXECUTED, [
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'observation_full' => $this->build_observation_full($usermessage, $templates, $total),
            'resultid' => (int)($messageids[0] ?? 0),
            'templates' => $templates,
            'total' => $total,
            'links' => $links,
            'debugmessage' => $debug,
            'outputlang' => $lang,
            'preview' => [
                'type' => taskflow_preview_renderer_factory::TYPE_MESSAGE_TEMPLATE_LIST,
                'data' => [
                    'templates' => $templates,
                    'total' => $total,
                    'query' => $query,
                    'type' => $type,
                    'limit' => $limit,
                ],
                'payload' => ['messageids' => $messageids],
            ],
        ]);
    }

    /**
     * Whether the user may read message templates (editor OR rule dashboard capability).
     *
     * @param int $userid
     * @return bool
     */
    private function may_read(int $userid): bool {
        $context = context_system::instance();
        return has_capability(self::CAPABILITY_MESSAGES, $context, $userid)
            || has_capability(self::CAPABILITY_RULES, $context, $userid);
    }

    /**
     * Coerce the raw input into typed values (limit clamped, type lower-cased).
     *
     * @param array $input
     * @return array
     */
    private function normalize_input(array $input): array {
        $normalized = $input;

        $query = trim((string)($input['query'] ?? ''));
        if ($query === '') {
            unset($normalized['query']);
        } else {
            $normalized['query'] = $query;
        }

        $type = strtolower(trim((string)($input['type'] ?? '')));
        if ($type === '') {
            unset($normalized['type']);
        } else {
            $normalized['type'] = $type;
        }

        $limit = taskflow_input_normalizer::to_int($input['limit'] ?? null);
        $normalized['limit'] = ($limit === null || $limit <= 0) ? self::DEFAULT_LIMIT : min($limit, self::MAX_LIMIT);

        return $normalized;
    }

    /**
     * WHERE clause + params for the template row query.
     *
     * @param string $query
     * @return array{0:string,1:array}
     */
    private function build_where(string $query): array {
        global $DB;

        if ($query === '') {
            return ['1 = 1', []];
        }

        $like = $DB->sql_like('name', ':query', false, false);
        $params = ['query' => '%' . $DB->sql_like_escape($query) . '%'];
        if (preg_match('/^\d+$/', $query)) {
            $params['queryid'] = (int)$query;
            return ['(' . $like . ' OR id = :queryid)', $params];
        }
        return [$like, $params];
    }

    /**
     * Form-level type of a persisted message class (mirrors message_form_entity::map_class_to_form_type()).
     *
     * @param string $class
     * @return string
     */
    private function form_type(string $class): string {
        $class = trim($class);
        if (in_array($class, self::CLASSES_STANDARD, true)) {
            return 'standard';
        }
        if (in_array($class, self::CLASSES_REQUEST, true)) {
            return 'request';
        }
        return $class;
    }

    /**
     * Normalized template row, or null when the record vanished.
     *
     * @param int $messageid
     * @param string $lang
     * @return array|null
     */
    private function build_template(int $messageid, string $lang): ?array {
        global $DB;

        $record = $DB->get_record('local_taskflow_messages', ['id' => $messageid]);
        if (!$record) {
            return null;
        }
        $data = (new message_form_entity())->prepare_record_for_form($messageid);
        if ($data === null) {
            return null;
        }

        $timing = [
            'sendstart' => (string)($data->sendstart ?? ''),
            'sendstartrequest' => (string)($data->sendstartrequest ?? ''),
            'senddirection' => (string)($data->senddirection ?? ''),
            'senddays' => (string)($data->senddays ?? ''),
            'timeunit' => (string)($data->timeunit ?? ''),
            'eventlist' => array_values(array_map('intval', (array)($data->eventlist ?? []))),
            'sendingcondition' => (string)($data->sendingcondition ?? ''),
        ];

        return [
            'id' => $messageid,
            'name' => (string)($data->messagename ?? ''),
            'type' => (string)($data->messagetypes ?? ''),
            'class' => (string)$record->class,
            'subject' => (string)($data->heading ?? ''),
            'recipients' => array_values(array_map('strval', (array)($data->recipientrole ?? []))),
            'cc' => array_values(array_map('strval', (array)($data->carboncopyrole ?? []))),
            'timing' => $timing,
            'timing_label' => $this->timing_label($timing, $lang),
            'package' => array_values(array_map('strval', (array)($data->tags ?? []))),
            'priority' => (int)($data->priority ?? 0),
            'used_in_rules' => [],
            'edit_url' => taskflow_result_link_builder::edit_message_url($messageid),
        ];
    }

    /**
     * Human readable sending time built from the stored sending settings.
     *
     * @param array $timing
     * @param string $lang
     * @return string
     */
    private function timing_label(array $timing, string $lang): string {
        $sendstart = (string)$timing['sendstart'];
        if ($sendstart === 'status_change') {
            $labels = [];
            foreach ((array)$timing['eventlist'] as $statusid) {
                $labels[] = $this->status_label((int)$statusid, $lang);
            }
            return $this->localized_string(
                'agent_message_timing_events',
                implode(', ', $labels),
                $lang
            );
        }

        $anchorkeys = ['start' => 'startdate', 'end' => 'enddate'];
        $requestkeys = ['onrequestcreated' => 'onrequestcreated', 'onrequestclosed' => 'onrequestclosed'];
        $sendstartrequest = (string)$timing['sendstartrequest'];
        if (isset($anchorkeys[$sendstart])) {
            $anchor = $this->localized_string($anchorkeys[$sendstart], null, $lang);
        } else if (isset($requestkeys[$sendstartrequest])) {
            $anchor = $this->localized_string($requestkeys[$sendstartrequest], null, $lang);
        } else {
            $anchor = '';
        }

        $days = trim((string)$timing['senddays']);
        if ($days === '' || $anchor === '') {
            return $anchor;
        }

        $unitkeys = ['minutes' => 'minutes', 'hours' => 'hours', 'days' => 'days'];
        $unitkey = $unitkeys[(string)$timing['timeunit']] ?? '';
        $unit = $unitkey === '' ? (string)$timing['timeunit'] : get_string($unitkey);
        $direction = (string)$timing['senddirection'] === 'before'
            ? $this->localized_string('beforecourseend', null, $lang)
            : $this->localized_string('aftercourseend', null, $lang);

        return $this->localized_string('agent_message_timing_relative', (object)[
            'days' => $days,
            'unit' => $unit,
            'direction' => $direction,
            'anchor' => $anchor,
        ], $lang);
    }

    /**
     * Rules referencing the given templates: messageid => [{id, name, url}].
     *
     * @param int[] $messageids
     * @return array<int,array<int,array{id:int,name:string,url:string}>>
     */
    private function rule_usage(array $messageids): array {
        global $DB;

        $usage = [];
        if (empty($messageids)) {
            return $usage;
        }

        $rules = $DB->get_records('local_taskflow_rules', null, 'id ASC', 'id, rulename, rulejson');
        foreach ($rules as $rule) {
            $decoded = json_decode((string)$rule->rulejson, true);
            if (!is_array($decoded)) {
                continue;
            }
            $document = (array)($decoded['rulejson']['rule'] ?? []);
            foreach ((array)($document['actions'] ?? []) as $action) {
                if (!is_array($action)) {
                    continue;
                }
                foreach ((array)($action['messages'] ?? []) as $message) {
                    $id = is_array($message) ? (int)($message['messageid'] ?? 0) : (int)$message;
                    if (!in_array($id, $messageids, true)) {
                        continue;
                    }
                    if (isset($usage[$id][(int)$rule->id])) {
                        continue;
                    }
                    $usage[$id][(int)$rule->id] = [
                        'id' => (int)$rule->id,
                        'name' => (string)$rule->rulename,
                        'url' => taskflow_result_link_builder::edit_rule_url((int)$rule->id),
                    ];
                }
            }
        }

        foreach ($usage as $id => $rows) {
            $usage[$id] = array_values($rows);
        }
        return $usage;
    }

    /**
     * Observation payload for follow-up reasoning steps (message + JSON list).
     *
     * @param string $usermessage
     * @param array $templates
     * @param int $total
     * @return string
     */
    private function build_observation_full(string $usermessage, array $templates, int $total): string {
        $json = json_encode(
            ['total' => $total, 'templates' => array_values($templates)],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if (!is_string($json) || $json === '') {
            return $usermessage;
        }
        return $usermessage . "\n\nMessage templates payload (JSON):\n" . $json;
    }
}
