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
use local_taskflow\local\wizard\taskflow\taskflow_message_resolver;
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
 * Besides the name query (shared with preview_message / diagnose_message_delivery through
 * taskflow_message_resolver) the skill offers STRUCTURAL filters whose value sets are the ones
 * of the template editor: recipient (recipientrole/carboncopyrole options), senddirection,
 * sendstart (sending options), senddays and package (tag names of the tag area
 * local_taskflow_messages). They are applied in PHP to the normalized rows, so a criterion like
 * "goes to the supervisor" or "7 days before the deadline" never has to be turned into a name
 * search.
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

    /** Issue code: a structural filter carries a value outside the editor's value set. */
    public const ISSUE_INVALID_FILTER = 'TASKFLOW_INVALID_FILTER';

    /** Recipient options of the template editor (recipientrole + carboncopyrole selects). */
    public const RECIPIENT_ROLES = ['assignee', 'supervisor', 'specificuser', 'ccspecificuser'];

    /** Send direction options of the template editor. */
    public const SEND_DIRECTIONS = ['before', 'after'];

    /** Sending anchor options of the template editor (standard + request variants). */
    public const SEND_STARTS = ['start', 'end', 'status_change', 'onrequestcreated', 'onrequestclosed'];

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
                . 'that rules send to assignees, supervisors or specific users). Each criterion has its own '
                . 'structural filter: WHO receives it -> recipient; WHEN it is sent -> senddirection, sendstart '
                . 'and senddays; WHICH package/tag it belongs to -> package; message type -> type. Use query '
                . 'ONLY for a part of the template name or its id, never to express a recipient, a timing or a '
                . 'package. Returns id, name, type, recipients, CC, sending time, priority, message package '
                . '(tags) and the rules that use the template.',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'Which message templates exist?',
                'Which templates go to the supervisor?',
                'Which template is sent 7 days before the deadline?',
                'List the request message templates',
                'Which templates belong to the onboarding package?',
                'Which rules use the template "Reminder 7 days"?',
            ],
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Optional part of the template NAME (case-insensitive substring) or the '
                        . 'template id. Not for recipients, timings or packages (see the other filters).',
                    'required' => false,
                ],
                'recipient' => [
                    'type' => 'string',
                    'enum' => self::RECIPIENT_ROLES,
                    'description' => 'Optional recipient filter: only templates that address this role as '
                        . 'recipient OR as CC. assignee = the person with the assignment, supervisor = the '
                        . 'assignee\'s supervisor (and deputies), specificuser/ccspecificuser = a fixed user.',
                    'required' => false,
                ],
                'senddirection' => [
                    'type' => 'string',
                    'enum' => self::SEND_DIRECTIONS,
                    'description' => 'Optional timing filter: before or after the sending anchor (sendstart).',
                    'required' => false,
                ],
                'sendstart' => [
                    'type' => 'string',
                    'enum' => self::SEND_STARTS,
                    'description' => 'Optional timing anchor: start (assignment start), end (due date / '
                        . 'deadline), status_change (sent on a status change), onrequestcreated, '
                        . 'onrequestclosed (request templates).',
                    'required' => false,
                ],
                'senddays' => [
                    'type' => 'integer',
                    'description' => 'Optional timing offset: number of time units (see timing.timeunit in the '
                        . 'result) before/after the anchor, e.g. 7 for "7 days before the deadline".',
                    'required' => false,
                ],
                'package' => [
                    'type' => 'string',
                    'description' => 'Optional message package: the name of a tag attached to the template '
                        . '(case-insensitive, exact tag name).',
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
        return ['recipient' => 'supervisor', 'senddirection' => 'before', 'sendstart' => 'end', 'senddays' => 7];
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
        foreach ($this->filter_issues($input, $lang) as $issue) {
            $errors[] = (string)$issue['message'];
        }

        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => []];
    }

    /**
     * Issues for filter values outside the editor's value sets (type, recipient, timing, senddays).
     *
     * @param array $input
     * @param string $lang
     * @return array<int,array{code:string,severity:string,field:string,message:string}>
     */
    private function filter_issues(array $input, string $lang): array {
        $issues = [];

        $type = strtolower(trim((string)($input['type'] ?? '')));
        if ($type !== '' && !in_array($type, self::MESSAGE_TYPES, true)) {
            $issues[] = [
                'code' => self::ISSUE_INVALID_MESSAGETYPE,
                'severity' => 'needs_clarification',
                'field' => 'type',
                'message' => $this->localized_string('agent_invalid_messagetype', $type, $lang),
            ];
        }

        $enums = [
            'recipient' => self::RECIPIENT_ROLES,
            'senddirection' => self::SEND_DIRECTIONS,
            'sendstart' => self::SEND_STARTS,
        ];
        foreach ($enums as $field => $allowed) {
            $value = strtolower(trim((string)($input[$field] ?? '')));
            if ($value === '' || in_array($value, $allowed, true)) {
                continue;
            }
            $issues[] = [
                'code' => self::ISSUE_INVALID_FILTER,
                'severity' => 'needs_clarification',
                'field' => $field,
                'message' => $this->localized_string('agent_invalid_filter_value', (object)[
                    'field' => $field,
                    'value' => $value,
                    'allowed' => implode(', ', $allowed),
                ], $lang),
            ];
        }

        $rawdays = $input['senddays'] ?? null;
        if ($rawdays !== null && trim((string)$rawdays) !== '') {
            $days = taskflow_input_normalizer::to_int($rawdays);
            if ($days === null || $days < 0) {
                $issues[] = [
                    'code' => self::ISSUE_INVALID_FILTER,
                    'severity' => 'needs_clarification',
                    'field' => 'senddays',
                    'message' => $this->localized_string('agent_invalid_filter_value', (object)[
                        'field' => 'senddays',
                        'value' => trim((string)$rawdays),
                        'allowed' => '0, 1, 2, …',
                    ], $lang),
                ];
            }
        }

        return $issues;
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
        $input = $this->canonical_input($input);
        $lang = $this->get_output_language($input);
        if (!$this->may_read($userid)) {
            return $this->invalid([$this->scope_denied_issue($lang)]);
        }

        $issues = $this->filter_issues($input, $lang);
        if (isset($input['query']) && !is_string($input['query']) && !is_numeric($input['query'])) {
            $issues[] = [
                'code' => 'VALIDATION_ERROR',
                'severity' => 'needs_clarification',
                'field' => 'query',
                'message' => $this->localized_string('agent_search_rules_query_must_be_string', null, $lang),
            ];
        }
        if (!empty($issues)) {
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

        $input = $this->canonical_input($input);
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
        $filters = $this->structural_filters($input);

        [$where, $params] = taskflow_message_resolver::name_where($query);
        $rows = $DB->get_records_select('local_taskflow_messages', $where, $params, 'name ASC, id ASC', 'id, class');

        // Type is decided on the raw class; the structural filters need the normalized row.
        $matched = [];
        foreach ($rows as $row) {
            $formtype = $this->form_type((string)$row->class);
            if ($type !== '' && $formtype !== $type) {
                continue;
            }
            $template = $this->build_template((int)$row->id, $lang);
            if ($template === null || !$this->matches_filters($template, $filters)) {
                continue;
            }
            $matched[] = $template;
        }

        $total = count($matched);
        $templates = array_slice($matched, 0, $limit);
        $usage = $this->rule_usage(array_map(static fn(array $row): int => (int)$row['id'], $templates));
        foreach ($templates as &$template) {
            $template['used_in_rules'] = $usage[(int)$template['id']] ?? [];
        }
        unset($template);

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
                    'filters' => $filters,
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

        foreach (['recipient', 'senddirection', 'sendstart'] as $field) {
            $value = strtolower(trim((string)($input[$field] ?? '')));
            if ($value === '') {
                unset($normalized[$field]);
            } else {
                $normalized[$field] = $value;
            }
        }

        $rawdays = $input['senddays'] ?? null;
        $days = ($rawdays === null || trim((string)$rawdays) === '')
            ? null
            : taskflow_input_normalizer::to_int($rawdays);
        if ($days === null || $days < 0) {
            unset($normalized['senddays']);
        } else {
            $normalized['senddays'] = $days;
        }

        $package = trim((string)($input['package'] ?? ''));
        if ($package === '') {
            unset($normalized['package']);
        } else {
            $normalized['package'] = $package;
        }

        $limit = taskflow_input_normalizer::to_int($input['limit'] ?? null);
        $normalized['limit'] = ($limit === null || $limit <= 0) ? self::DEFAULT_LIMIT : min($limit, self::MAX_LIMIT);

        return $normalized;
    }

    /**
     * The structural filters present in the normalized input.
     *
     * @param array $input Normalized input.
     * @return array{recipient?:string,senddirection?:string,sendstart?:string,senddays?:int,package?:string}
     */
    private function structural_filters(array $input): array {
        $filters = [];
        foreach (['recipient', 'senddirection', 'sendstart', 'package'] as $field) {
            if (isset($input[$field]) && (string)$input[$field] !== '') {
                $filters[$field] = (string)$input[$field];
            }
        }
        if (isset($input['senddays'])) {
            $filters['senddays'] = (int)$input['senddays'];
        }
        return $filters;
    }

    /**
     * Whether a normalized template row satisfies every structural filter.
     *
     * recipient matches the recipient roles OR the CC roles; sendstart matches the standard
     * anchor OR the request anchor; senddays compares the numeric offset; package compares
     * the tag names case-insensitively.
     *
     * @param array $template Row built by build_template().
     * @param array $filters Result of structural_filters().
     * @return bool
     */
    private function matches_filters(array $template, array $filters): bool {
        if (empty($filters)) {
            return true;
        }
        $timing = (array)($template['timing'] ?? []);

        if (isset($filters['recipient'])) {
            $roles = array_merge((array)($template['recipients'] ?? []), (array)($template['cc'] ?? []));
            if (!in_array($filters['recipient'], $roles, true)) {
                return false;
            }
        }
        if (isset($filters['senddirection']) && (string)($timing['senddirection'] ?? '') !== $filters['senddirection']) {
            return false;
        }
        if (isset($filters['sendstart'])) {
            $anchors = [(string)($timing['sendstart'] ?? ''), (string)($timing['sendstartrequest'] ?? '')];
            if (!in_array($filters['sendstart'], $anchors, true)) {
                return false;
            }
        }
        if (isset($filters['senddays'])) {
            $days = trim((string)($timing['senddays'] ?? ''));
            if ($days === '' || !is_numeric($days) || (int)$days !== $filters['senddays']) {
                return false;
            }
        }
        if (isset($filters['package'])) {
            $wanted = \core_text::strtolower($filters['package']);
            $found = false;
            foreach ((array)($template['package'] ?? []) as $tag) {
                if (\core_text::strtolower((string)$tag) === $wanted) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return false;
            }
        }
        return true;
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
            foreach (taskflow_message_resolver::rule_message_ids($document) as $id) {
                if (!in_array($id, $messageids, true)) {
                    continue;
                }
                $usage[$id][(int)$rule->id] = [
                    'id' => (int)$rule->id,
                    'name' => (string)$rule->rulename,
                    'url' => taskflow_result_link_builder::edit_rule_url((int)$rule->id),
                ];
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
