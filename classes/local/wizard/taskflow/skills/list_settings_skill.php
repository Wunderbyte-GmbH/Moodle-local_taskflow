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
use local_taskflow\local\wizard\engine\skill_risk_class;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\taskflow_input_normalizer;
use local_taskflow\local\wizard\taskflow\taskflow_settings_catalog;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use moodle_url;

/**
 * Skill local_taskflow.list_settings — READ-ONLY (R0).
 *
 * Lists every local_taskflow admin setting (and, optionally, the settings of the active
 * adapter subplugin) with label, description, type and current value, based on the static
 * taskflow_settings_catalog. Admin-only: the native capability moodle/site:config is
 * enforced by the engine and re-checked here.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class list_settings_skill extends taskflow_skill_base {
    /** Skill name constant. */
    public const TASK_NAME = 'local_taskflow.list_settings';

    /** Native capability required to read the site configuration. */
    public const NATIVE_CAPABILITY = 'moodle/site:config';

    /** Issue code: acting user lacks the native capability. */
    public const ISSUE_NO_NATIVE_CAPABILITY = 'NO_NATIVE_CAPABILITY';

    /**
     * Constructor — read-only, R0, admin-only.
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
            'description' => 'List the admin settings of the taskflow plugin (local_taskflow) and of the active'
                . ' import adapter: name, label, description, type and current value. Read-only — use it for'
                . ' questions like "how is taskflow configured", "which adapter is active", "is the prolonged'
                . ' state enabled" or "what can I configure for taskflow". It never changes a setting.',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'How is taskflow configured?',
                'Which import adapter is active for taskflow?',
                'Show me the taskflow settings about self extension',
                'Is the prolonged state enabled in the taskflow adapter?',
            ],
            'properties' => [
                'filter' => [
                    'type' => 'string',
                    'description' => 'Optional substring that the setting name or label must contain'
                        . ' (case-insensitive). Leave empty to list every setting.',
                    'required' => false,
                ],
                'includeadapter' => [
                    'type' => 'boolean',
                    'description' => 'Whether to include the settings of the active adapter subplugin'
                        . ' (taskflowadapter_<adapter>). Default true.',
                    'required' => false,
                ],
            ],
        ];
    }

    /**
     * Prompt metadata overrides.
     *
     * @return array<string,mixed>
     */
    protected function prompt_meta(): array {
        return [
            'intent' => 'Read the taskflow plugin and adapter configuration.',
            'input_fields_for_prompt' => [],
            'anchor_fields' => [],
        ];
    }

    /**
     * Preflight: normalize input and enforce the native capability.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    protected function run_preflight(array $input, int $contextid, int $userid): array {
        $lang = $this->get_output_language($input);
        if (!has_capability(self::NATIVE_CAPABILITY, context_system::instance(), $userid)) {
            return $this->invalid([[
                'code' => self::ISSUE_NO_NATIVE_CAPABILITY,
                'severity' => 'needs_clarification',
                'message' => get_string('nopermissions', 'error', self::NATIVE_CAPABILITY),
            ]]);
        }
        return $this->pass($this->normalize_input($input, $lang));
    }

    /**
     * Execute: return the settings catalog with current values.
     *
     * R0 skills may run without the preflight gate, so the capability is enforced here too.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        $lang = $this->get_output_language($input);
        $input = $this->normalize_input($input, $lang);

        if (!has_capability(self::NATIVE_CAPABILITY, context_system::instance(), $userid)) {
            return $this->error_result(
                self::ISSUE_NO_NATIVE_CAPABILITY,
                get_string('nopermissions', 'error', self::NATIVE_CAPABILITY),
                ['debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input)]
            );
        }

        $adapter = taskflow_settings_catalog::active_adapter();
        $includeadapter = (bool)$input['includeadapter'];
        $filter = (string)$input['filter'];

        $settings = [];
        foreach (taskflow_settings_catalog::all($includeadapter ? [$adapter] : []) as $entry) {
            $label = taskflow_settings_catalog::label($entry, $lang);
            if ($filter !== '' && !$this->matches_filter($entry, $label, $filter)) {
                continue;
            }
            $settings[] = [
                'name' => (string)$entry['name'],
                'component' => (string)$entry['component'],
                'group' => (string)($entry['group'] ?? ''),
                'label' => $label,
                'description' => taskflow_settings_catalog::description($entry, $lang),
                'type' => (string)($entry['type'] ?? ''),
                'default' => $entry['default'] ?? null,
                'current_value' => taskflow_settings_catalog::current_value($entry),
            ];
        }

        $links = $this->links(
            $this->settings_page_url(),
            array_merge(['settings'], $includeadapter ? ['adapters_' . $adapter, 'adapters'] : [])
        );

        $summaryparams = (object)['count' => count($settings), 'adapter' => $adapter, 'filter' => $filter];
        $usermessage = empty($settings) && $filter !== ''
            ? $this->localized_string('agent_list_settings_nomatch', $filter, $lang)
            : $this->localized_string('agent_list_settings_summary', $summaryparams, $lang);

        return $this->base_result(self::STATUS_EXECUTED, [
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'observation_full' => $this->build_observation($settings, $adapter, $filter, $links),
            'settings' => $settings,
            'adapter' => $adapter,
            'includeadapter' => $includeadapter,
            'filter' => $filter,
            'count' => count($settings),
            'links' => $links,
            'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, [
                'adapter: ' . $adapter,
                'settings returned: ' . count($settings),
            ]),
            'preview' => [
                'type' => taskflow_preview_renderer_factory::TYPE_CATALOG,
                'data' => $this->build_preview_data($settings, $adapter, $filter, $links, $lang),
                'payload' => [],
            ],
        ]);
    }

    /**
     * Normalized input: trimmed filter, boolean includeadapter (default true), outputlang.
     *
     * @param array $input
     * @param string $lang
     * @return array{filter:string,includeadapter:bool,outputlang:string}
     */
    private function normalize_input(array $input, string $lang): array {
        $includeadapter = taskflow_input_normalizer::to_bool($input['includeadapter'] ?? null);
        return [
            'filter' => trim((string)($input['filter'] ?? '')),
            'includeadapter' => $includeadapter ?? true,
            'outputlang' => $lang,
        ];
    }

    /**
     * Whether a catalog entry matches the user-supplied substring filter (name or label).
     *
     * @param array $entry
     * @param string $label
     * @param string $filter
     * @return bool
     */
    private function matches_filter(array $entry, string $label, string $filter): bool {
        $needle = \core_text::strtolower($filter);
        foreach ([(string)$entry['name'], $label] as $haystack) {
            if ($haystack !== '' && \core_text::strpos(\core_text::strtolower($haystack), $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * URL of the local_taskflow admin settings page.
     *
     * @return string
     */
    private function settings_page_url(): string {
        return (new moodle_url('/admin/settings.php', ['section' => 'local_taskflow_settings']))->out(false);
    }

    /**
     * Plain-text representation of a current value for observations and previews.
     *
     * @param mixed $value
     * @return string
     */
    private function value_to_text($value): string {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            return empty($value) ? '' : implode(', ', array_map('strval', $value));
        }
        if ($value === null) {
            return '';
        }
        return trim((string)$value);
    }

    /**
     * Observation text for the synchronizer: one line per setting.
     *
     * @param array $settings
     * @param string $adapter
     * @param string $filter
     * @param array $links
     * @return string
     */
    private function build_observation(array $settings, string $adapter, string $filter, array $links): string {
        $lines = [];
        $lines[] = count($settings) . ' taskflow setting(s); active adapter: ' . $adapter
            . ($filter !== '' ? '; filter: "' . $filter . '"' : '') . '.';
        foreach ($settings as $setting) {
            $value = $this->value_to_text($setting['current_value']);
            $lines[] = '- ' . $setting['component'] . '/' . $setting['name']
                . ' (' . $setting['label'] . ', ' . $setting['type'] . '): '
                . ($value === '' ? '(empty)' : $value);
        }
        if (!empty($links['page'])) {
            $lines[] = 'Settings page: ' . $links['page'];
        }
        return implode("\n", $lines);
    }

    /**
     * Data for the taskflow_catalog preview: one section per component/group.
     *
     * @param array $settings
     * @param string $adapter
     * @param string $filter
     * @param array $links
     * @param string $lang
     * @return array
     */
    private function build_preview_data(array $settings, string $adapter, string $filter, array $links, string $lang): array {
        $sections = [];
        foreach ($settings as $setting) {
            $key = $setting['component'] . ($setting['group'] !== '' ? ' · ' . $setting['group'] : '');
            if (!isset($sections[$key])) {
                $sections[$key] = ['title' => $key, 'open' => $setting['component'] === 'local_taskflow', 'rows' => []];
            }
            $value = $setting['current_value'];
            $sections[$key]['rows'][] = [
                'label' => $setting['name'],
                'code' => true,
                'value' => is_bool($value) ? ($value ? '✓' : '–') : $this->value_to_text($value),
                'hint' => $setting['description'] !== '' ? $setting['description'] : $setting['label'],
            ];
        }

        $previewlinks = [];
        if (!empty($links['page'])) {
            $previewlinks[] = [
                'url' => $links['page'],
                'label' => $this->localized_string('agent_preview_open_settings', null, $lang),
            ];
        }
        foreach ((array)($links['docs'] ?? []) as $url) {
            $previewlinks[] = ['url' => $url, 'label' => $this->localized_string('agent_preview_open_docs', null, $lang)];
        }

        return [
            'title' => $this->localized_string('agent_preview_catalog_settings', null, $lang)
                . ($filter !== '' ? ' · „' . $filter . '"' : ''),
            'badge' => $adapter,
            'count' => count($settings),
            'sections' => array_values($sections),
            'links' => $previewlinks,
            'empty' => $filter !== '' ? $this->localized_string('agent_list_settings_nomatch', $filter, $lang) : '',
        ];
    }
}
