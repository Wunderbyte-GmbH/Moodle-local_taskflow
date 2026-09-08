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
use core_component;
use core_plugin_manager;
use local_taskflow\local\supervisor\supervisor;
use local_taskflow\local\wizard\engine\skill_risk_class;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\taskflow_input_normalizer;
use local_taskflow\local\wizard\taskflow\taskflow_result_link_builder;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;

/**
 * Read-only skill local_taskflow.diagnose_permissions (implementation plan §2 #17).
 *
 * Answers "what may this person do in taskflow, and why does the interface look like that?".
 * Every taskflow capability declared in db/access.php is probed with has_capability() in the
 * system context (agent skill capabilities are listed separately), the supervisor role
 * assignment and both HR user lists are read from configuration, the deputy relations come
 * from the supervisor scope, and visible_ui mirrors the gating of output\dashboard and of the
 * taskflow shortcodes one to one.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class diagnose_permissions_skill extends taskflow_skill_base {
    /** Skill name. */
    public const TASK_NAME = 'local_taskflow.diagnose_permissions';

    /** Capability required to inspect the permissions of somebody. */
    public const CAP_VIEWREPORTS = 'local/taskflow:viewreports';

    /** Capability prefix of every taskflow capability. */
    public const CAP_PREFIX = 'local/taskflow:';

    /** Name prefix of the agent skill capabilities. */
    public const SKILL_CAP_PREFIX = 'local/taskflow:skill_';

    /** Plugin holding the second HR user list (booking extension). */
    public const HR_PLUGIN = 'bookingextension_confirmation_supervisor';

    /** Setting of the second HR user list. */
    public const HR_PLUGIN_SETTING = 'confirmation_supervisor_hrusers';

    /**
     * Interface element => capability gating it (mirrors output\dashboard and shortcodes.php).
     *
     * 'dashboard' (shortcode myassignments) has no capability at all; 'admin_tab' additionally
     * accepts membership in the booking extension HR list, both handled in build_visible_ui().
     *
     * @var array<string,string>
     */
    public const UI_CAPABILITIES = [
        'dashboard' => '',
        'supervisor_tab' => 'local/taskflow:issupervisor',
        'admin_tab' => 'local/taskflow:editassignment',
        'requests_tab' => 'local/taskflow:viewrequests',
        'rules' => 'local/taskflow:viewrules',
    ];

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
            'description' => 'Diagnose the taskflow permissions of a person: every local/taskflow capability in the '
                . 'system context, the supervisor role, both HR user lists, the deputy relations and which parts '
                . 'of the taskflow interface are visible (supervisor tab, admin tab, requests tab, HR lists). '
                . 'Prefer this over core.diagnose_permissions for any question about the taskflow UI or taskflow '
                . 'rights: the taskflow tabs depend on taskflow capabilities and HR lists, not on site:config. '
                . 'Read-only.',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'Why does Anna Muster not see the supervisor dashboard?',
                'Why is the taskflow admin tab missing for user 4021?',
                'Which taskflow permissions does user 123 have?',
                'Is Dr. Emily Smith an HR user?',
                'May bert.beispiel@example.org treat requests?',
            ],
            'properties' => [
                'userid' => [
                    'type' => 'integer',
                    'description' => 'User id. Defaults to the acting user when neither userid nor userquery is given.',
                    'required' => false,
                ],
                'userquery' => [
                    'type' => 'string',
                    'description' => 'User id, e-mail, username or name when the user id is unknown.',
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
            'intent' => 'Report the taskflow permissions and the resulting visible interface of one person.',
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
     * Preflight: resolve the user, require the reporting capability.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array{status:string,prepared_input:array,issues:array}
     */
    protected function run_preflight(array $input, int $contextid, int $userid): array {
        $lang = $this->get_output_language($input);
        $targetuserid = $this->resolve_userid($input, $userid);
        $user = $targetuserid > 0 ? \core_user::get_user($targetuserid, '*', IGNORE_MISSING) : null;
        if (!$user || !empty($user->deleted)) {
            return $this->invalid([$this->user_lookup_issue($input, $lang, $targetuserid)]);
        }
        if (!$this->may_report($userid)) {
            return $this->invalid([$this->scope_denied_issue($lang, ['field' => 'userid'])]);
        }

        $prepared = $input;
        $prepared['userid'] = $targetuserid;
        unset($prepared['userquery']);
        return $this->pass($prepared);
    }

    /**
     * Execute: probe capabilities, roles, HR lists and the derived interface.
     *
     * @param array $input Prepared input.
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
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
        if (!$this->may_report($userid)) {
            return $this->error_result(
                self::ISSUE_SCOPE_DENIED,
                $this->localized_string('agent_scope_denied', null, $lang),
                ['debugmessage' => $debug]
            );
        }

        $capabilities = $this->probe_capabilities($targetuserid, false);
        $skillcapabilities = $this->probe_capabilities($targetuserid, true);
        $supervisorrole = $this->supervisor_role($targetuserid);
        $hr = $this->hr_membership($targetuserid);
        $deputyof = $this->deputy_of($targetuserid);
        $visibleui = $this->build_visible_ui($targetuserid, $hr);

        $granted = count(array_filter($capabilities, static fn(array $row): bool => $row['granted']));
        $checks = $this->build_checks($capabilities, $skillcapabilities, $supervisorrole, $hr, $deputyof, $visibleui, $lang);

        $links = $this->links(
            taskflow_result_link_builder::user_url($targetuserid),
            ['capabilities', 'dashboard'],
            ['dashboard' => taskflow_result_link_builder::dashboard_url()]
        );

        $usermessage = $this->localized_string('agent_diagnose_permissions_summary', (object)[
            'fullname' => fullname($user),
            'granted' => $granted,
            'total' => count($capabilities),
            'supervisor' => get_string($supervisorrole['assigned'] ? 'yes' : 'no'),
        ], $lang);

        $observation = [$usermessage];
        foreach ($capabilities as $capability) {
            $observation[] = sprintf('%s = %s', $capability['capability'], $capability['granted'] ? 'yes' : 'no');
        }
        $observation[] = 'skill capabilities granted: ' . count(array_filter(
            $skillcapabilities,
            static fn(array $row): bool => $row['granted']
        )) . '/' . count($skillcapabilities);
        $observation[] = 'supervisorrole=' . ($supervisorrole['assigned'] ? 'yes' : 'no')
            . ' (roleid=' . $supervisorrole['roleid'] . ')';
        $observation[] = 'hr taskflow=' . ($hr['taskflow'] ? 'yes' : 'no')
            . ', hr bookingextension=' . ($hr['bookingextension'] === null ? 'n/a' : ($hr['bookingextension'] ? 'yes' : 'no'));
        $observation[] = 'deputy of: ' . (empty($deputyof) ? '-' : implode(', ', array_map(
            static fn(array $row): string => $row['fullname'] . ' (id=' . $row['id'] . ')',
            $deputyof
        )));
        foreach ($visibleui as $key => $visible) {
            $observation[] = 'ui ' . $key . '=' . ($visible ? 'yes' : 'no');
        }

        return $this->base_result(self::STATUS_EXECUTED, [
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'observation_full' => implode("\n", $observation),
            'resultid' => $targetuserid,
            'user' => ['id' => $targetuserid, 'fullname' => fullname($user), 'email' => (string)$user->email],
            'capabilities' => $capabilities,
            'skill_capabilities' => $skillcapabilities,
            'capabilities_granted' => $granted,
            'is_supervisor_role' => $supervisorrole['assigned'],
            'supervisor_role' => $supervisorrole,
            'is_hr' => ['taskflow' => $hr['taskflow'], 'bookingextension' => $hr['bookingextension']],
            'is_deputy_of' => $deputyof,
            'visible_ui' => $visibleui,
            'links' => $links,
            'debugmessage' => $debug,
            'preview' => [
                'type' => taskflow_preview_renderer_factory::TYPE_DIAGNOSTIC_CHECKLIST,
                'data' => [
                    'title' => $this->localized_string('agent_preview_diagnosis_title', (object)[
                        'subject' => fullname($user),
                        'object' => $this->localized_string('agent_permissions_capabilities', null, $lang),
                    ], $lang),
                    'rows' => $checks,
                    'links' => $links,
                    'ids' => ['userids' => [$targetuserid]],
                ],
                'payload' => ['userids' => [$targetuserid]],
            ],
        ]);
    }

    /**
     * Whether the acting user may run the report.
     *
     * @param int $userid
     * @return bool
     */
    private function may_report(int $userid): bool {
        return $userid > 0 && has_capability(self::CAP_VIEWREPORTS, context_system::instance(), $userid);
    }

    /**
     * Probe every declared local/taskflow capability in the system context.
     *
     * The capability list comes from get_capability_info() over the capabilities the access
     * definition of the plugin declares, so the report can never drift from db/access.php.
     *
     * @param int $targetuserid
     * @param bool $skillcaps True = only the agent skill capabilities, false = only the others.
     * @return array<int,array{capability:string,name:string,granted:bool,contextlevel:int}>
     */
    private function probe_capabilities(int $targetuserid, bool $skillcaps): array {
        $context = context_system::instance();
        $rows = [];
        foreach ($this->declared_capabilities() as $capability) {
            $isskillcap = strpos($capability, self::SKILL_CAP_PREFIX) === 0;
            if ($isskillcap !== $skillcaps) {
                continue;
            }
            $info = get_capability_info($capability);
            $stringkey = str_replace('local/', '', $capability);
            $rows[] = [
                'capability' => $capability,
                'name' => get_string_manager()->string_exists($stringkey, 'local_taskflow')
                    ? $this->localized_string($stringkey) : $capability,
                'granted' => has_capability($capability, $context, $targetuserid),
                'contextlevel' => $info === null ? CONTEXT_SYSTEM : (int)$info->contextlevel,
            ];
        }
        return $rows;
    }

    /**
     * Every capability name declared by local_taskflow (db/access.php), sorted.
     *
     * @return string[]
     */
    private function declared_capabilities(): array {
        $capabilities = array_keys((array)get_all_capabilities());
        $own = array_values(array_filter(
            $capabilities,
            static fn($capability): bool => is_string($capability) && strpos($capability, self::CAP_PREFIX) === 0
        ));
        if (empty($own)) {
            $own = $this->capabilities_from_access_file();
        }
        sort($own);
        return $own;
    }

    /**
     * Fallback when the capabilities are not (yet) installed: read db/access.php directly.
     *
     * @return string[]
     */
    private function capabilities_from_access_file(): array {
        $file = core_component::get_component_directory('local_taskflow') . '/db/access.php';
        if (!is_readable($file)) {
            return [];
        }
        $capabilities = [];
        require($file);
        return array_keys((array)$capabilities);
    }

    /**
     * Supervisor role configuration and assignment.
     *
     * @param int $targetuserid
     * @return array{roleid:int,rolename:string,assigned:bool}
     */
    private function supervisor_role(int $targetuserid): array {
        $roleid = (int)get_config('local_taskflow', 'supervisorrole');
        if ($roleid <= 0) {
            return ['roleid' => 0, 'rolename' => '', 'assigned' => false];
        }
        global $DB;
        $role = $DB->get_record('role', ['id' => $roleid], 'id, name, shortname', IGNORE_MISSING);
        return [
            'roleid' => $roleid,
            'rolename' => $role ? (string)($role->name !== '' ? $role->name : $role->shortname) : '',
            'assigned' => user_has_role_assignment($targetuserid, $roleid, context_system::instance()->id),
        ];
    }

    /**
     * HR membership in both configured HR user lists.
     *
     * @param int $targetuserid
     * @return array{taskflow:bool,bookingextension:bool|null}
     */
    private function hr_membership(int $targetuserid): array {
        $bookingextension = null;
        if (core_plugin_manager::instance()->get_plugin_info(self::HR_PLUGIN) !== null) {
            $bookingextension = $this->is_in_list(
                (string)get_config(self::HR_PLUGIN, self::HR_PLUGIN_SETTING),
                $targetuserid
            );
        }
        return [
            'taskflow' => $this->permissions()->is_hr_user($targetuserid),
            'bookingextension' => $bookingextension,
        ];
    }

    /**
     * Whether a user id appears in a comma separated id list setting.
     *
     * @param string $setting
     * @param int $userid
     * @return bool
     */
    private function is_in_list(string $setting, int $userid): bool {
        $ids = array_map('intval', array_filter(array_map('trim', explode(',', $setting)), 'is_numeric'));
        return $userid > 0 && in_array($userid, $ids, true);
    }

    /**
     * Supervisors the user deputizes for (own subordinates minus the direct team).
     *
     * @param int $targetuserid
     * @return array<int,array{id:int,fullname:string}>
     */
    private function deputy_of(int $targetuserid): array {
        try {
            $subordinates = array_map('intval', supervisor::get_visible_subordinate_ids($targetuserid));
        } catch (\Throwable $e) {
            return [];
        }
        $supervisors = [];
        foreach ($subordinates as $subordinateid) {
            try {
                $direct = supervisor::get_supervisor_for_user($subordinateid);
            } catch (\Throwable $e) {
                continue;
            }
            if (!is_object($direct) || empty($direct->id) || (int)$direct->id === $targetuserid) {
                continue;
            }
            $supervisors[(int)$direct->id] = ['id' => (int)$direct->id, 'fullname' => fullname($direct)];
        }
        return array_values($supervisors);
    }

    /**
     * Visible interface elements, mirroring output\dashboard and the taskflow shortcodes.
     *
     * @param int $targetuserid
     * @param array $hr
     * @return array<string,bool>
     */
    private function build_visible_ui(int $targetuserid, array $hr): array {
        $context = context_system::instance();
        $visible = [];
        foreach (self::UI_CAPABILITIES as $element => $capability) {
            $visible[$element] = $capability === ''
                ? $targetuserid > 0
                : has_capability($capability, $context, $targetuserid);
        }
        // The admin dashboard also opens for the HR list of the booking extension (dashboard::set_data()).
        $visible['admin_tab'] = $visible['admin_tab'] || !empty($hr['bookingextension']);
        return $visible;
    }

    /**
     * Checklist preview rows.
     *
     * @param array $capabilities
     * @param array $skillcapabilities
     * @param array $supervisorrole
     * @param array $hr
     * @param array $deputyof
     * @param array $visibleui
     * @param string $lang
     * @return array<int,array<string,mixed>>
     */
    private function build_checks(
        array $capabilities,
        array $skillcapabilities,
        array $supervisorrole,
        array $hr,
        array $deputyof,
        array $visibleui,
        string $lang
    ): array {
        $rows = [];
        foreach ($capabilities as $capability) {
            $rows[] = [
                'status' => $capability['granted'] ? 'ok' : 'fail',
                'check' => $capability['capability'],
                'detail' => $capability['name'],
                'url' => '',
            ];
        }
        $grantedskills = array_values(array_filter(
            $skillcapabilities,
            static fn(array $row): bool => $row['granted']
        ));
        $rows[] = [
            'status' => empty($grantedskills) ? 'fail' : 'ok',
            'check' => $this->localized_string('agent_permissions_skill_capabilities', null, $lang),
            'detail' => count($grantedskills) . '/' . count($skillcapabilities) . ' — '
                . implode(', ', array_column($grantedskills, 'capability')),
            'url' => '',
        ];
        $rows[] = [
            'status' => $supervisorrole['assigned'] ? 'ok' : 'warn',
            'check' => $this->localized_string('agent_check_supervisorrole', null, $lang),
            'detail' => $supervisorrole['roleid'] <= 0
                ? $this->localized_string('agent_detail_no_supervisorrole', null, $lang)
                : $supervisorrole['rolename'],
            'url' => '',
        ];
        $rows[] = [
            'status' => $hr['taskflow'] ? 'ok' : 'warn',
            'check' => $this->localized_string('agent_check_hr_taskflow', null, $lang),
            'detail' => '',
            'url' => '',
        ];
        $rows[] = [
            'status' => $hr['bookingextension'] === null ? 'warn' : ($hr['bookingextension'] ? 'ok' : 'fail'),
            'check' => $this->localized_string('agent_check_hr_bookingextension', null, $lang),
            'detail' => $hr['bookingextension'] === null
                ? $this->localized_string('agent_detail_hr_plugin_missing', null, $lang) : '',
            'url' => '',
        ];
        $rows[] = [
            'status' => empty($deputyof) ? 'warn' : 'ok',
            'check' => $this->localized_string('agent_check_deputy_of', null, $lang),
            'detail' => empty($deputyof)
                ? $this->localized_string('agent_detail_deputy_none', null, $lang)
                : implode(', ', array_column($deputyof, 'fullname')),
            'url' => '',
        ];
        foreach ($visibleui as $element => $isvisible) {
            $rows[] = [
                'status' => $isvisible ? 'ok' : 'fail',
                'check' => $this->localized_string('agent_ui_' . $element, null, $lang),
                'detail' => (string)(self::UI_CAPABILITIES[$element] ?? ''),
                'url' => '',
            ];
        }
        return $rows;
    }
}
