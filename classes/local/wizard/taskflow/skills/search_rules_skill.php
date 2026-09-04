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
use local_taskflow\local\units\organisational_unit_factory;
use local_taskflow\local\wizard\engine\skill_risk_class;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\taskflow_input_normalizer;
use local_taskflow\local\wizard\taskflow\taskflow_result_link_builder;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;

/**
 * Skill local_taskflow.search_rules: list/search taskflow rules (implementation plan §2 #3).
 *
 * Read-only, R0, native capability local/taskflow:viewrules. Rows come straight from
 * {local_taskflow_rules} (the rules dashboard uses SQL as well); every hit is decoded through
 * rules::instance() so the target types can be listed and filtered.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_rules_skill extends taskflow_skill_base {
    /** Skill name. */
    public const TASK_NAME = 'local_taskflow.search_rules';

    /** Native capability required to list rules. */
    public const CAPABILITY = 'local/taskflow:viewrules';

    /** Issue code: unknown target type filter. */
    public const ISSUE_INVALID_TARGETTYPE = 'TASKFLOW_INVALID_TARGETTYPE';

    /** Target types a rule can carry (classes below local/actions/targets/types). */
    public const TARGET_TYPES = ['moodlecourse', 'bookingoption', 'competency'];

    /** Default number of rules returned. */
    public const DEFAULT_LIMIT = 25;

    /** Hard cap of rules returned. */
    public const MAX_LIMIT = 100;

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
            'description' => 'Search and list taskflow RULES (the definitions that assign trainings, courses, '
                . 'booking options or competencies to the members of an organisational unit or to a single '
                . 'person). Filters: name text, unit id, active flag, target type. Returns id, name, type, unit, '
                . 'target types and the number of assignments per rule. Use get_rule_details for one rule.',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'Show me all taskflow rules',
                'Which rules exist for unit 12?',
                'List the inactive rules',
                'Find the rule called "Data protection basics"',
                'Which rules assign a booking option?',
                'Search rules containing "safety"',
            ],
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Optional text matched against the rule name (case-insensitive substring). '
                        . 'A pure number is also matched against the rule id.',
                    'required' => false,
                ],
                'unitid' => [
                    'type' => 'integer',
                    'description' => 'Optional organisational unit id (cohort id in cohort mode) the rules belong to.',
                    'required' => false,
                ],
                'isactive' => [
                    'type' => 'boolean',
                    'description' => 'Optional: true = only active rules, false = only inactive rules. '
                        . 'Omit to return both.',
                    'required' => false,
                ],
                'targettype' => [
                    'type' => 'string',
                    'enum' => self::TARGET_TYPES,
                    'description' => 'Optional: only rules that contain at least one target of this type '
                        . '(moodlecourse, bookingoption or competency).',
                    'required' => false,
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of rules to return (default 25, max 100).',
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
            'intent' => 'List or find taskflow rules by name, unit, activity state or target type.',
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

        if (isset($input['query']) && !is_string($input['query']) && !is_numeric($input['query'])) {
            $errors[] = $this->localized_string('agent_search_rules_query_must_be_string', null, $lang);
        }
        if (isset($input['targettype']) && trim((string)$input['targettype']) !== '') {
            $targettype = strtolower(trim((string)$input['targettype']));
            if (!in_array($targettype, self::TARGET_TYPES, true)) {
                $errors[] = $this->localized_string('agent_invalid_targettype', $targettype, $lang);
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
        if (!has_capability(self::CAPABILITY, context_system::instance(), $userid)) {
            return $this->invalid([$this->scope_denied_issue($lang)]);
        }

        $structure = $this->check_structure($input);
        if (!($structure['valid'] ?? false)) {
            $issues = [];
            foreach ((array)($structure['errors'] ?? []) as $error) {
                $issues[] = [
                    'code' => isset($input['targettype']) ? self::ISSUE_INVALID_TARGETTYPE : 'VALIDATION_ERROR',
                    'severity' => 'needs_clarification',
                    'message' => (string)$error,
                ];
            }
            return $this->invalid($issues);
        }

        return $this->pass($this->normalize_input($input));
    }

    /**
     * Execute: query the rule rows, decode each hit, build result + preview data.
     *
     * @param array $input
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
                ['links' => $this->links(null, ['rules'])]
            );
        }

        $input = $this->normalize_input($input);
        $query = (string)($input['query'] ?? '');
        $unitid = $input['unitid'] ?? null;
        $isactive = $input['isactive'] ?? null;
        $targettype = (string)($input['targettype'] ?? '');
        $limit = (int)($input['limit'] ?? self::DEFAULT_LIMIT);

        [$where, $params] = $this->build_where($query, $unitid, $isactive);
        $rows = $DB->get_records_select('local_taskflow_rules', $where, $params, 'rulename ASC, id ASC', 'id');

        $matches = [];
        foreach ($rows as $row) {
            $rule = $this->resolve_rule((int)$row->id);
            if (empty($rule)) {
                continue;
            }
            $targettypes = $this->target_types_of($rule['rule']);
            if ($targettype !== '' && !in_array($targettype, $targettypes, true)) {
                continue;
            }
            $matches[] = [
                'id' => $rule['id'],
                'name' => $rule['rulename'],
                'description' => (string)($rule['rule']['description'] ?? ''),
                'type' => $rule['userid'] > 0 && $rule['unitid'] <= 0 ? 'user' : 'unit',
                'unitid' => $rule['unitid'],
                'unitname' => '',
                'userid' => $rule['userid'],
                'isactive' => $rule['isactive'],
                'targettypes' => $targettypes,
                'assignments_count' => 0,
                'edit_url' => taskflow_result_link_builder::edit_rule_url($rule['id']),
            ];
        }

        $total = count($matches);
        $shown = array_slice($matches, 0, $limit);
        $shown = $this->add_counts_and_unit_names($shown);

        $ruleids = array_map(static fn(array $rule): int => (int)$rule['id'], $shown);
        $links = $this->links(taskflow_result_link_builder::dashboard_url(), ['rules']);

        if (empty($shown)) {
            $usermessage = $this->localized_string('agent_search_rules_none', null, $lang);
        } else {
            $usermessage = $this->localized_string('agent_search_rules_summary', (object)[
                'count' => count($shown),
                'total' => $total,
            ], $lang);
        }

        $debug = $this->build_task_debug_message(self::TASK_NAME, $input, [
            'Results: ' . count($shown) . ' of ' . $total,
            'Rule ids: ' . implode(', ', $ruleids),
        ]);

        return $this->base_result(self::STATUS_EXECUTED, [
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'observation_full' => $this->build_observation_full($usermessage, $shown, $total),
            'resultid' => (int)($ruleids[0] ?? 0),
            'rules' => $shown,
            'total' => $total,
            'links' => $links,
            'debugmessage' => $debug,
            'outputlang' => $lang,
            'preview' => [
                'type' => taskflow_preview_renderer_factory::TYPE_RULE_LIST,
                'data' => [
                    'rules' => $shown,
                    'total' => $total,
                    'query' => $query,
                    'limit' => $limit,
                ],
                'payload' => ['ruleids' => $ruleids],
            ],
        ]);
    }

    /**
     * Coerce the raw input into typed values (limit clamped, targettype lower-cased).
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

        $unitid = taskflow_input_normalizer::to_int($input['unitid'] ?? null);
        if ($unitid === null || $unitid <= 0) {
            unset($normalized['unitid']);
        } else {
            $normalized['unitid'] = $unitid;
        }

        $isactive = taskflow_input_normalizer::to_bool($input['isactive'] ?? null);
        if ($isactive === null) {
            unset($normalized['isactive']);
        } else {
            $normalized['isactive'] = $isactive;
        }

        $targettype = strtolower(trim((string)($input['targettype'] ?? '')));
        if ($targettype === '') {
            unset($normalized['targettype']);
        } else {
            $normalized['targettype'] = $targettype;
        }

        $limit = taskflow_input_normalizer::to_int($input['limit'] ?? null);
        $normalized['limit'] = ($limit === null || $limit <= 0) ? self::DEFAULT_LIMIT : min($limit, self::MAX_LIMIT);

        return $normalized;
    }

    /**
     * WHERE clause + params for the rule row query.
     *
     * @param string $query
     * @param int|null $unitid
     * @param bool|null $isactive
     * @return array{0:string,1:array}
     */
    private function build_where(string $query, ?int $unitid, ?bool $isactive): array {
        global $DB;

        $conditions = ['1 = 1'];
        $params = [];

        if ($query !== '') {
            $like = $DB->sql_like('rulename', ':query', false, false);
            $params['query'] = '%' . $DB->sql_like_escape($query) . '%';
            if (preg_match('/^\d+$/', $query)) {
                $conditions[] = '(' . $like . ' OR id = :queryid)';
                $params['queryid'] = (int)$query;
            } else {
                $conditions[] = $like;
            }
        }
        if ($unitid !== null) {
            $conditions[] = 'unitid = :unitid';
            $params['unitid'] = $unitid;
        }
        if ($isactive !== null) {
            $conditions[] = 'isactive = :isactive';
            $params['isactive'] = $isactive ? 1 : 0;
        }

        return [implode(' AND ', $conditions), $params];
    }

    /**
     * Distinct target types of a decoded rule document.
     *
     * @param array $rule rulejson.rule
     * @return string[]
     */
    private function target_types_of(array $rule): array {
        $types = [];
        foreach ((array)($rule['actions'] ?? []) as $action) {
            foreach ((array)($action['targets'] ?? []) as $target) {
                $type = strtolower(trim((string)($target['targettype'] ?? '')));
                if ($type !== '' && !in_array($type, $types, true)) {
                    $types[] = $type;
                }
            }
        }
        return $types;
    }

    /**
     * Fill assignments_count (one grouped query) and unitname (unit factory, cached per id).
     *
     * @param array $rules
     * @return array
     */
    private function add_counts_and_unit_names(array $rules): array {
        global $DB;

        if (empty($rules)) {
            return $rules;
        }

        $ruleids = array_map(static fn(array $rule): int => (int)$rule['id'], $rules);
        [$insql, $params] = $DB->get_in_or_equal($ruleids, SQL_PARAMS_NAMED);
        $counts = $DB->get_records_sql_menu(
            "SELECT ruleid, COUNT(id) AS assignments
               FROM {local_taskflow_assignment}
              WHERE ruleid $insql
           GROUP BY ruleid",
            $params
        );

        $unitnames = [];
        foreach ($rules as $index => $rule) {
            $rules[$index]['assignments_count'] = (int)($counts[(int)$rule['id']] ?? 0);
            $unitid = (int)$rule['unitid'];
            if ($unitid > 0) {
                if (!array_key_exists($unitid, $unitnames)) {
                    $unitnames[$unitid] = $this->unit_name($unitid);
                }
                $rules[$index]['unitname'] = $unitnames[$unitid];
            }
        }

        return $rules;
    }

    /**
     * Name of an organisational unit ('' when unresolvable, e.g. mode not configured).
     *
     * @param int $unitid
     * @return string
     */
    private function unit_name(int $unitid): string {
        try {
            $unit = organisational_unit_factory::instance($unitid);
        } catch (\Throwable $e) {
            return '';
        }
        return is_object($unit) && method_exists($unit, 'get_name') ? (string)$unit->get_name() : '';
    }

    /**
     * Observation payload for follow-up reasoning steps (message + JSON list).
     *
     * @param string $usermessage
     * @param array $rules
     * @param int $total
     * @return string
     */
    private function build_observation_full(string $usermessage, array $rules, int $total): string {
        $json = json_encode(
            ['total' => $total, 'rules' => array_values($rules)],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if (!is_string($json) || $json === '') {
            return $usermessage;
        }
        return $usermessage . "\n\nRules payload (JSON):\n" . $json;
    }
}
