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
use local_taskflow\local\wizard\taskflow\preview\taskflow_catalog_preview_renderer;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;

/**
 * Tests for the taskflow_catalog side-pane preview renderer (engine-independent).
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\preview\taskflow_catalog_preview_renderer
 * @covers     \local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_base
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class taskflow_catalog_preview_renderer_test extends advanced_testcase {
    /**
     * Setup.
     */
    protected function setUp(): void {
        parent::setUp();
        // Pin the mocked clock of tool_mocktesttime to now: other suites advance it and never reset it.
        if (class_exists('\\tool_mocktesttime\\time_mock')) {
            \tool_mocktesttime\time_mock::reset_mock_time();
        }
        $this->resetAfterTest();
    }

    /**
     * The factory resolves the type to this renderer.
     */
    public function test_factory_resolves_catalog_type(): void {
        $renderer = taskflow_preview_renderer_factory::for_type(taskflow_preview_renderer_factory::TYPE_CATALOG);
        $this->assertInstanceOf(taskflow_catalog_preview_renderer::class, $renderer);
        $this->assertFileExists(__DIR__ . '/../../../templates/wizard/preview_catalog.mustache');
    }

    /**
     * Full card: title, badge, sections, rows, badges, status badge, links.
     */
    public function test_render_full_card(): void {
        $overdue = assignment_status_facade::get_status_identifier('overdue');
        $block = (new taskflow_catalog_preview_renderer())->render([
            'title' => 'Settings',
            'badge' => 'tuines',
            'count' => 2,
            'sections' => [
                ['title' => 'local_taskflow', 'open' => true, 'rows' => [
                    ['label' => 'external_api_option', 'code' => true, 'value' => 'tuines', 'hint' => 'Adapter'],
                ]],
                ['title' => 'Operators', 'open' => false, 'rows' => [
                    ['label' => 'since', 'code' => true, 'value' => 'Since', 'hint' => 'inclusive',
                        'badge' => 'not at runtime', 'badgeclass' => 'bg-warning text-dark'],
                    ['label' => (string)$overdue, 'statusid' => $overdue, 'value' => 'x', 'hint' => 'active'],
                ]],
            ],
            'links' => [['url' => 'https://example.com/settings', 'label' => 'Open settings']],
        ], 'en', ['settingids' => [3, 1]]);

        $this->assertNotNull($block);
        $this->assertSame('taskflow_catalog', $block['type']);
        $this->assertSame(['settingids' => [3, 1]], $block['payload']);
        $html = $block['html'];
        $this->assertSame(1, substr_count($html, 'taskflow-ai-preview-item'));
        $this->assertStringContainsString('data-preview-type="taskflow_catalog"', $html);
        $this->assertStringContainsString('Settings', $html);
        $this->assertStringContainsString('tuines', $html);
        $this->assertStringContainsString('<details class="taskflow-ai-catalog-section mb-2" open>', $html);
        $this->assertStringContainsString('<details class="taskflow-ai-catalog-section mb-2">', $html);
        $this->assertStringContainsString('<code>external_api_option</code>', $html);
        $this->assertStringContainsString('bg-warning text-dark', $html);
        $this->assertStringContainsString('not at runtime', $html);
        $this->assertStringContainsString('bg-danger', $html);
        $this->assertStringContainsString(s(assignment_status_facade::get_specific_names($overdue, 'en')), $html);
        $this->assertStringContainsString('visually-hidden', $html);
        $this->assertStringContainsString('href="https://example.com/settings"', $html);
        $this->assertStringContainsString('rel="noopener"', $html);
        $this->assertDebuggingNotCalled();
    }

    /**
     * Raw values are escaped.
     */
    public function test_escaping(): void {
        $block = (new taskflow_catalog_preview_renderer())->render([
            'title' => '<script>alert(1)</script>',
            'sections' => [['title' => 'S', 'rows' => [
                ['label' => '<b>l</b>', 'value' => '<i>v</i>', 'hint' => '<u>h</u>', 'badge' => '<s>b</s>'],
            ]]],
        ]);
        $this->assertNotNull($block);
        $this->assertStringNotContainsString('<script>', $block['html']);
        $this->assertStringContainsString('&lt;script&gt;', $block['html']);
        $this->assertStringContainsString('&lt;b&gt;l&lt;/b&gt;', $block['html']);
        $this->assertStringContainsString('&lt;i&gt;v&lt;/i&gt;', $block['html']);
        $this->assertStringContainsString('&lt;u&gt;h&lt;/u&gt;', $block['html']);
        $this->assertStringContainsString('&lt;s&gt;b&lt;/s&gt;', $block['html']);
    }

    /**
     * No sections: empty-state card (custom text or agent_preview_empty_catalog); no data at all: null.
     */
    public function test_empty_state(): void {
        $renderer = new taskflow_catalog_preview_renderer();

        $block = $renderer->render(['title' => 'Settings', 'sections' => [], 'empty' => 'Nothing matches "x"']);
        $this->assertNotNull($block);
        $this->assertStringContainsString('Nothing matches &quot;x&quot;', $block['html']);
        $this->assertStringNotContainsString('<details', $block['html']);

        $block = $renderer->render(['title' => 'Settings'], 'en');
        $this->assertNotNull($block);
        $this->assertStringContainsString(s(get_string('agent_preview_empty_catalog', 'local_taskflow')), $block['html']);

        $this->assertNull($renderer->render([]));
        $this->assertNull($renderer->render(['sections' => [['title' => 'x', 'rows' => []]]]));
        $this->assertDebuggingNotCalled();
    }

    /**
     * Sections without rows are dropped and the row count is shown.
     */
    public function test_section_counts(): void {
        $block = (new taskflow_catalog_preview_renderer())->render([
            'title' => 'T',
            'sections' => [
                ['title' => 'Empty', 'rows' => []],
                ['title' => 'Two', 'rows' => [['label' => 'a', 'value' => '1'], ['label' => 'b', 'value' => '2']]],
            ],
        ]);
        $this->assertNotNull($block);
        $this->assertStringNotContainsString('Empty', $block['html']);
        $this->assertStringContainsString('Two', $block['html']);
        $this->assertStringContainsString('(2)', $block['html']);
        $this->assertSame([], $block['payload']);
    }
}
