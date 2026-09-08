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

use core_component;
use local_taskflow\local\external_adapter\external_api_base;
use local_taskflow\local\wizard\engine\observation_time;
use local_taskflow\local\wizard\engine\skill_risk_class;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\taskflow_settings_catalog;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use local_taskflow\plugininfo\taskflowadapter;
use ReflectionClass;

/**
 * Read-only skill local_taskflow.diagnose_import (implementation plan §2 #16, §1.8).
 *
 * Generic core diagnosis of the data import, independent of the concrete adapter: the active
 * adapter, the last run of its scheduled import task (whatever it is called), the logged error
 * events of local_taskflow and of the adapter component since a given point in time, translator
 * functions without a mapped profile field, unit members without a supervisor, suspended users
 * and units without members. Adapter-specific diagnosis stays in the adapter sub-plugin; the
 * result only names it as 'adapter_skill' (never implemented here).
 *
 * Gated by local/taskflow:editassignment (the HR role that owns the imported assignments). The
 * logged event payloads may carry the import endpoint; every string of a payload is passed
 * through taskflow_settings_catalog masking (secret-like keys, URLs with credentials) before it
 * is reported.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class diagnose_import_skill extends taskflow_skill_base {
    /** Skill name. */
    public const TASK_NAME = 'local_taskflow.diagnose_import';

    /** Native capability: the import diagnosis belongs to the role that manages assignments. */
    public const CAP_EDITASSIGNMENT = 'local/taskflow:editassignment';

    /** Issue code: the given 'since' value is not a date. */
    public const ISSUE_DATE_INVALID = 'TASKFLOW_DATE_INVALID';

    /** Default length of the observed period when 'since' is omitted. */
    public const DEFAULT_PERIOD = 30 * DAYSECS;

    /** Maximum number of error events reported. */
    public const MAX_EVENTS = 25;

    /** Core event class always inspected. */
    public const CORE_ERROR_EVENT = '\\local_taskflow\\event\\upload_error';

    /**
     * Adapter => the adapter sub-plugin skill that continues this diagnosis (plan §1.8).
     *
     * These skills live in the adapter sub-plugins and are only named here, never called.
     *
     * @var array<string,string>
     */
    public const ADAPTER_SKILLS = [
        'tuines' => 'taskflowadapter_tuines.diagnose_dwh_import',
        'ksw' => 'taskflowadapter_ksw.diagnose_org_import',
    ];

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(true, skill_risk_class::R0, [self::CAP_EDITASSIGNMENT]);
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
            'description' => 'Diagnose the taskflow HR data import (the feed of persons, units and supervisors '
                . 'that the active adapter loads into taskflow): which adapter is active, when its scheduled '
                . 'import task last ran, the import errors logged since a given date, adapter fields (translator '
                . 'functions) without a mapped profile field, unit members without a supervisor, user accounts '
                . 'suspended by the import, and organisational units without members. Use it for any question '
                . 'about the state, health or side effects of the import/synchronisation itself (as opposed to '
                . 'one person\'s assignments). Read-only; it never starts an import.',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'Is the taskflow import working?',
                'When did the HR feed last run and were there errors?',
                'Which adapter fields are not mapped to a profile field?',
                'How many users have no supervisor after the import?',
                'Which accounts were suspended by the last import?',
                'Are there organisational units without members after the synchronisation?',
            ],
            'properties' => [
                'since' => [
                    'type' => 'string',
                    'description' => 'Start of the observed period as ISO 8601 date or Unix timestamp '
                        . '(default: 30 days ago).',
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
            'intent' => 'Report the health of the taskflow data import from logged engine state.',
            'input_fields_for_prompt' => ['since (optional)'],
            'anchor_fields' => [],
        ];
    }

    /**
     * Example input for the planner contract.
     *
     * @return array
     */
    public function get_example_input(): array {
        return ['since' => '-7 days'];
    }

    /**
     * Preflight: normalize the period.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array{status:string,prepared_input:array,issues:array}
     */
    protected function run_preflight(array $input, int $contextid, int $userid): array {
        $lang = $this->get_output_language($input);
        $prepared = $input;
        $raw = trim((string)($input['since'] ?? ''));
        if ($raw === '') {
            $prepared['since'] = time() - self::DEFAULT_PERIOD;
            return $this->pass($prepared);
        }
        $timestamp = $this->parse_timestamp($raw);
        if ($timestamp === null) {
            return $this->invalid([
                [
                    'code' => self::ISSUE_DATE_INVALID,
                    'severity' => 'needs_clarification',
                    'field' => 'since',
                    'message' => $this->localized_string('agent_date_invalid', $raw, $lang),
                ],
            ]);
        }
        $prepared['since'] = $timestamp;
        return $this->pass($prepared);
    }

    /**
     * Execute: assemble the import report.
     *
     * @param array $input Prepared input.
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        $lang = $this->get_output_language($input);
        $since = (int)($input['since'] ?? 0);
        if ($since <= 0) {
            $since = $this->parse_timestamp(trim((string)($input['since'] ?? ''))) ?? (time() - self::DEFAULT_PERIOD);
        }
        $adapter = taskflow_settings_catalog::active_adapter();
        $component = 'taskflowadapter_' . $adapter;

        $lastrun = $this->last_run($component);
        $errors = $this->error_events($component, $since);
        $unmapped = $this->unmapped_functions($lang);
        $supervisor = $this->users_without_supervisor();
        $suspended = $this->suspended_members();
        $emptyunits = $this->units_without_members();
        $counters = $this->counters($lang);
        $adapterskill = self::ADAPTER_SKILLS[$adapter] ?? '';

        $warnings = [];
        if ($supervisor['count'] > 0) {
            $warnings[] = $this->localized_string('agent_import_warning_supervisor', (object)[
                'count' => $supervisor['count'],
                'field' => $supervisor['field'],
            ], $lang);
        }
        if ($suspended > 0) {
            $warnings[] = $this->localized_string('agent_import_warning_suspended', $suspended, $lang);
        }
        if ($emptyunits > 0) {
            $warnings[] = $this->localized_string('agent_import_warning_units', $emptyunits, $lang);
        }

        $recommendations = [];
        if (!empty($unmapped)) {
            $recommendations[] = $this->localized_string(
                'agent_import_recommendation_mapping',
                implode(', ', array_column($unmapped, 'label')),
                $lang
            );
        }
        if (!$lastrun['found']) {
            $recommendations[] = $this->localized_string('agent_import_recommendation_norun', $adapter, $lang);
        }
        if (!empty($errors)) {
            $recommendations[] = $this->localized_string('agent_import_recommendation_errors', count($errors), $lang);
        }
        if ($supervisor['count'] > 0) {
            $recommendations[] = $this->localized_string(
                'agent_import_recommendation_supervisor',
                $supervisor['count'],
                $lang
            );
        }
        if ($adapterskill !== '') {
            $recommendations[] = $this->localized_string(
                'agent_import_recommendation_adapter_skill',
                $adapterskill,
                $lang
            );
        }

        $links = $this->links(
            (new \moodle_url('/admin/settings.php', ['section' => 'local_taskflow']))->out(false),
            ['adapters', 'adapters_' . $adapter, 'scheduled_tasks']
        );

        $usermessage = $this->localized_string('agent_diagnose_import_summary', (object)[
            'adapter' => $adapter,
            'errors' => count($errors),
            'unmapped' => count($unmapped),
            'nosupervisor' => $supervisor['count'],
        ], $lang);

        $observation = [$usermessage];
        $observation[] = 'since=' . $this->format_time($since) . ', adapter=' . $adapter;
        $observation[] = $this->localized_string('agent_preview_import_lastrun', null, $lang) . ': '
            . ($lastrun['found'] ? $lastrun['name'] . ' ' . $lastrun['time_text'] : '-');
        foreach ($errors as $error) {
            $observation[] = '  error ' . $error['time_text'] . ' ' . $error['event'] . ' ' . $error['message'];
        }
        $observation[] = $this->localized_string('agent_import_unmapped', null, $lang) . ': '
            . (empty($unmapped) ? '-' : implode(', ', array_column($unmapped, 'function')));
        foreach ($counters as $counter) {
            $observation[] = '  ' . $counter['label'] . ': ' . $counter['value'];
        }
        foreach ($warnings as $warning) {
            $observation[] = '  ! ' . $warning;
        }
        foreach ($recommendations as $recommendation) {
            $observation[] = '  > ' . $recommendation;
        }

        return $this->base_result(self::STATUS_EXECUTED, [
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'observation_full' => implode("\n", $observation),
            'resultid' => 0,
            'adapter' => $adapter,
            'since' => $since,
            'since_text' => $this->format_time($since),
            'last_run' => $lastrun,
            'errors' => $errors,
            'unmapped_functions' => $unmapped,
            'users_without_supervisor' => $supervisor['count'],
            'supervisor_field' => $supervisor['field'],
            'suspended_by_import' => $suspended,
            'units_without_members' => $emptyunits,
            'counters' => $counters,
            'warnings' => $warnings,
            'recommendations' => $recommendations,
            'adapter_skill' => $adapterskill,
            'links' => $links,
            'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, ['Adapter: ' . $adapter]),
            'preview' => [
                'type' => taskflow_preview_renderer_factory::TYPE_IMPORT_REPORT,
                'data' => [
                    'adapter' => $adapter,
                    'last_run' => $lastrun,
                    'counters' => $counters,
                    'errors' => $errors,
                    'unmapped' => $unmapped,
                    'warnings' => $warnings,
                    'recommendations' => $recommendations,
                    'adapter_skill' => $adapterskill,
                    'links' => $links,
                ],
                'payload' => [],
            ],
        ]);
    }

    /**
     * Last run of the adapter's scheduled task(s), read from task_scheduled.
     *
     * The adapter component may register any number of scheduled tasks (tuines:
     * fetch_dwh_data); the most recently run one is reported.
     *
     * @param string $component
     * @return array{found:bool,name:string,classname:string,lastruntime:int,time_text:string,disabled:bool}
     */
    private function last_run(string $component): array {
        global $DB;

        $records = $DB->get_records(
            'task_scheduled',
            ['component' => $component],
            'lastruntime DESC, id ASC',
            'id, classname, lastruntime, nextruntime, disabled'
        );
        $record = $records ? reset($records) : null;
        if (!$record) {
            return ['found' => false, 'name' => '', 'classname' => '', 'lastruntime' => 0,
                'time_text' => '', 'disabled' => false];
        }
        $classname = (string)$record->classname;
        $lastruntime = (int)$record->lastruntime;
        return [
            'found' => $lastruntime > 0,
            'name' => ltrim(substr($classname, (int)strrpos($classname, '\\')), '\\'),
            'classname' => $classname,
            'lastruntime' => $lastruntime,
            'time_text' => $this->format_time($lastruntime),
            'disabled' => !empty($record->disabled),
        ];
    }

    /**
     * Logged error events of local_taskflow and of the adapter component since a point in time.
     *
     * @param string $component
     * @param int $since
     * @return array<int,array{id:int,event:string,time:int,time_text:string,message:string}>
     */
    private function error_events(string $component, int $since): array {
        $classes = [self::CORE_ERROR_EVENT];
        foreach (array_keys(core_component::get_component_classes_in_namespace($component, 'event')) as $class) {
            $classes[] = '\\' . ltrim($class, '\\');
        }
        $classes = array_values(array_unique($classes));

        $reader = $this->log_reader();
        if ($reader === null) {
            return [];
        }

        $events = [];
        try {
            global $DB;
            [$insql, $params] = $DB->get_in_or_equal($classes, SQL_PARAMS_NAMED, 'ev');
            $params['since'] = $since;
            $records = $reader->get_events_select(
                "eventname {$insql} AND timecreated >= :since",
                $params,
                'timecreated DESC',
                0,
                self::MAX_EVENTS
            );
        } catch (\Throwable $e) {
            return [];
        }
        foreach ($records as $record) {
            $eventname = (string)$record->eventname;
            $message = '';
            try {
                $other = $record->other;
                $message = is_array($other)
                    ? (string)json_encode($this->mask_payload($other), JSON_UNESCAPED_UNICODE)
                    : taskflow_settings_catalog::mask_url_credentials((string)$other);
            } catch (\Throwable $e) {
                $message = '';
            }
            // Restored event objects expose no 'id' property; the log row id sits in the data array.
            $events[] = [
                'id' => (int)($record->get_data()['id'] ?? 0),
                'event' => ltrim(substr($eventname, (int)strrpos($eventname, '\\')), '\\'),
                'eventname' => $eventname,
                'time' => (int)$record->timecreated,
                'time_text' => $this->format_time((int)$record->timecreated),
                'message' => $message,
            ];
        }
        return $events;
    }

    /**
     * Event payload without credentials: secret-like keys become "***", URL values lose
     * their userinfo and query values (taskflow_settings_catalog conventions).
     *
     * @param array $payload
     * @return array
     */
    private function mask_payload(array $payload): array {
        $masked = [];
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $masked[$key] = $this->mask_payload($value);
            } else if (is_string($value)) {
                $masked[$key] = taskflow_settings_catalog::masked_value(
                    ['name' => (string)$key],
                    $value
                );
            } else {
                $masked[$key] = $value;
            }
        }
        return $masked;
    }

    /**
     * The first available SQL log reader, or null when logging is off.
     *
     * @return \core\log\sql_reader|null
     */
    private function log_reader(): ?\core\log\sql_reader {
        try {
            $readers = get_log_manager()->get_readers('\\core\\log\\sql_reader');
        } catch (\Throwable $e) {
            return null;
        }
        foreach ($readers as $reader) {
            return $reader;
        }
        return null;
    }

    /**
     * Translator user functions without a mapped profile field in the active adapter.
     *
     * @param string $lang
     * @return array<int,array{function:string,label:string}>
     */
    private function unmapped_functions(string $lang): array {
        $unmapped = [];
        foreach ($this->translator_user_functions() as $function) {
            if ((string)external_api_base::return_shortname_for_functionname($function) !== '') {
                continue;
            }
            $labelkey = str_replace('translator_user_', '', $function);
            $unmapped[] = [
                'function' => $function,
                'label' => get_string_manager()->string_exists($labelkey, 'local_taskflow')
                    ? $this->localized_string($labelkey, null, $lang) : $function,
            ];
        }
        return $unmapped;
    }

    /**
     * All TRANSLATOR_USER_* constants of the adapter plugininfo class.
     *
     * @return string[]
     */
    private function translator_user_functions(): array {
        $functions = [];
        try {
            $constants = (new ReflectionClass(taskflowadapter::class))->getConstants();
        } catch (\Throwable $e) {
            return [];
        }
        foreach ($constants as $name => $value) {
            if (strpos((string)$name, 'TRANSLATOR_USER_') === 0 && is_string($value) && $value !== '') {
                $functions[] = $value;
            }
        }
        return array_values(array_unique($functions));
    }

    /**
     * Unit members whose mapped supervisor profile field is empty.
     *
     * @return array{count:int,field:string}
     */
    private function users_without_supervisor(): array {
        global $DB;

        $shortname = (string)external_api_base::return_shortname_for_functionname(
            taskflowadapter::TRANSLATOR_USER_SUPERVISOR
        );
        if ($shortname === '') {
            $shortname = (string)get_config('local_taskflow', 'supervisor_field');
        }
        if ($shortname === '') {
            return ['count' => 0, 'field' => ''];
        }
        $fieldid = (int)$DB->get_field('user_info_field', 'id', ['shortname' => $shortname], IGNORE_MISSING);
        if ($fieldid <= 0) {
            return ['count' => 0, 'field' => $shortname];
        }

        $sql = "SELECT COUNT(DISTINCT m.userid)
                  FROM {local_taskflow_unit_members} m
                  JOIN {user} u ON u.id = m.userid AND u.deleted = 0
             LEFT JOIN {user_info_data} d ON d.userid = m.userid AND d.fieldid = :fieldid
                 WHERE d.id IS NULL OR " . $DB->sql_compare_text('d.data', 255) . " = :empty";
        $count = (int)$DB->count_records_sql($sql, ['fieldid' => $fieldid, 'empty' => '']);
        return ['count' => $count, 'field' => $shortname];
    }

    /**
     * Suspended user accounts among the unit members.
     *
     * @return int
     */
    private function suspended_members(): int {
        global $DB;

        return (int)$DB->count_records_sql(
            "SELECT COUNT(DISTINCT m.userid)
               FROM {local_taskflow_unit_members} m
               JOIN {user} u ON u.id = m.userid
              WHERE u.deleted = 0 AND u.suspended = 1"
        );
    }

    /**
     * Organisational units without a single member.
     *
     * @return int
     */
    private function units_without_members(): int {
        try {
            $units = (array)\local_taskflow\local\units\organisational_units_factory::instance()->get_units();
        } catch (\Throwable $e) {
            return 0;
        }
        $empty = 0;
        foreach (array_keys($units) as $unitid) {
            try {
                $unit = \local_taskflow\local\units\organisational_unit_factory::instance((int)$unitid);
                if (is_object($unit) && method_exists($unit, 'count_members') && (int)$unit->count_members() === 0) {
                    $empty++;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }
        return $empty;
    }

    /**
     * Volume counters of the imported data.
     *
     * @param string $lang
     * @return array<int,array{label:string,value:int}>
     */
    private function counters(string $lang): array {
        global $DB;

        return [
            [
                'label' => $this->localized_string('agent_import_counter_members', null, $lang),
                'value' => (int)$DB->count_records('local_taskflow_unit_members'),
            ],
            [
                'label' => $this->localized_string('agent_import_counter_users', null, $lang),
                'value' => (int)$DB->count_records_sql(
                    'SELECT COUNT(DISTINCT userid) FROM {local_taskflow_unit_members}'
                ),
            ],
            [
                'label' => $this->localized_string('agent_import_counter_assignments', null, $lang),
                'value' => (int)$DB->count_records('local_taskflow_assignment'),
            ],
        ];
    }

    /**
     * Parse a Unix timestamp or a date string.
     *
     * @param string $value
     * @return int|null
     */
    private function parse_timestamp(string $value): ?int {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{9,11}$/', $value)) {
            return (int)$value;
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? null : $timestamp;
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
        return userdate($timestamp, get_string('strftimedatetime', 'langconfig'));
    }
}
