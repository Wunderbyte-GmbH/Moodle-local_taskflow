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

use local_taskflow\local\external_adapter\external_api_repository;
use local_taskflow\local\wizard\engine\queue_identity_provider_interface;
use local_taskflow\local\wizard\engine\skill_risk_class;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\taskflow_input_normalizer;
use local_taskflow\local\wizard\taskflow\taskflow_settings_catalog;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;

/**
 * Mutating skill local_taskflow.trigger_import (implementation plan §2 #33, §1.8, §6.5).
 *
 * Runs the JSON upload path of the core adapters (standard / ksw): exactly what
 * local_taskflow\form\uploaduser::process_dynamic_submission() does, namely
 * external_api_repository::create($json)->process_incoming_data(). Nothing about the import
 * itself is re-implemented here.
 *
 * The DWH import of the tuines adapter is NOT part of this skill: when tuines is the active
 * adapter, a payload is refused and the result names the sub-plugin skill
 * taskflowadapter_tuines.trigger_dwh_import instead (§1.8).
 *
 * Safety comes from the confirm gate and the capability, not from hiding the skill (§6.5):
 * the skill stays MCP-exposed, requires moodle/site:config and, for a real import, an explicit
 * override token, because the import can suspend users that the file no longer contains. The
 * preflight therefore always states how many persons the payload contains versus how many user
 * accounts exist today. Without a payload — or with dryrun (the default) — only the read-only
 * checks of local_taskflow.diagnose_import run and nothing is written.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class trigger_import_skill extends taskflow_skill_base implements queue_identity_provider_interface {
    /** Skill name. */
    public const TASK_NAME = 'local_taskflow.trigger_import';

    /** Native capability: running an import is an administrative operation. */
    public const CAP_SITECONFIG = 'moodle/site:config';

    /** Override token that releases a real import. */
    public const OVERRIDE_SUSPEND = 'CONFIRM_IMPORT_MAY_SUSPEND_USERS';

    /** Adapters whose data arrive as a JSON upload and are therefore handled here (§1.8). */
    public const PAYLOAD_ADAPTERS = ['standard', 'ksw'];

    /**
     * Adapter => the sub-plugin skill that imports for it instead of this skill (§1.8).
     *
     * @var array<string,string>
     */
    public const ADAPTER_SKILLS = [
        'tuines' => 'taskflowadapter_tuines.trigger_dwh_import',
    ];

    /** Issue code: the active adapter does not use the JSON upload path. */
    public const ISSUE_ADAPTER_UNSUPPORTED = 'TASKFLOW_IMPORT_ADAPTER_UNSUPPORTED';

    /** Issue code: the payload is not valid JSON or contains no person. */
    public const ISSUE_PAYLOAD_INVALID = 'TASKFLOW_IMPORT_PAYLOAD_INVALID';

    /** Issue code: a real import needs the override token. */
    public const ISSUE_OVERRIDE_REQUIRED = 'TASKFLOW_IMPORT_OVERRIDE_REQUIRED';

    /** Issue code: the import threw. */
    public const ISSUE_IMPORT_FAILED = 'TASKFLOW_IMPORT_FAILED';

    /** Issue code: error events were logged while the import ran. */
    public const ISSUE_IMPORT_ERRORS_LOGGED = 'TASKFLOW_IMPORT_ERRORS_LOGGED';

    /**
     * Constructor: mutating, R3, admin capability.
     */
    public function __construct() {
        parent::__construct(false, skill_risk_class::R3, [self::CAP_SITECONFIG]);
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
            'description' => 'Run the taskflow data import from a JSON payload (the upload path of the standard '
                . 'and ksw adapters) or, with dryrun, only check whether an import would work. A real import can '
                . 'create, update and suspend user accounts, so it needs a payload and the override token '
                . self::OVERRIDE_SUSPEND . '. When the tuines adapter is active, use the sub-plugin skill '
                . self::ADAPTER_SKILLS['tuines'] . ' instead of a payload.',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'Check whether the taskflow import is working',
                'Import this user list: [{"userID": 1, ...}]',
                'Run a dry run of the taskflow import',
            ],
            'properties' => [
                'payload' => [
                    'type' => 'string',
                    'description' => 'The import file as a JSON string (array of person objects) for the '
                        . 'standard / ksw adapter. Without a payload only the read-only checks run.',
                    'required' => false,
                ],
                'dryrun' => [
                    'type' => 'boolean',
                    'description' => 'True (default) only runs the read-only import checks and writes nothing. '
                        . 'Set it to false together with a payload to really import.',
                    'required' => false,
                ],
                'override' => [
                    'type' => 'array',
                    'description' => 'Override tokens for confirmed exceptions; a real import requires '
                        . self::OVERRIDE_SUSPEND . ' because it can suspend users missing from the file.',
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
            'intent' => 'Check the taskflow import or run it from a JSON payload.',
            'input_fields_for_prompt' => ['payload (optional)', 'dryrun', 'override'],
            'anchor_fields' => [],
        ];
    }

    /**
     * Example input for the planner contract.
     *
     * @return array
     */
    public function get_example_input(): array {
        return ['dryrun' => true];
    }

    /**
     * Queue business identity for deduplication.
     *
     * @param array $input
     * @return array<string,mixed>
     */
    public function build_queue_business_identity(array $input): array {
        $payload = (string)($input['payload'] ?? '');
        return [
            'task_family' => self::TASK_NAME,
            'target' => ['adapter' => taskflow_settings_catalog::active_adapter()],
            'change' => [
                'dryrun' => $this->is_dryrun($input) ? 1 : 0,
                'payload_hash' => $payload === '' ? '' : sha1($payload),
            ],
        ];
    }

    /**
     * Preflight: adapter gate, payload validation, counters and the override token.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array{status:string,prepared_input:array,issues:array}
     */
    protected function run_preflight(array $input, int $contextid, int $userid): array {
        $lang = $this->get_output_language($input);
        $adapter = taskflow_settings_catalog::active_adapter();
        $payload = trim((string)($input['payload'] ?? ''));
        $prepared = $input;
        $prepared['payload'] = $payload;

        if ($payload === '' || $this->is_dryrun($input)) {
            $prepared['dryrun'] = true;
            return $this->pass($prepared);
        }

        if (!in_array($adapter, self::PAYLOAD_ADAPTERS, true)) {
            return $this->invalid([[
                'code' => self::ISSUE_ADAPTER_UNSUPPORTED,
                'severity' => 'needs_clarification',
                'field' => 'payload',
                'message' => $this->localized_string('agent_trigger_import_adapter_unsupported', (object)[
                    'adapter' => $adapter,
                    'skill' => (string)(self::ADAPTER_SKILLS[$adapter] ?? ''),
                ], $lang),
                'adapter_skill' => (string)(self::ADAPTER_SKILLS[$adapter] ?? ''),
            ]]);
        }

        $filecount = $this->count_persons($payload);
        if ($filecount === null) {
            return $this->invalid([[
                'code' => self::ISSUE_PAYLOAD_INVALID,
                'severity' => 'needs_clarification',
                'field' => 'payload',
                'message' => $this->localized_string(
                    'agent_trigger_import_payload_invalid',
                    json_last_error_msg(),
                    $lang
                ),
            ]]);
        }

        $prepared['dryrun'] = false;
        $prepared['filecount'] = $filecount;
        $counts = $this->counts();
        $prepared['override'] = $this->override_tokens($input);

        if (!in_array(self::OVERRIDE_SUSPEND, $prepared['override'], true)) {
            return $this->confirmable($prepared, [[
                'code' => self::ISSUE_OVERRIDE_REQUIRED,
                'severity' => 'needs_confirmation',
                'field' => 'override',
                'user_question' => $this->localized_string('agent_trigger_import_confirm', (object)[
                    'adapter' => $adapter,
                    'filecount' => $filecount,
                    'users' => $counts['users'],
                    'token' => self::OVERRIDE_SUSPEND,
                ], $lang),
                'remedy_options' => [self::OVERRIDE_SUSPEND],
            ]]);
        }

        return $this->pass($prepared);
    }

    /**
     * Tier-3 confirmation preview: adapter, payload size, current counters, risk.
     *
     * @param array $input Prepared input.
     * @return array{title:string,summary:string,rows:array}|null
     */
    public function describe_proposed_action(array $input): ?array {
        $lang = $this->get_output_language($input);
        $adapter = taskflow_settings_catalog::active_adapter();
        $payload = trim((string)($input['payload'] ?? ''));
        $dryrun = $payload === '' || $this->is_dryrun($input);
        $counts = $this->counts();

        $rows = [
            [
                'label' => $this->localized_string('agent_preview_backend', null, $lang),
                'value' => $adapter,
            ],
            [
                'label' => $this->localized_string('agent_trigger_import_row_mode', null, $lang),
                'value' => $this->localized_string(
                    $dryrun ? 'agent_trigger_import_mode_dryrun' : 'agent_trigger_import_mode_import',
                    null,
                    $lang
                ),
            ],
        ];

        if (!$dryrun) {
            $filecount = $this->count_persons($payload);
            $rows[] = [
                'label' => $this->localized_string('agent_trigger_import_row_counts', null, $lang),
                'value' => $this->localized_string('agent_trigger_import_counts', (object)[
                    'filecount' => $filecount === null ? 0 : $filecount,
                    'users' => $counts['users'],
                    'suspended' => $counts['suspended'],
                    'members' => $counts['members'],
                ], $lang),
            ];
            $rows[] = [
                'label' => $this->localized_string('agent_preview_warning', null, $lang),
                'value' => $this->localized_string('agent_trigger_import_warning_suspend', self::OVERRIDE_SUSPEND, $lang),
            ];
        }

        return [
            'title' => $this->localized_string(
                $dryrun ? 'agent_trigger_import_title_dryrun' : 'agent_trigger_import_title_import',
                $adapter,
                $lang
            ),
            'summary' => $this->localized_string(
                $dryrun ? 'agent_trigger_import_summary_dryrun' : 'agent_trigger_import_summary_import',
                (object)['adapter' => $adapter],
                $lang
            ),
            'rows' => $rows,
        ];
    }

    /**
     * Execute: dry run (read-only checks) or the real JSON upload import with before/after counts.
     *
     * @param array $input Prepared input.
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        $lang = $this->get_output_language($input);
        $adapter = taskflow_settings_catalog::active_adapter();
        $payload = trim((string)($input['payload'] ?? ''));
        $debug = $this->build_task_debug_message(self::TASK_NAME, array_diff_key($input, ['payload' => true]), [
            'Adapter: ' . $adapter,
            'Payload bytes: ' . strlen($payload),
        ]);

        if ($payload === '' || $this->is_dryrun($input)) {
            return $this->dry_run_result($contextid, $userid, $lang, $payload, $debug);
        }

        if (!in_array($adapter, self::PAYLOAD_ADAPTERS, true)) {
            return $this->error_result(
                self::ISSUE_ADAPTER_UNSUPPORTED,
                $this->localized_string('agent_trigger_import_adapter_unsupported', (object)[
                    'adapter' => $adapter,
                    'skill' => (string)(self::ADAPTER_SKILLS[$adapter] ?? ''),
                ], $lang),
                ['adapter_skill' => (string)(self::ADAPTER_SKILLS[$adapter] ?? ''), 'debugmessage' => $debug]
            );
        }
        $filecount = $this->count_persons($payload);
        if ($filecount === null) {
            return $this->error_result(
                self::ISSUE_PAYLOAD_INVALID,
                $this->localized_string('agent_trigger_import_payload_invalid', json_last_error_msg(), $lang),
                ['debugmessage' => $debug]
            );
        }
        if (!in_array(self::OVERRIDE_SUSPEND, $this->override_tokens($input), true)) {
            return $this->error_result(
                self::ISSUE_OVERRIDE_REQUIRED,
                $this->localized_string('agent_trigger_import_override_required', self::OVERRIDE_SUSPEND, $lang),
                ['debugmessage' => $debug]
            );
        }

        $before = $this->counts();
        $started = time();
        try {
            external_api_repository::create($payload)->process_incoming_data();
        } catch (\Throwable $e) {
            return $this->error_result(
                self::ISSUE_IMPORT_FAILED,
                $this->localized_string('agent_trigger_import_failed', $e->getMessage(), $lang),
                ['debugmessage' => $debug, 'counters_before' => $before]
            );
        }
        $after = $this->counts();

        // Error events written while the import ran: the read-only diagnosis reads the very
        // same logstore, so the check is not duplicated here.
        $diagnosis = $this->diagnosis($contextid, $userid, $lang, $started);
        $errors = is_array($diagnosis['errors'] ?? null) ? (array)$diagnosis['errors'] : [];

        $counters = [
            $this->counter_row('agent_trigger_import_counter_users', $before['users'], $after['users'], $lang),
            $this->counter_row('agent_trigger_import_counter_members', $before['members'], $after['members'], $lang),
            $this->counter_row(
                'agent_trigger_import_counter_suspended',
                $before['suspended'],
                $after['suspended'],
                $lang
            ),
        ];

        $usermessage = $this->localized_string('agent_trigger_import_result', (object)[
            'adapter' => $adapter,
            'filecount' => $filecount,
            'users' => $after['users'] - $before['users'],
            'suspended' => $after['suspended'] - $before['suspended'],
            'errors' => count($errors),
        ], $lang);

        $observation = [
            $usermessage,
            'adapter=' . $adapter . ', persons_in_file=' . $filecount . ', started=' . $started,
        ];
        foreach ($counters as $counter) {
            $observation[] = '  ' . $counter['label'] . ': ' . $counter['value'];
        }
        foreach ($errors as $error) {
            $observation[] = '  error ' . (string)($error['time_text'] ?? '') . ' ' . (string)($error['event'] ?? '')
                . ' ' . (string)($error['message'] ?? '');
        }

        $links = $this->links(
            (new \moodle_url('/local/taskflow/view.php'))->out(false),
            ['adapters', 'adapters_' . $adapter, 'scheduled_tasks']
        );
        $preview = [
            'type' => taskflow_preview_renderer_factory::TYPE_IMPORT_REPORT,
            'data' => [
                'adapter' => $adapter,
                'last_run' => [
                    'found' => true,
                    'name' => self::TASK_NAME,
                    'time_text' => userdate($started, get_string('strftimedatetime', 'langconfig')),
                ],
                'counters' => $counters,
                'errors' => $errors,
                'unmapped' => is_array($diagnosis['unmapped_functions'] ?? null)
                    ? (array)$diagnosis['unmapped_functions'] : [],
                'warnings' => is_array($diagnosis['warnings'] ?? null) ? (array)$diagnosis['warnings'] : [],
                'recommendations' => is_array($diagnosis['recommendations'] ?? null)
                    ? (array)$diagnosis['recommendations'] : [],
                'adapter_skill' => (string)($diagnosis['adapter_skill'] ?? ''),
                'links' => $links,
            ],
            'payload' => [],
        ];

        $fields = [
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'observation_full' => implode("\n", $observation),
            'resultid' => 0,
            'dryrun' => false,
            'adapter' => $adapter,
            'filecount' => $filecount,
            'started' => $started,
            'counters_before' => $before,
            'counters_after' => $after,
            'counters' => $counters,
            'errors' => $errors,
            'links' => $links,
            'outputlang' => $lang,
            'debugmessage' => $debug,
            'preview' => $preview,
        ];

        if (!empty($errors)) {
            // Faithful reporting: the import ran but the logstore recorded failures.
            return $this->base_result(self::STATUS_ERROR, $fields + [
                'issue_codes' => [self::ISSUE_IMPORT_ERRORS_LOGGED],
            ]);
        }
        return $this->base_result(self::STATUS_EXECUTED, $fields);
    }

    /**
     * Dry run: the read-only checks of local_taskflow.diagnose_import, nothing is written.
     *
     * @param int $contextid
     * @param int $userid
     * @param string $lang
     * @param string $payload
     * @param string $debug
     * @return array
     */
    private function dry_run_result(
        int $contextid,
        int $userid,
        string $lang,
        string $payload,
        string $debug
    ): array {
        $adapter = taskflow_settings_catalog::active_adapter();
        $diagnosis = $this->diagnosis($contextid, $userid, $lang, 0);
        $counts = $this->counts();
        $filecount = $payload === '' ? null : $this->count_persons($payload);

        $counters = is_array($diagnosis['counters'] ?? null) ? (array)$diagnosis['counters'] : [];
        $counters[] = [
            'label' => $this->localized_string('agent_trigger_import_counter_accounts', null, $lang),
            'value' => $counts['users'],
        ];
        $counters[] = [
            'label' => $this->localized_string('agent_trigger_import_counter_suspended', null, $lang),
            'value' => $counts['suspended'],
        ];
        if ($filecount !== null) {
            $counters[] = [
                'label' => $this->localized_string('agent_trigger_import_counter_file', null, $lang),
                'value' => $filecount,
            ];
        }

        $warnings = is_array($diagnosis['warnings'] ?? null) ? (array)$diagnosis['warnings'] : [];
        if (!in_array($adapter, self::PAYLOAD_ADAPTERS, true)) {
            $warnings[] = $this->localized_string('agent_trigger_import_adapter_unsupported', (object)[
                'adapter' => $adapter,
                'skill' => (string)(self::ADAPTER_SKILLS[$adapter] ?? ''),
            ], $lang);
        }
        if ($payload !== '' && $filecount === null) {
            $warnings[] = $this->localized_string(
                'agent_trigger_import_payload_invalid',
                json_last_error_msg(),
                $lang
            );
        }

        $usermessage = $this->localized_string('agent_trigger_import_dryrun_result', (object)[
            'adapter' => $adapter,
            'errors' => count(is_array($diagnosis['errors'] ?? null) ? (array)$diagnosis['errors'] : []),
            'users' => $counts['users'],
            'filecount' => $filecount === null ? 0 : $filecount,
        ], $lang);

        $observation = [$usermessage, (string)($diagnosis['observation_full'] ?? '')];
        foreach ($warnings as $warning) {
            $observation[] = '  ! ' . (string)$warning;
        }

        $links = is_array($diagnosis['links'] ?? null)
            ? (array)$diagnosis['links']
            : $this->links(null, ['adapters']);

        return $this->base_result(self::STATUS_EXECUTED, [
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'observation_full' => implode("\n", array_filter($observation, 'strlen')),
            'resultid' => 0,
            'dryrun' => true,
            'adapter' => $adapter,
            'filecount' => $filecount === null ? 0 : $filecount,
            'counters_before' => $counts,
            'counters_after' => $counts,
            'counters' => $counters,
            'errors' => is_array($diagnosis['errors'] ?? null) ? (array)$diagnosis['errors'] : [],
            'warnings' => $warnings,
            'recommendations' => is_array($diagnosis['recommendations'] ?? null)
                ? (array)$diagnosis['recommendations'] : [],
            'adapter_skill' => (string)($diagnosis['adapter_skill'] ?? ''),
            'links' => $links,
            'outputlang' => $lang,
            'debugmessage' => $debug,
            'preview' => [
                'type' => taskflow_preview_renderer_factory::TYPE_IMPORT_REPORT,
                'data' => [
                    'adapter' => $adapter,
                    'last_run' => is_array($diagnosis['last_run'] ?? null) ? (array)$diagnosis['last_run'] : [],
                    'counters' => $counters,
                    'errors' => is_array($diagnosis['errors'] ?? null) ? (array)$diagnosis['errors'] : [],
                    'unmapped' => is_array($diagnosis['unmapped_functions'] ?? null)
                        ? (array)$diagnosis['unmapped_functions'] : [],
                    'warnings' => $warnings,
                    'recommendations' => is_array($diagnosis['recommendations'] ?? null)
                        ? (array)$diagnosis['recommendations'] : [],
                    'adapter_skill' => (string)($diagnosis['adapter_skill'] ?? ''),
                    'links' => $links,
                ],
                'payload' => [],
            ],
        ]);
    }

    /**
     * Read-only import diagnosis (skill #16), reused instead of repeating its checks.
     *
     * @param int $contextid
     * @param int $userid
     * @param string $lang
     * @param int $since Start of the observed period (0 = the skill's default period).
     * @return array Result of diagnose_import_skill::execute(), [] on failure.
     */
    private function diagnosis(int $contextid, int $userid, string $lang, int $since): array {
        $input = ['outputlang' => $lang];
        if ($since > 0) {
            $input['since'] = $since;
        }
        try {
            return (new diagnose_import_skill())->execute($input, $contextid, $userid);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Whether the input asks for a dry run (default true).
     *
     * @param array $input
     * @return bool
     */
    private function is_dryrun(array $input): bool {
        $value = taskflow_input_normalizer::to_bool($input['dryrun'] ?? null);
        return $value === null ? true : (bool)$value;
    }

    /**
     * Override tokens of the input, upper-cased and trimmed.
     *
     * @param array $input
     * @return string[]
     */
    private function override_tokens(array $input): array {
        $tokens = [];
        foreach ((array)($input['override'] ?? []) as $token) {
            $token = \core_text::strtoupper(trim((string)$token));
            if ($token !== '') {
                $tokens[] = $token;
            }
        }
        return array_values(array_unique($tokens));
    }

    /**
     * Number of persons in a JSON payload, or null when the payload is not usable.
     *
     * @param string $payload
     * @return int|null
     */
    private function count_persons(string $payload): ?int {
        $payload = trim($payload);
        if ($payload === '') {
            return null;
        }
        $decoded = json_decode($payload, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return null;
        }
        return count($decoded);
    }

    /**
     * Volume counters used before and after the import.
     *
     * @return array{users:int,members:int,suspended:int}
     */
    private function counts(): array {
        global $DB;

        return [
            'users' => (int)$DB->count_records('user', ['deleted' => 0]),
            'members' => (int)$DB->count_records('local_taskflow_unit_members'),
            'suspended' => (int)$DB->count_records_select('user', 'deleted = 0 AND suspended = 1'),
        ];
    }

    /**
     * One before/after counter row for the import report preview.
     *
     * @param string $key Language key of the label.
     * @param int $before
     * @param int $after
     * @param string $lang
     * @return array{label:string,value:string}
     */
    private function counter_row(string $key, int $before, int $after, string $lang): array {
        $delta = $after - $before;
        return [
            'label' => $this->localized_string($key, null, $lang),
            'value' => $before . ' → ' . $after . ' (' . ($delta > 0 ? '+' : '') . $delta . ')',
        ];
    }
}
