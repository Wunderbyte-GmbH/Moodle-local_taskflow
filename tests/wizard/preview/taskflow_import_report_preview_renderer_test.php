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
use local_taskflow\local\wizard\taskflow\preview\taskflow_import_report_preview_renderer;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;

/**
 * Rendering of the taskflow_import_report side-pane card (no engine needed).
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\preview\taskflow_import_report_preview_renderer
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class taskflow_import_report_preview_renderer_test extends advanced_testcase {
    /**
     * Setup.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Data of a full import report.
     *
     * @return array
     */
    private function data(): array {
        return [
            'adapter' => 'tuines',
            'last_run' => [
                'found' => true,
                'name' => 'fetch_dwh_data',
                'classname' => '\\taskflowadapter_tuines\\task\\fetch_dwh_data',
                'lastruntime' => 1756857600,
                'time_text' => '3 September 2026, 2:00 AM',
                'disabled' => false,
            ],
            'counters' => [
                ['label' => 'Unit memberships', 'value' => 1190],
                ['label' => 'Users in units', 'value' => 1204],
            ],
            'errors' => [
                [
                    'time_text' => '2 September 2026',
                    'event' => 'dwh_fetch_failed',
                    'message' => 'timeout <script>alert(1)</script>',
                ],
            ],
            'unmapped' => [['function' => 'translator_user_longleave', 'label' => 'Long Leave']],
            'warnings' => ['7 unit member(s) have an empty supervisor field.', ''],
            'recommendations' => ['Map the translator function Long Leave.'],
            'adapter_skill' => 'taskflowadapter_tuines.diagnose_dwh_import',
            'links' => ['docs' => ['https://example.org/local/taskflow/documentation.php?file=user/adapters/tuines.md']],
        ];
    }

    /**
     * Full card: header, counters, error and mapping sections, warnings, recommendations, links.
     */
    public function test_render_full_card(): void {
        $block = (new taskflow_import_report_preview_renderer())->render($this->data(), 'en');

        $this->assertNotNull($block);
        $this->assertSame(taskflow_preview_renderer_factory::TYPE_IMPORT_REPORT, $block['type']);
        $this->assertSame([], $block['payload']);

        $html = $block['html'];
        $this->assertSame(1, substr_count($html, 'taskflow-ai-preview-item'));
        $this->assertStringContainsString(get_string('agent_preview_import_title', 'local_taskflow', 'tuines'), $html);
        $this->assertStringContainsString(get_string('agent_preview_import_errorcount', 'local_taskflow', 1), $html);
        $this->assertStringContainsString('badge bg-danger', $html);
        $this->assertStringContainsString('fetch_dwh_data', $html);
        $this->assertStringContainsString('Unit memberships: 1190', $html);
        $this->assertStringContainsString('dwh_fetch_failed', $html);
        $this->assertStringContainsString('translator_user_longleave', $html);
        $this->assertStringContainsString('7 unit member(s) have an empty supervisor field.', $html);
        $this->assertStringContainsString('Map the translator function Long Leave.', $html);
        $this->assertStringContainsString('taskflowadapter_tuines.diagnose_dwh_import', $html);
        $this->assertStringContainsString('documentation.php?file=user/adapters/tuines.md', $html);
        $this->assertStringContainsString('<details', $html);

        // Escaping of the untrusted event payload.
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    /**
     * Without errors the badge stays neutral; without an adapter the card is not rendered.
     */
    public function test_clean_report_and_null(): void {
        $renderer = new taskflow_import_report_preview_renderer();

        $block = $renderer->render(['adapter' => 'standard', 'last_run' => ['found' => false]], 'en');
        $this->assertNotNull($block);
        $this->assertDebuggingNotCalled();
        $this->assertStringContainsString('badge bg-light text-dark', $block['html']);
        $this->assertStringContainsString(get_string('agent_preview_none', 'local_taskflow'), $block['html']);
        $this->assertStringNotContainsString('<details', $block['html']);

        $this->assertNull($renderer->render([], 'en'));
        $this->assertNull($renderer->render(['adapter' => '  '], 'en'));
    }

    /**
     * Labels follow the requested output language.
     */
    public function test_language(): void {
        $block = (new taskflow_import_report_preview_renderer())->render($this->data(), 'de');
        $this->assertStringContainsString(
            get_string_manager()->get_string('agent_preview_import_lastrun', 'local_taskflow', null, 'de'),
            $block['html']
        );
    }
}
