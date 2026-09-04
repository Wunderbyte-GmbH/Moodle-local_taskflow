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
 * Side-pane preview 'taskflow_diagnostic_checklist': one checklist card per diagnosis.
 *
 * Data contract (filled by the diagnose skills #13, #14, #17 and reused by dry-run previews):
 * title (string), rows[] {status: ok|fail|warn, check, detail, url}, verdict {code, label, class},
 * status (int|null: assignment status id rendered as a badge), links {page, docs[], ...},
 * ids {userids[], ruleids[], assignmentids[]}.
 *
 * Markup mirrors the engine's wizard-diagnostic-checklist (glyphs with aria-hidden, findings
 * as muted small text, links with a speaking label) so all diagnose skills look identical.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class taskflow_diagnostic_checklist_preview_renderer extends taskflow_preview_renderer_base {
    /** Preview type. */
    public const PREVIEW_TYPE = taskflow_preview_renderer_factory::TYPE_DIAGNOSTIC_CHECKLIST;

    /** Row status => glyph (screen readers get the text of 'srtext' instead). */
    private const GLYPHS = ['ok' => '✓', 'fail' => '✗', 'warn' => '⚠'];

    /** Row status => bootstrap text colour class. */
    private const TEXTCLASS = ['ok' => 'text-success', 'fail' => 'text-danger', 'warn' => 'text-warning'];

    /** Row status => lang key of the screen-reader text. */
    private const SRKEYS = [
        'ok' => 'agent_preview_check_passed',
        'fail' => 'agent_preview_check_failed',
        'warn' => 'agent_preview_check_warning',
    ];

    /** Verdict class => badge class. */
    private const VERDICTCLASS = [
        'ok' => 'badge bg-success',
        'fail' => 'badge bg-danger',
        'warn' => 'badge bg-warning text-dark',
    ];

    /**
     * Build the template context.
     *
     * @param array $data
     * @return array|null Null when neither a title nor a single row is present.
     */
    protected function build_context(array $data): ?array {
        $title = trim((string)($data['title'] ?? ''));
        $rows = $this->build_rows(is_array($data['rows'] ?? null) ? (array)$data['rows'] : []);
        if ($title === '' && empty($rows)) {
            return null;
        }

        $verdict = null;
        if (is_array($data['verdict'] ?? null) && trim((string)($data['verdict']['label'] ?? '')) !== '') {
            $class = (string)($data['verdict']['class'] ?? 'warn');
            $verdict = [
                'code' => $this->esc($data['verdict']['code'] ?? ''),
                'label' => $this->esc($data['verdict']['label']),
                'class' => self::VERDICTCLASS[$class] ?? self::VERDICTCLASS['warn'],
            ];
        }

        $status = null;
        if (isset($data['status']) && $data['status'] !== null && $data['status'] !== '') {
            $status = $this->status_badge((int)$data['status']);
        }

        return [
            'title' => $this->esc($title),
            'hastitle' => $title !== '',
            'status' => $status,
            'rows' => $rows,
            'hasrows' => !empty($rows),
            'emptytext' => $this->esc($this->str('agent_preview_empty_diagnostic_checklist')),
            'verdictlabel' => $this->esc($this->str('agent_preview_verdict')),
            'verdict' => $verdict,
            'links' => $this->build_links(is_array($data['links'] ?? null) ? (array)$data['links'] : []),
        ];
    }

    /**
     * Id arrays for accumulation across a confirm chain.
     *
     * @param array $data
     * @return array<string,int[]>
     */
    protected function default_payload(array $data): array {
        $ids = is_array($data['ids'] ?? null) ? (array)$data['ids'] : [];
        $payload = [];
        foreach (['userids', 'ruleids', 'assignmentids', 'unitids'] as $key) {
            if (isset($ids[$key]) && is_array($ids[$key])) {
                $payload[$key] = array_values($ids[$key]);
            }
        }
        return $payload;
    }

    /**
     * Normalize the raw checklist rows into template rows.
     *
     * @param array $rows
     * @return array<int,array<string,mixed>>
     */
    private function build_rows(array $rows): array {
        $normalized = [];
        foreach ($rows as $row) {
            $row = (array)$row;
            $check = trim((string)($row['check'] ?? ''));
            if ($check === '') {
                continue;
            }
            $status = (string)($row['status'] ?? 'warn');
            if (!isset(self::GLYPHS[$status])) {
                $status = 'warn';
            }
            $detail = trim((string)($row['detail'] ?? ''));
            $url = trim((string)($row['url'] ?? ''));
            $normalized[] = [
                'glyph' => self::GLYPHS[$status],
                'glyphclass' => self::TEXTCLASS[$status] . ' me-2 fw-bold',
                'srtext' => $this->esc($this->str(self::SRKEYS[$status])),
                'check' => $this->esc($check),
                'detail' => $this->esc($this->truncate($detail, 300)),
                'hasdetail' => $detail !== '',
                'link' => $url === '' ? null : $this->link($url, $this->str('agent_preview_open_link')),
            ];
        }
        return $normalized;
    }

    /**
     * Turn the result links block into template link objects.
     *
     * @param array $links
     * @return array<int,array{url:string,label:string}>
     */
    private function build_links(array $links): array {
        $result = [];
        $page = trim((string)($links['page'] ?? ''));
        if ($page !== '') {
            $result[] = $this->link($page, $this->str('agent_preview_open_link'));
        }
        foreach (['edit' => 'agent_preview_edit_assignment', 'rule' => 'agent_preview_open_rule'] as $key => $labelkey) {
            $url = trim((string)($links[$key] ?? ''));
            if ($url !== '') {
                $result[] = $this->link($url, $this->str($labelkey));
            }
        }
        foreach ((array)($links['docs'] ?? []) as $url) {
            $url = trim((string)$url);
            if ($url !== '') {
                $result[] = $this->link($url, $this->str('agent_preview_open_docs'));
            }
        }
        return $result;
    }
}
