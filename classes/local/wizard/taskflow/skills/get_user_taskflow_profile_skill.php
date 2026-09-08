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
use local_taskflow\local\deputy\deputy;
use local_taskflow\local\external_adapter\external_api_base;
use local_taskflow\local\supervisor\supervisor;
use local_taskflow\local\units\organisational_unit_factory;
use local_taskflow\local\units\organisational_units_factory;
use local_taskflow\local\wizard\engine\observation_time;
use local_taskflow\local\wizard\engine\skill_risk_class;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\taskflow_input_normalizer;
use local_taskflow\local\wizard\taskflow\taskflow_permission_resolver;
use local_taskflow\local\wizard\taskflow\taskflow_result_link_builder;
use local_taskflow\local\wizard\taskflow\taskflow_settings_catalog;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use local_taskflow\plugininfo\taskflowadapter;
use stdClass;

/**
 * Read-only skill local_taskflow.get_user_taskflow_profile (implementation plan §2 #8).
 *
 * Taskflow view of one person: organisational units, supervisor and deputies, the
 * adapter-mapped profile fields (contract start/end, long leave, external id, ...),
 * supervisor role membership and the assignment counts per status. Defaults to the acting
 * user; other users require scope (admin > supervisor/deputy) or local/taskflow:viewreports.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_user_taskflow_profile_skill extends taskflow_skill_base {
    /** Skill name. */
    public const TASK_NAME = 'local_taskflow.get_user_taskflow_profile';

    /** Capability that grants reading any profile. */
    public const CAP_VIEWREPORTS = 'local/taskflow:viewreports';

    /**
     * Adapter user functions reported as mapped fields: result key => translator constant.
     *
     * @var array<string,string>
     */
    public const MAPPED_FUNCTIONS = [
        'supervisor' => taskflowadapter::TRANSLATOR_USER_SUPERVISOR,
        'supervisor_external' => taskflowadapter::TRANSLATOR_USER_SUPERVISOR_EXTERNAL,
        'deputy' => taskflowadapter::TRANSLATOR_USER_DEPUTY,
        'longleave' => taskflowadapter::TRANSLATOR_USER_LONG_LEAVE,
        'contractend' => taskflowadapter::TRANSLATOR_USER_CONTRACTEND,
        'contractstart' => taskflowadapter::TRANSLATOR_USER_CONTRACTSTART,
        'externalid' => taskflowadapter::TRANSLATOR_USER_EXTERNALID,
        'orgunit' => taskflowadapter::TRANSLATOR_USER_ORGUNIT,
        'units' => taskflowadapter::TRANSLATOR_USER_TARGETGROUP,
    ];

    /** Mapped functions whose values are Unix timestamps. */
    private const DATE_FUNCTIONS = ['contractend', 'contractstart'];

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
            'description' => 'Get the taskflow profile of a person: organisational units, supervisor, deputies, '
                . 'contract end, long leave, external id, supervisor role and assignment counts per status. '
                . 'Without userid/userquery the acting user is described.',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'Who is the supervisor of Anna Muster?',
                'Which units does anna.muster@example.org belong to?',
                'When does the contract of user 123 end?',
                'Show my taskflow profile',
                'How many assignments does Bert Beispiel have per status?',
            ],
            'properties' => [
                'userid' => [
                    'type' => 'integer',
                    'description' => 'User id. Defaults to the acting user when neither userid nor userquery is given.',
                    'required' => false,
                ],
                'userquery' => [
                    'type' => 'string',
                    'description' => 'User id, e-mail, username or name to resolve when the id is unknown.',
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
            'intent' => 'Describe one person from the taskflow perspective (units, supervisor, contract, counts).',
            'input_fields_for_prompt' => ['userquery (or userid; omit for the acting user)'],
            'anchor_fields' => ['userquery', 'userid'],
        ];
    }

    /**
     * Example input for the planner contract.
     *
     * @return array
     */
    public function get_example_input(): array {
        return ['userquery' => 'anna.muster@example.org'];
    }

    /**
     * Preflight: resolve the user and check the scope.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array{status:string,prepared_input:array,issues:array}
     */
    protected function run_preflight(array $input, int $contextid, int $userid): array {
        $lang = $this->get_output_language($input);
        $targetuserid = $this->resolve_userid($input, $userid);
        if ($targetuserid <= 0) {
            return $this->invalid([$this->user_lookup_issue($input, $lang, $targetuserid)]);
        }
        $user = \core_user::get_user($targetuserid, '*', IGNORE_MISSING);
        if (!$user || !empty($user->deleted)) {
            return $this->invalid([
                $this->not_found_issue(
                    self::ISSUE_USER_NOT_FOUND,
                    $this->localized_string('agent_user_notfound', $this->user_query_label($input, $targetuserid), $lang),
                    ['field' => 'userid']
                ),
            ]);
        }

        if (!$this->may_read($targetuserid, $userid)) {
            return $this->invalid([$this->scope_denied_issue($lang, ['field' => 'userid'])]);
        }

        $prepared = $input;
        $prepared['userid'] = $targetuserid;
        unset($prepared['userquery']);
        return $this->pass($prepared);
    }

    /**
     * Execute: assemble the profile payload.
     *
     * @param array $input Prepared input.
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/profile/lib.php');

        $lang = $this->get_output_language($input);
        $targetuserid = taskflow_input_normalizer::to_int($input['userid'] ?? null) ?? 0;
        if ($targetuserid <= 0) {
            $targetuserid = $this->resolve_userid($input, $userid);
        }
        $debug = $this->build_task_debug_message(self::TASK_NAME, $input);

        $user = $targetuserid > 0 ? \core_user::get_user($targetuserid, '*', IGNORE_MISSING) : null;
        if (!$user || !empty($user->deleted)) {
            $issue = $this->user_lookup_issue($input, $lang, $targetuserid);
            return $this->error_result((string)$issue['code'], (string)$issue['message'], ['debugmessage' => $debug]);
        }
        if (!$this->may_read($targetuserid, $userid)) {
            return $this->error_result(
                self::ISSUE_SCOPE_DENIED,
                $this->localized_string('agent_scope_denied', null, $lang),
                ['debugmessage' => $debug]
            );
        }

        $scope = $this->permissions()->scope_for_user($targetuserid, $userid);
        if ($scope === taskflow_permission_resolver::SCOPE_NONE) {
            $scope = 'viewreports';
        }

        $profile = [
            'id' => (int)$user->id,
            'fullname' => fullname($user),
            'firstname' => (string)$user->firstname,
            'lastname' => (string)$user->lastname,
            'email' => (string)$user->email,
            'username' => (string)$user->username,
            'suspended' => !empty($user->suspended),
            'url' => taskflow_result_link_builder::user_url((int)$user->id),
        ];

        $units = $this->build_units($targetuserid);
        $supervisor = $this->supervisor_of($targetuserid);
        $deputies = $this->deputies_of($user);
        $mapped = $this->build_mapped_fields($targetuserid, $lang);
        $supervisorrole = (int)get_config('local_taskflow', 'supervisorrole');
        $hassupervisorrole = $supervisorrole > 0
            && user_has_role_assignment($targetuserid, $supervisorrole, context_system::instance()->id);
        $subordinates = [];
        try {
            $subordinates = array_map('intval', supervisor::get_visible_subordinate_ids($targetuserid));
        } catch (\Throwable $e) {
            $subordinates = [];
        }
        $counts = $this->assignments_by_status($targetuserid, $lang);
        $adapter = taskflow_settings_catalog::active_adapter();

        $contractend = $mapped['contractend']['value'] ?? null;
        $contractstart = $mapped['contractstart']['value'] ?? null;
        $longleave = array_key_exists('longleave', $mapped) && $mapped['longleave']['value'] !== null
            ? !empty($mapped['longleave']['value']) : null;
        $externalid = $mapped['externalid']['value'] ?? null;

        $links = $this->links(
            taskflow_result_link_builder::user_url($targetuserid),
            ['units_and_users', 'adapters', 'dashboard'],
            [
                'certificates' => taskflow_result_link_builder::my_certificates_url($targetuserid),
                'dashboard' => taskflow_result_link_builder::dashboard_url(),
            ]
        );

        $usermessage = $this->localized_string('agent_get_user_taskflow_profile_summary', (object)[
            'fullname' => $profile['fullname'],
            'units' => count($units),
            'assignments' => $counts['total'],
            'supervisor' => $supervisor ? $supervisor['fullname'] : '-',
        ], $lang);

        $observation = [$usermessage];
        $observation[] = 'userid=' . $profile['id'] . ', email=' . $profile['email'] . ', suspended='
            . ($profile['suspended'] ? 'yes' : 'no') . ', adapter=' . $adapter . ', scope=' . $scope;
        $observation[] = $this->localized_string('agent_preview_units', null, $lang) . ': '
            . (empty($units) ? '-' : implode(', ', array_map(
                static fn(array $unit): string => $unit['name'] . ' (id=' . $unit['id'] . ')',
                $units
            )));
        $observation[] = $this->localized_string('agent_preview_supervisor', null, $lang) . ': '
            . ($supervisor ? $supervisor['fullname'] . ' (id=' . $supervisor['id'] . ', ' . $supervisor['email'] . ')' : '-');
        $observation[] = $this->localized_string('agent_preview_deputies', null, $lang) . ': '
            . (empty($deputies) ? '-' : implode(', ', array_map(
                static fn(array $deputy): string => $deputy['fullname'] . ' (id=' . $deputy['id'] . ')',
                $deputies
            )));
        $observation[] = $this->localized_string('contractend', null, $lang) . ': '
            . ($contractend ? $this->format_time((int)$contractend) : '-');
        $observation[] = $this->localized_string('longleave', null, $lang) . ': '
            . ($longleave === null ? '-' : ($longleave ? 'yes' : 'no'));
        $observation[] = $this->localized_string('externalid', null, $lang) . ': '
            . ($externalid !== null && $externalid !== '' ? $externalid : '-');
        $observation[] = $this->localized_string('agent_preview_is_supervisor', null, $lang) . ': '
            . ($hassupervisorrole ? 'yes' : 'no') . ' (role), subordinates=' . count($subordinates);
        foreach ($mapped as $function => $field) {
            $observation[] = sprintf(
                'mapped %s -> field "%s" = %s',
                $function,
                $field['field'],
                $field['value'] === null ? '-' : $field['value_text']
            );
        }
        $observation[] = $this->localized_string('agent_preview_assignments', null, $lang) . ': ' . $counts['total']
            . (empty($counts['by_status']) ? '' : ' (' . implode(', ', array_map(
                static fn(array $row): string => $row['label'] . ' ' . $row['count'],
                $counts['by_status']
            )) . ')');

        return $this->base_result(self::STATUS_EXECUTED, [
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'observation_full' => implode("\n", $observation),
            'resultid' => $targetuserid,
            'user' => $profile,
            'units' => $units,
            'supervisor' => $supervisor,
            'deputies' => $deputies,
            'contractend' => $contractend !== null ? (int)$contractend : null,
            'contractend_text' => $contractend ? $this->format_time((int)$contractend) : '',
            'contractstart' => $contractstart !== null ? (int)$contractstart : null,
            'contractstart_text' => $contractstart ? $this->format_time((int)$contractstart) : '',
            'longleave' => $longleave,
            'externalid' => $externalid,
            'mapped_fields' => $mapped,
            'is_supervisor' => $hassupervisorrole,
            'subordinates_count' => count($subordinates),
            'assignments_by_status' => $counts['by_status'],
            'assignments_total' => $counts['total'],
            'adapter' => $adapter,
            'scope' => $scope,
            'links' => $links,
            'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, ['Scope: ' . $scope]),
            'preview' => [
                'type' => taskflow_preview_renderer_factory::TYPE_USER_PROFILE,
                'data' => [
                    'user' => $profile,
                    'units' => $units,
                    'supervisor' => $supervisor,
                    'deputies' => $deputies,
                    'contractend' => $contractend !== null ? (int)$contractend : null,
                    'contractstart' => $contractstart !== null ? (int)$contractstart : null,
                    'longleave' => $longleave,
                    'externalid' => $externalid,
                    'mapped_fields' => $mapped,
                    'is_supervisor' => $hassupervisorrole,
                    'subordinates_count' => count($subordinates),
                    'assignments_by_status' => $counts['by_status'],
                    'assignments_total' => $counts['total'],
                    'adapter' => $adapter,
                    'links' => $links,
                ],
                'payload' => ['userids' => [$targetuserid]],
            ],
        ]);
    }

    /**
     * Whether the acting user may read the target's profile (scope or viewreports).
     *
     * @param int $targetuserid
     * @param int $userid
     * @return bool
     */
    private function may_read(int $targetuserid, int $userid): bool {
        if ($this->permissions()->scope_for_user($targetuserid, $userid) !== taskflow_permission_resolver::SCOPE_NONE) {
            return true;
        }
        return $userid > 0 && has_capability(self::CAP_VIEWREPORTS, context_system::instance(), $userid);
    }

    /**
     * Units the user is a member of (backend unit|cohort).
     *
     * @param int $userid
     * @return array<int,array{id:int,name:string}>
     */
    private function build_units(int $userid): array {
        try {
            $all = (array)organisational_units_factory::instance()->get_units();
        } catch (\Throwable $e) {
            return [];
        }
        $units = [];
        foreach ($all as $unitid => $name) {
            try {
                $unit = organisational_unit_factory::instance((int)$unitid);
                if (!is_object($unit) || !$unit->is_member($userid)) {
                    continue;
                }
            } catch (\Throwable $e) {
                continue;
            }
            $units[] = ['id' => (int)$unitid, 'name' => (string)$name];
        }
        return $units;
    }

    /**
     * Supervisor of the user, or null.
     *
     * @param int $userid
     * @return array{id:int,fullname:string,email:string}|null
     */
    private function supervisor_of(int $userid): ?array {
        try {
            $supervisor = supervisor::get_supervisor_for_user($userid);
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_object($supervisor) || empty($supervisor->id)) {
            return null;
        }
        return [
            'id' => (int)$supervisor->id,
            'fullname' => fullname($supervisor),
            'email' => (string)($supervisor->email ?? ''),
        ];
    }

    /**
     * Deputies of the user (deputy profile field, adapter mapped).
     *
     * @param stdClass $user
     * @return array<int,array{id:int,fullname:string,email:string}>
     */
    private function deputies_of(stdClass $user): array {
        try {
            $records = (new deputy(clone $user))->get_deputies_of_user();
        } catch (\Throwable $e) {
            return [];
        }
        $deputies = [];
        foreach ((array)$records as $record) {
            if (!is_object($record) || empty($record->id)) {
                continue;
            }
            $deputies[] = [
                'id' => (int)$record->id,
                'fullname' => fullname($record),
                'email' => (string)($record->email ?? ''),
            ];
        }
        return $deputies;
    }

    /**
     * Adapter-mapped profile fields with their raw values.
     *
     * @param int $userid
     * @param string $lang
     * @return array<string,array{function:string,field:string,value:mixed,value_text:string}>
     */
    private function build_mapped_fields(int $userid, string $lang): array {
        $record = profile_user_record($userid, false);
        $mapped = [];
        foreach (self::MAPPED_FUNCTIONS as $key => $function) {
            $shortname = (string)external_api_base::return_shortname_for_functionname($function);
            if ($shortname === '') {
                continue;
            }
            $value = property_exists($record, $shortname) ? $record->{$shortname} : null;
            if ($value === '' || $value === false) {
                $value = null;
            }
            if ($value !== null && in_array($key, self::DATE_FUNCTIONS, true)) {
                $value = is_numeric($value) ? (int)$value : (strtotime((string)$value) ?: null);
            }
            $mapped[$key] = [
                'function' => $function,
                'field' => $shortname,
                'value' => $value,
                'value_text' => $value === null ? '' : (in_array($key, self::DATE_FUNCTIONS, true)
                    ? $this->format_time((int)$value) : (string)$value),
            ];
        }
        return $mapped;
    }

    /**
     * Assignment counts per status (all assignments, active or not).
     *
     * @param int $userid
     * @param string $lang
     * @return array{total:int,by_status:array<int,array{status:int,label:string,count:int}>}
     */
    private function assignments_by_status(int $userid, string $lang): array {
        global $DB;

        $rows = $DB->get_records_sql(
            "SELECT status, COUNT(id) AS cnt
               FROM {local_taskflow_assignment}
              WHERE userid = :userid
           GROUP BY status
           ORDER BY status",
            ['userid' => $userid]
        );
        $bystatus = [];
        $total = 0;
        foreach ($rows as $row) {
            $count = (int)$row->cnt;
            $total += $count;
            $bystatus[] = [
                'status' => (int)$row->status,
                'label' => $this->status_label((int)$row->status, $lang),
                'count' => $count,
            ];
        }
        return ['total' => $total, 'by_status' => $bystatus];
    }

    /**
     * Timezone-adjusted date text ('' when unset).
     *
     * @param int $timestamp
     * @return string
     */
    private function format_time(int $timestamp): string {
        if ($timestamp <= 0) {
            return '';
        }
        if (class_exists(observation_time::class)) {
            return (string)observation_time::format($timestamp);
        }
        return userdate($timestamp, get_string('strftimedate', 'langconfig'));
    }
}
