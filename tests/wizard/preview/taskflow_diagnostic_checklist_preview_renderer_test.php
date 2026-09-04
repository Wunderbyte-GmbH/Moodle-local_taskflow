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

namespace local_taskflow\wizard\preview;

use advanced_testcase;
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\wizard\taskflow\preview\taskflow_diagnostic_checklist_preview_renderer;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;

/**
 * Rendering of the taskflow_diagnostic_checklist side-pane card (no engine needed).
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\preview\taskflow_diagnostic_checklist_preview_renderer
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class taskflow_diagnostic_checklist_preview_renderer_test extends advanced_testcase {
    /**
     * Setup.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Data of a full diagnosis card.
     *
     * @return array
     */
    private function data(): array {
        return [
            'title' => 'Diagnosis: Anna Muster · Rule #17',
            'status' => assignment_status_facade::get_status_identifier('overdue'),
            'rows' => [
                ['status' => 'ok', 'check' => 'Rule is active', 'detail' => '', 'url' => ''],
                [
                    'status' => 'fail',
                    'check' => 'Filter: contract equals internal',
                    'detail' => 'The filter does not match <b>this</b> user.',
                    'url' => 'https://example.org/local/taskflow/editrule.php?id=17',
                ],
                ['status' => 'warn', 'check' => 'Pending adhoc tasks', 'detail' => 'update_rule', 'url' => ''],
                ['status' => 'nonsense', 'check' => 'Unknown state', 'detail' => '', 'url' => ''],
                ['status' => 'ok', 'check' => '', 'detail' => 'dropped, no check name', 'url' => ''],
            ],
            'verdict' => ['code' => 'blocked_by_filter', 'label' => 'A rule filter does not match.', 'class' => 'fail'],
            'links' => [
                'page' => 'https://example.org/local/taskflow/editrule.php?id=17',
                'docs' => ['https://example.org/local/taskflow/documentation.php?file=user/rules/02-filters.md'],
            ],
            'ids' => ['userids' => [123], 'ruleids' => [17]],
        ];
    }

    /**
     * Full card: glyphs, status badge, verdict, links, payload, escaping.
     */
    public function test_render_full_card(): void {
        $block = (new taskflow_diagnostic_checklist_preview_renderer())->render($this->data(), 'en');

        $this->assertNotNull($block);
        $this->assertSame(taskflow_preview_renderer_factory::TYPE_DIAGNOSTIC_CHECKLIST, $block['type']);
        $this->assertSame([123], $block['payload']['userids']);
        $this->assertSame([17], $block['payload']['ruleids']);

        $html = $block['html'];
        $this->assertSame(1, substr_count($html, 'taskflow-ai-preview-item'));
        $this->assertStringContainsString('Diagnosis: Anna Muster', $html);
        $this->assertStringContainsString('✓', $html);
        $this->assertStringContainsString('✗', $html);
        $this->assertStringContainsString('⚠', $html);
        $this->assertStringContainsString('text-success', $html);
        $this->assertStringContainsString('text-danger', $html);
        $this->assertStringContainsString(get_string('agent_preview_check_passed', 'local_taskflow'), $html);
        $this->assertStringContainsString(get_string('agent_preview_verdict', 'local_taskflow'), $html);
        $this->assertStringContainsString('A rule filter does not match.', $html);
        $this->assertStringContainsString('badge bg-danger', $html);
        $this->assertStringContainsString('editrule.php?id=17', $html);
        $this->assertStringContainsString('documentation.php?file=user/rules/02-filters.md', $html);
        $this->assertStringContainsString(
            assignment_status_facade::get_specific_names(
                assignment_status_facade::get_status_identifier('overdue'),
                'en'
            ),
            $html
        );

        // Escaping and row filtering.
        $this->assertStringContainsString('&lt;b&gt;this&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>this</b>', $html);
        $this->assertStringNotContainsString('dropped, no check name', $html);
        // An unknown row status degrades to the warning glyph rather than breaking the card.
        $this->assertStringContainsString('Unknown state', $html);
        $this->assertSame(2, substr_count($html, '⚠'));
    }

    /**
     * A checklist without rows renders the empty state; data without title and rows yields null.
     */
    public function test_empty_state_and_null(): void {
        $renderer = new taskflow_diagnostic_checklist_preview_renderer();

        $block = $renderer->render(['title' => 'Diagnosis: nothing to check', 'rows' => []], 'en');
        $this->assertNotNull($block);
        $this->assertStringContainsString(
            get_string('agent_preview_empty_diagnostic_checklist', 'local_taskflow'),
            $block['html']
        );
        $this->assertSame([], $block['payload']);

        $this->assertNull($renderer->render([], 'en'));
        $this->assertNull($renderer->render(['rows' => [['check' => '']]], 'en'));
    }

    /**
     * Minimal data renders without notices and honours the requested output language.
     */
    public function test_minimal_data_and_language(): void {
        $data = ['title' => 'Diagnosis', 'rows' => [['status' => 'ok', 'check' => 'Rule is active']]];
        $block = (new taskflow_diagnostic_checklist_preview_renderer())->render($data, 'en');
        $this->assertNotNull($block);
        $this->assertDebuggingNotCalled();
        $this->assertStringContainsString(get_string('agent_preview_check_passed', 'local_taskflow'), $block['html']);

        $german = (new taskflow_diagnostic_checklist_preview_renderer())->render($data, 'de');
        $this->assertStringContainsString(
            get_string_manager()->get_string('agent_preview_check_passed', 'local_taskflow', null, 'de'),
            $german['html']
        );
    }
}
