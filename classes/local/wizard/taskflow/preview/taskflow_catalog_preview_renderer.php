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
 * Side-pane preview 'taskflow_catalog': grouped lookup table (settings, rule properties).
 *
 * Data contract (raw, unescaped — the mustache template escapes every scalar; only the
 * pre-escaped base helpers status_badge()/link() and the empty-state markup are emitted raw):
 * - title (string), badge (string, optional), count (int, optional), empty (string, optional)
 * - sections[]: title, open (bool), rows[]: label, code (bool), value, hint, badge, badgeclass,
 *   statusid (int, optional → coloured status badge from the status type class)
 * - links[]: url, label
 *
 * A catalog is static reference data, so the accumulation payload carries no ids.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class taskflow_catalog_preview_renderer extends taskflow_preview_renderer_base {
    /** Preview type. */
    public const PREVIEW_TYPE = taskflow_preview_renderer_factory::TYPE_CATALOG;

    /** Badge class used when a row badge names none. */
    private const DEFAULT_BADGE_CLASS = 'bg-light text-dark';

    /**
     * Build the template context.
     *
     * @param array $data
     * @return array|null
     */
    protected function build_context(array $data): ?array {
        $sections = [];
        foreach ((array)($data['sections'] ?? []) as $section) {
            if (!is_array($section)) {
                continue;
            }
            $rows = [];
            foreach ((array)($section['rows'] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $rows[] = $this->build_row($row);
            }
            if (empty($rows)) {
                continue;
            }
            $sections[] = [
                'title' => (string)($section['title'] ?? ''),
                'open' => !empty($section['open']),
                'count' => count($rows),
                'rows' => $rows,
            ];
        }

        $links = [];
        foreach ((array)($data['links'] ?? []) as $link) {
            if (!is_array($link) || trim((string)($link['url'] ?? '')) === '') {
                continue;
            }
            $links[] = $this->link((string)$link['url'], (string)($link['label'] ?? $link['url']));
        }

        $title = trim((string)($data['title'] ?? ''));
        if ($title === '' && empty($sections)) {
            return null;
        }

        $badge = trim((string)($data['badge'] ?? ''));
        $count = (int)($data['count'] ?? 0);
        $emptytext = trim((string)($data['empty'] ?? ''));

        return [
            'title' => $title,
            'badge' => $badge,
            'count' => $count > 0 ? $count : '',
            'sections' => $sections,
            'hassections' => !empty($sections),
            'emptyhtml' => empty($sections)
                ? ($emptytext === ''
                    ? $this->empty_state_html()
                    : \html_writer::tag('p', $this->esc($emptytext), ['class' => 'text-muted mb-0']))
                : '',
            'links' => $links,
            'haslinks' => !empty($links),
        ];
    }

    /**
     * Row context (raw scalars; statusbadge is pre-escaped by the base helper).
     *
     * @param array $row
     * @return array
     */
    private function build_row(array $row): array {
        $badge = trim((string)($row['badge'] ?? ''));
        $statusbadge = null;
        if (isset($row['statusid']) && is_numeric($row['statusid'])) {
            $statusbadge = $this->status_badge((int)$row['statusid']);
        }
        return [
            'label' => (string)($row['label'] ?? ''),
            'iscode' => !empty($row['code']),
            'value' => (string)($row['value'] ?? ''),
            'hint' => (string)($row['hint'] ?? ''),
            'badge' => $badge,
            'badgeclass' => trim((string)($row['badgeclass'] ?? '')) ?: self::DEFAULT_BADGE_CLASS,
            'statusbadge' => $statusbadge,
        ];
    }

    /**
     * A catalog has no ids to accumulate.
     *
     * @param array $data
     * @return array<string,int[]>
     */
    protected function default_payload(array $data): array {
        return [];
    }
}
