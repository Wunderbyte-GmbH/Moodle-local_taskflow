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
use local_taskflow\local\wizard\taskflow\preview\taskflow_supervisor_overview_preview_renderer;

/**
 * Rendering of the taskflow_supervisor_overview side-pane table (no engine needed).
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\preview\taskflow_supervisor_overview_preview_renderer
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class taskflow_supervisor_overview_preview_renderer_test extends advanced_testcase {
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
     * Team table with totals, profile links, an overdue badge and the payload user ids.
     */
    public function test_render_table(): void {
        $data = [
            'supervisor' => ['id' => 9, 'fullname' => 'Emily Smith'],
            'subordinates' => [
                ['userid' => 5, 'fullname' => 'Anna Muster', 'open' => 3, 'overdue' => 2, 'completed' => 1,
                    'open_requests' => 1, 'unread_chats' => 1],
                ['userid' => 6, 'fullname' => 'Bert <b>Beispiel</b>', 'open' => 2, 'overdue' => 0, 'completed' => 0,
                    'open_requests' => 0, 'unread_chats' => 0],
            ],
            'totals' => ['open' => 5, 'overdue' => 2, 'completed' => 1, 'open_requests' => 1, 'unread_chats' => 1],
        ];
        $block = (new taskflow_supervisor_overview_preview_renderer())->render($data, 'en');

        $this->assertNotNull($block);
        $this->assertSame(taskflow_preview_renderer_factory::TYPE_SUPERVISOR_OVERVIEW, $block['type']);
        $this->assertSame([9, 5, 6], $block['payload']['userids']);
        $html = $block['html'];

        $this->assertSame(1, substr_count($html, 'taskflow-ai-preview-item'));
        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('user/profile.php?id=5', $html);
        $this->assertStringContainsString('Emily Smith', $html);
        $this->assertStringContainsString(get_string('agent_preview_overdue', 'local_taskflow'), $html);
        $this->assertStringContainsString(get_string('agent_preview_unread_chats', 'local_taskflow'), $html);
        $this->assertStringContainsString('bg-danger', $html);
        $this->assertStringContainsString('Beispiel', $html);
        $this->assertStringNotContainsString('<b>Beispiel</b>', $html);
        $this->assertDebuggingNotCalled();
    }

    /**
     * Without overdue assignments the totals badge stays neutral.
     */
    public function test_no_overdue_is_not_red(): void {
        $block = (new taskflow_supervisor_overview_preview_renderer())->render([
            'supervisor' => ['id' => 9, 'fullname' => 'Emily Smith'],
            'subordinates' => [['userid' => 5, 'fullname' => 'Anna Muster', 'open' => 1, 'overdue' => 0]],
            'totals' => ['open' => 1, 'overdue' => 0],
        ], 'en');
        $this->assertStringNotContainsString('bg-danger', $block['html']);
    }

    /**
     * An empty team renders the empty-state card; rows without user id are skipped.
     */
    public function test_empty_state(): void {
        $block = (new taskflow_supervisor_overview_preview_renderer())->render([
            'supervisor' => ['id' => 9, 'fullname' => 'Emily Smith'],
            'subordinates' => [],
            'totals' => [],
        ]);
        $this->assertNotNull($block);
        $this->assertStringContainsString(
            get_string('agent_preview_empty_supervisor_overview', 'local_taskflow'),
            $block['html']
        );
        $this->assertStringNotContainsString('<table', $block['html']);
        $this->assertSame([9], $block['payload']['userids']);

        $block = (new taskflow_supervisor_overview_preview_renderer())->render(['subordinates' => [['open' => 1]]]);
        $this->assertStringNotContainsString('<table', $block['html']);
    }
}
