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

namespace local_taskflow\local\wizard\taskflow\preview;

/**
 * Side-pane preview 'taskflow_import_report': adapter import diagnosis card.
 *
 * Data contract (filled by local_taskflow.diagnose_import and reusable by trigger_import):
 * adapter (string), last_run {name, time_text, duration_text, found}, counters[] {label, value},
 * errors[] {time_text, event, message}, unmapped[] {function, label}, warnings[] (strings),
 * recommendations[] (strings), adapter_skill (string), links {page, docs[]}.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class taskflow_import_report_preview_renderer extends taskflow_preview_renderer_base {
    /** Preview type. */
    public const PREVIEW_TYPE = taskflow_preview_renderer_factory::TYPE_IMPORT_REPORT;

    /**
     * Build the template context.
     *
     * @param array $data
     * @return array|null Null when no adapter is named.
     */
    protected function build_context(array $data): ?array {
        $adapter = trim((string)($data['adapter'] ?? ''));
        if ($adapter === '') {
            return null;
        }

        $errors = [];
        foreach ((array)($data['errors'] ?? []) as $error) {
            $error = (array)$error;
            $errors[] = [
                'time' => $this->esc($error['time_text'] ?? ''),
                'event' => $this->esc($error['event'] ?? ''),
                'message' => $this->esc($this->truncate((string)($error['message'] ?? ''), 200)),
            ];
        }

        $unmapped = [];
        foreach ((array)($data['unmapped'] ?? []) as $entry) {
            $entry = (array)$entry;
            $unmapped[] = [
                'label' => $this->esc($entry['label'] ?? ($entry['function'] ?? '')),
                'function' => $this->esc($entry['function'] ?? ''),
            ];
        }

        $counters = [];
        foreach ((array)($data['counters'] ?? []) as $counter) {
            $counter = (array)$counter;
            $counters[] = [
                'label' => $this->esc($counter['label'] ?? ''),
                'value' => $this->esc($counter['value'] ?? ''),
            ];
        }

        $warnings = [];
        foreach ((array)($data['warnings'] ?? []) as $warning) {
            $warning = trim((string)$warning);
            if ($warning !== '') {
                $warnings[] = ['text' => $this->esc($warning)];
            }
        }

        $recommendations = [];
        foreach ((array)($data['recommendations'] ?? []) as $recommendation) {
            $recommendation = trim((string)$recommendation);
            if ($recommendation !== '') {
                $recommendations[] = ['text' => $this->esc($recommendation)];
            }
        }

        $lastrun = is_array($data['last_run'] ?? null) ? (array)$data['last_run'] : [];
        $adapterskill = trim((string)($data['adapter_skill'] ?? ''));
        $links = [];
        foreach ((array)(($data['links'] ?? [])['docs'] ?? []) as $url) {
            $url = trim((string)$url);
            if ($url !== '') {
                $links[] = $this->link($url, $this->str('agent_preview_open_docs'));
            }
        }

        return [
            'title' => $this->esc($this->str('agent_preview_import_title', $adapter)),
            'errorcount' => $this->esc($this->str('agent_preview_import_errorcount', count($errors))),
            'errorclass' => 'badge ' . (empty($errors) ? 'bg-light text-dark' : 'bg-danger'),
            'lastrunlabel' => $this->esc($this->str('agent_preview_import_lastrun')),
            'lastrun' => empty($lastrun['found'])
                ? $this->esc($this->str('agent_preview_none'))
                : $this->esc(trim(($lastrun['name'] ?? '') . ' · ' . ($lastrun['time_text'] ?? ''), ' ·')),
            'counterslabel' => $this->esc($this->str('agent_preview_import_counters')),
            'counters' => $counters,
            'hascounters' => !empty($counters),
            'errorslabel' => $this->esc($this->str('agent_import_errors')),
            'errors' => $errors,
            'haserrors' => !empty($errors),
            'unmappedlabel' => $this->esc($this->str('agent_import_unmapped')),
            'unmapped' => $unmapped,
            'hasunmapped' => !empty($unmapped),
            'warnings' => $warnings,
            'haswarnings' => !empty($warnings),
            'recommendationslabel' => $this->esc($this->str('agent_import_recommendations')),
            'recommendations' => $recommendations,
            'hasrecommendations' => !empty($recommendations),
            'adapterskilllabel' => $this->esc($this->str('agent_preview_adapter_skill')),
            'adapterskill' => $this->esc($adapterskill),
            'hasadapterskill' => $adapterskill !== '',
            'links' => $links,
        ];
    }

    /**
     * Id arrays for accumulation (the report itself carries no entity ids).
     *
     * @param array $data
     * @return array<string,int[]>
     */
    protected function default_payload(array $data): array {
        $ids = is_array($data['ids'] ?? null) ? (array)$data['ids'] : [];
        return isset($ids['userids']) && is_array($ids['userids']) ? ['userids' => array_values($ids['userids'])] : [];
    }
}
