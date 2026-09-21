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
use local_taskflow\local\requests;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\preview\taskflow_request_list_preview_renderer;

/**
 * Rendering of the taskflow_request_list side-pane table (no engine needed).
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\preview\taskflow_request_list_preview_renderer
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class taskflow_request_list_preview_renderer_test extends advanced_testcase {
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
     * Table with two rows, scope badge, links, treated badges and payload lists.
     */
    public function test_render_table(): void {
        $data = [
            'scope' => 'supervisor',
            'total' => 3,
            'filters' => ['Status: Open'],
            'requests' => [
                [
                    'id' => 88, 'type' => 2, 'typelabel' => 'Duedate extension',
                    'treated' => requests::TREATED_STATUS_UNTREATED, 'treatedlabel' => 'Open',
                    'userid' => 5, 'fullname' => 'Anna Muster', 'assignmentid' => 4711,
                    'rulename' => 'Data protection', 'receiver' => 0, 'receiverlabel' => 'Supervisor',
                    'comment' => 'Please decide', 'timecreated' => time() - DAYSECS,
                ],
                [
                    'id' => 89, 'type' => 1, 'typelabel' => 'Not relevant',
                    'treated' => requests::TREATED_STATUS_CONFIRMED, 'treatedlabel' => 'Confirmed',
                    'userid' => 6, 'fullname' => 'Bert <b>Beispiel</b>', 'assignmentid' => 4720,
                    'rulename' => 'First aid', 'receiver' => 1, 'receiverlabel' => 'HR',
                    'comment' => '', 'timecreated' => time() - 2 * DAYSECS,
                ],
            ],
        ];
        $block = (new taskflow_request_list_preview_renderer())->render($data, 'en');

        $this->assertNotNull($block);
        $this->assertSame(taskflow_preview_renderer_factory::TYPE_REQUEST_LIST, $block['type']);
        $this->assertSame([88, 89], $block['payload']['requestids']);
        $this->assertSame([4711, 4720], $block['payload']['assignmentids']);
        $this->assertSame([5, 6], $block['payload']['userids']);
        $html = $block['html'];

        $this->assertSame(1, substr_count($html, 'taskflow-ai-preview-item'));
        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('assignment.php?id=4711', $html);
        $this->assertStringContainsString('user/profile.php?id=5', $html);
        $this->assertStringContainsString('Duedate extension', $html);
        $this->assertStringContainsString('bg-warning', $html);
        $this->assertStringContainsString('bg-success', $html);
        $this->assertStringContainsString(get_string('agent_scope_supervisor', 'local_taskflow'), $html);
        $this->assertStringContainsString('Status: Open', $html);
        $this->assertStringContainsString(
            get_string('agent_preview_showing', 'local_taskflow', (object)['shown' => 2, 'total' => 3]),
            $html
        );
        $this->assertStringContainsString('Beispiel', $html);
        $this->assertStringNotContainsString('<b>Beispiel</b>', $html);
        $this->assertDebuggingNotCalled();
    }

    /**
     * A long comment is truncated in the cell and kept in full in the title attribute.
     */
    public function test_comment_is_truncated(): void {
        $comment = str_repeat('a', 200);
        $block = (new taskflow_request_list_preview_renderer())->render(['requests' => [[
            'id' => 1, 'userid' => 2, 'fullname' => 'A', 'assignmentid' => 3, 'rulename' => 'R',
            'treated' => requests::TREATED_STATUS_UNTREATED, 'treatedlabel' => 'Open', 'comment' => $comment,
        ]]]);
        $this->assertStringContainsString('…', $block['html']);
        $this->assertStringContainsString($comment, $block['html']);
    }

    /**
     * Empty list renders the empty-state card; rows without id are skipped.
     */
    public function test_empty_state(): void {
        $block = (new taskflow_request_list_preview_renderer())->render(['requests' => [], 'scope' => 'self']);
        $this->assertNotNull($block);
        $this->assertStringContainsString(get_string('agent_preview_empty_request_list', 'local_taskflow'), $block['html']);
        $this->assertStringNotContainsString('<table', $block['html']);
        $this->assertSame([], $block['payload']['requestids']);

        $block = (new taskflow_request_list_preview_renderer())->render(['requests' => [['fullname' => 'x']]]);
        $this->assertStringNotContainsString('<table', $block['html']);
    }
}
