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
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\preview\taskflow_units_tree_preview_renderer;

/**
 * Rendering of the taskflow_units_tree side-pane tree (no engine, no JavaScript).
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\preview\taskflow_units_tree_preview_renderer
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class taskflow_units_tree_preview_renderer_test extends advanced_testcase {
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
        $this->setAdminUser();
    }

    /**
     * Three levels nest into <details>; the third level is collapsed, the first two are open.
     */
    public function test_render_nested_tree(): void {
        $data = [
            'backend' => 'unit',
            'total' => 3,
            'parentid' => 0,
            'query' => '',
            'units' => [
                ['id' => 10, 'name' => 'Administration', 'parentid' => 0, 'depth' => 1, 'members' => 120,
                    'rules_count' => 3],
                ['id' => 11, 'name' => 'Human resources', 'parentid' => 10, 'depth' => 2, 'members' => 18,
                    'rules_count' => 1],
                ['id' => 12, 'name' => 'Recruiting <b>team</b>', 'parentid' => 11, 'depth' => 3, 'members' => 4,
                    'rules_count' => 0],
            ],
        ];
        $block = (new taskflow_units_tree_preview_renderer())->render($data, 'en');

        $this->assertNotNull($block);
        $this->assertSame(taskflow_preview_renderer_factory::TYPE_UNITS_TREE, $block['type']);
        $this->assertSame([10, 11, 12], $block['payload']['unitids']);
        $html = $block['html'];

        $this->assertSame(1, substr_count($html, 'taskflow-ai-preview-item'));
        $this->assertSame(3, substr_count($html, '<details'));
        $this->assertSame(3, substr_count($html, '<summary'));
        // Levels 0 and 1 are expanded, the third one is collapsed.
        $this->assertSame(2, substr_count($html, 'open="open"'));
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('Administration', $html);
        $this->assertStringContainsString(get_string('agent_preview_members_count', 'local_taskflow', 120), $html);
        $this->assertStringContainsString(get_string('agent_preview_rules_count', 'local_taskflow', 3), $html);
        $this->assertStringContainsString('unit', $html);
        $this->assertStringContainsString('team', $html);
        $this->assertStringNotContainsString('<b>team</b>', $html);
        $this->assertDebuggingNotCalled();
    }

    /**
     * Units whose parent is not in the result set are rendered as roots; filters show in the title area.
     */
    public function test_orphans_become_roots_and_filters_are_shown(): void {
        $block = (new taskflow_units_tree_preview_renderer())->render([
            'backend' => 'cohort',
            'total' => 1,
            'parentid' => 7,
            'query' => 'eng',
            'units' => [['id' => 12, 'name' => 'Engineering', 'parentid' => 99, 'depth' => 3, 'members' => 2,
                'rules_count' => 0]],
        ], 'en');

        $this->assertSame(1, substr_count($block['html'], '<details'));
        $this->assertStringContainsString('open="open"', $block['html']);
        $this->assertStringContainsString(get_string('agent_preview_units_below', 'local_taskflow', 7), $block['html']);
        $this->assertStringContainsString(
            s(get_string('agent_preview_rule_list_query', 'local_taskflow', 'eng')),
            $block['html']
        );
        $this->assertStringContainsString('cohort', $block['html']);
    }

    /**
     * An empty unit list renders the empty-state card; rows without id are skipped.
     */
    public function test_empty_state(): void {
        $block = (new taskflow_units_tree_preview_renderer())->render(['units' => [], 'backend' => 'unit']);
        $this->assertNotNull($block);
        $this->assertStringContainsString(get_string('agent_preview_empty_units_tree', 'local_taskflow'), $block['html']);
        $this->assertStringNotContainsString('<details', $block['html']);
        $this->assertSame([], $block['payload']['unitids']);

        $block = (new taskflow_units_tree_preview_renderer())->render(['units' => [['name' => 'x']]]);
        $this->assertStringNotContainsString('<details', $block['html']);
    }
}
