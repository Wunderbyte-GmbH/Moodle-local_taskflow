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
use local_taskflow\local\units\organisational_units_factory;
use local_taskflow\local\units\unit_hierarchy;
use local_taskflow\local\wizard\engine\skill_risk_class;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\taskflow_input_normalizer;
use local_taskflow\local\wizard\taskflow\taskflow_result_link_builder;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;

/**
 * Read-only skill local_taskflow.list_units (implementation plan §2 #7).
 *
 * Lists the organisational units of the active backend (setting
 * local_taskflow/organisational_unit_option: own unit tables or core cohorts) with their
 * position in the hierarchy (depth and path from unit_hierarchy), the member count of the
 * backend unit object and the number of rules attached to the unit
 * (local_taskflow_rules.unitid). Reading the whole organisation is an administrative view,
 * so local/taskflow:viewreports is declared as a native capability.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class list_units_skill extends taskflow_skill_base {
    /** Skill name. */
    public const TASK_NAME = 'local_taskflow.list_units';

    /** Capability required to read the organisational structure. */
    public const CAP_VIEWREPORTS = 'local/taskflow:viewreports';

    /** Backend name: own unit tables. */
    public const BACKEND_UNIT = 'unit';

    /** Backend name: core cohorts. */
    public const BACKEND_COHORT = 'cohort';

    /** Default number of units. */
    public const DEFAULT_LIMIT = 100;

    /** Issue code: the parent unit does not exist. */
    public const ISSUE_UNIT_NOT_FOUND = 'TASKFLOW_UNIT_NOT_FOUND';

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(true, skill_risk_class::R0, [self::CAP_VIEWREPORTS]);
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
            'description' => 'List the organisational units (own unit tables or cohorts, depending on the configured '
                . 'backend) with hierarchy depth, parent, member count and number of rules attached to the unit.',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'Which organisational units exist?',
                'Show the units below unit 3',
                'How many members does the unit Administration have?',
                'Which units have no rules?',
                'Show the organisational structure',
            ],
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Only units whose name contains this text.',
                    'required' => false,
                ],
                'parentid' => [
                    'type' => 'integer',
                    'description' => 'Only this unit and the units below it in the hierarchy.',
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
            'intent' => 'List the organisational units with hierarchy, member counts and rule counts.',
            'input_fields_for_prompt' => ['query, parentid'],
            'anchor_fields' => ['query'],
        ];
    }

    /**
     * Example input for the planner contract.
     *
     * @return array
     */
    public function get_example_input(): array {
        return ['query' => 'Administration'];
    }

    /**
     * Preflight: normalize the filters and verify the parent unit.
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

        $prepared = $input;
        $query = trim((string)($input['query'] ?? ''));
        if ($query === '') {
            unset($prepared['query']);
        } else {
            $prepared['query'] = $query;
        }

        $parentid = taskflow_input_normalizer::to_int($input['parentid'] ?? null) ?? 0;
        if ($parentid > 0) {
            if (!array_key_exists($parentid, $this->all_units())) {
                return $this->invalid([
                    $this->not_found_issue(
                        self::ISSUE_UNIT_NOT_FOUND,
                        $this->localized_string('agent_unit_notfound', (string)$parentid, $lang),
                        ['field' => 'parentid']
                    ),
                ]);
            }
            $prepared['parentid'] = $parentid;
        } else {
            unset($prepared['parentid']);
        }

        return $this->pass($prepared);
    }

    /**
     * Execute: assemble the unit list.
     *
     * @param array $input Prepared input.
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        $lang = $this->get_output_language($input);
        $debug = $this->build_task_debug_message(self::TASK_NAME, $input);

        if (!$this->may_read($userid)) {
            return $this->error_result(
                self::ISSUE_SCOPE_DENIED,
                $this->localized_string('agent_scope_denied', null, $lang),
                ['debugmessage' => $debug]
            );
        }

        $backend = $this->backend();
        $all = $this->all_units();
        $hierarchy = $this->hierarchy();
        $rulecounts = $this->rule_counts();

        $parentid = taskflow_input_normalizer::to_int($input['parentid'] ?? null) ?? 0;
        $allowed = null;
        if ($parentid > 0) {
            $allowed = [$parentid => true];
            foreach ($this->descendants($parentid, $hierarchy) as $childid) {
                $allowed[$childid] = true;
            }
        }
        $query = trim((string)($input['query'] ?? ''));

        $units = [];
        foreach ($all as $unitid => $name) {
            $unitid = (int)$unitid;
            $name = (string)$name;
            if ($allowed !== null && !isset($allowed[$unitid])) {
                continue;
            }
            if ($query !== '' && \core_text::strpos(\core_text::strtolower($name), \core_text::strtolower($query)) === false) {
                continue;
            }
            $entry = $hierarchy[$unitid] ?? [];
            $path = (string)($entry['pathtoou'] ?? (string)$unitid);
            $units[] = [
                'id' => $unitid,
                'name' => $name,
                'parentid' => $this->parent_from_path($path, $unitid),
                'depth' => (int)($entry['depth'] ?? 1),
                'path' => $path,
                'members' => $this->count_members($unitid),
                'rules_count' => (int)($rulecounts[$unitid] ?? 0),
            ];
            if (count($units) >= self::DEFAULT_LIMIT) {
                break;
            }
        }

        $total = count($units);
        $usermessage = $this->localized_string('agent_list_units_summary', (object)[
            'count' => $total,
            'backend' => $backend,
        ], $lang);

        $observation = [$usermessage];
        foreach ($units as $unit) {
            $observation[] = sprintf(
                '#%d %s | %s %d | %s: %d | %s: %d%s',
                $unit['id'],
                $unit['name'],
                $this->localized_string('agent_preview_depth', null, $lang),
                $unit['depth'],
                $this->localized_string('agent_preview_members', null, $lang),
                $unit['members'],
                $this->localized_string('agent_preview_rules', null, $lang),
                $unit['rules_count'],
                $unit['parentid'] > 0 ? ' | parentid=' . $unit['parentid'] : ''
            );
        }

        return $this->base_result(self::STATUS_EXECUTED, [
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'observation_full' => implode("\n", $observation),
            'resultid' => $units[0]['id'] ?? 0,
            'units' => $units,
            'backend' => $backend,
            'total' => $total,
            'links' => $this->links(taskflow_result_link_builder::dashboard_url(), ['units_and_users']),
            'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, [
                'Backend: ' . $backend,
                'Units: ' . $total . ' of ' . count($all),
            ]),
            'preview' => [
                'type' => taskflow_preview_renderer_factory::TYPE_UNITS_TREE,
                'data' => [
                    'units' => $units,
                    'backend' => $backend,
                    'total' => $total,
                    'parentid' => $parentid,
                    'query' => $query,
                ],
                'payload' => [
                    'unitids' => array_column($units, 'id'),
                ],
            ],
        ]);
    }

    /**
     * Whether the acting user may read the organisational structure.
     *
     * @param int $userid
     * @return bool
     */
    private function may_read(int $userid): bool {
        return $userid > 0 && has_capability(self::CAP_VIEWREPORTS, context_system::instance(), $userid);
    }

    /**
     * Active unit backend from the plugin setting.
     *
     * @return string unit|cohort
     */
    private function backend(): string {
        $option = trim((string)get_config('local_taskflow', 'organisational_unit_option'));
        return $option === self::BACKEND_COHORT ? self::BACKEND_COHORT : self::BACKEND_UNIT;
    }

    /**
     * All units of the active backend: id => display name.
     *
     * @return array<int,string>
     */
    private function all_units(): array {
        try {
            $units = (array)organisational_units_factory::instance()->get_units();
        } catch (\Throwable $e) {
            return [];
        }
        $normalized = [];
        foreach ($units as $unitid => $name) {
            $normalized[(int)$unitid] = (string)$name;
        }
        return $normalized;
    }

    /**
     * Hierarchy entries (depth and path) by unit id.
     *
     * @return array<int,array{depth:int,pathtoou:string}>
     */
    private function hierarchy(): array {
        try {
            $hierarchy = (array)(new unit_hierarchy())->get();
        } catch (\Throwable $e) {
            return [];
        }
        $normalized = [];
        foreach ($hierarchy as $unitid => $entry) {
            $entry = (array)$entry;
            $normalized[(int)$unitid] = [
                'depth' => (int)($entry['depth'] ?? 1),
                'pathtoou' => (string)($entry['pathtoou'] ?? (string)$unitid),
            ];
        }
        return $normalized;
    }

    /**
     * Descendant unit ids of one unit (path contains the unit id).
     *
     * @param int $unitid
     * @param array<int,array{depth:int,pathtoou:string}> $hierarchy
     * @return int[]
     */
    private function descendants(int $unitid, array $hierarchy): array {
        $ids = [];
        foreach ($hierarchy as $childid => $entry) {
            if ((int)$childid === $unitid) {
                continue;
            }
            $segments = array_map('intval', array_filter(explode('/', (string)$entry['pathtoou']), 'strlen'));
            if (in_array($unitid, $segments, true)) {
                $ids[] = (int)$childid;
            }
        }
        return $ids;
    }

    /**
     * Parent unit id taken from the hierarchy path ('root/.../parent/unit').
     *
     * @param string $path
     * @param int $unitid
     * @return int 0 for root units.
     */
    private function parent_from_path(string $path, int $unitid): int {
        $segments = array_values(array_map('intval', array_filter(explode('/', $path), 'strlen')));
        $position = array_search($unitid, $segments, true);
        if ($position === false || $position < 1) {
            return 0;
        }
        return (int)$segments[$position - 1];
    }

    /**
     * Member count of one unit through the backend unit object.
     *
     * @param int $unitid
     * @return int
     */
    private function count_members(int $unitid): int {
        try {
            $unit = organisational_unit_factory::instance($unitid);
        } catch (\Throwable $e) {
            return 0;
        }
        if (!is_object($unit) || !method_exists($unit, 'count_members')) {
            return 0;
        }
        try {
            return (int)$unit->count_members();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Number of rules per unit id.
     *
     * @return array<int,int>
     */
    private function rule_counts(): array {
        global $DB;

        $records = $DB->get_records_sql(
            "SELECT unitid, COUNT(id) AS cnt FROM {local_taskflow_rules} WHERE unitid > 0 GROUP BY unitid"
        );
        $counts = [];
        foreach ($records as $record) {
            $counts[(int)$record->unitid] = (int)$record->cnt;
        }
        return $counts;
    }
}
