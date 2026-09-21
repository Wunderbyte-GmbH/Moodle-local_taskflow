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
use local_taskflow\local\wizard\taskflow\preview\taskflow_assignment_preview_renderer;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;

/**
 * Rendering of the taskflow_assignment side-pane card (no engine needed).
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\preview\taskflow_assignment_preview_renderer
 * @covers     \local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_base
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class taskflow_assignment_preview_renderer_test extends advanced_testcase {
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
     * Data as produced by get_assignment_details.
     *
     * @param array $overrides Assignment field overrides.
     * @return array
     */
    private function data(array $overrides = []): array {
        $overdue = assignment_status_facade::get_status_identifier('overdue');
        return [
            'assignment' => array_merge([
                'id' => 4711,
                'userid' => 5,
                'fullname' => 'Anna Muster',
                'ruleid' => 17,
                'rulename' => 'Data protection basics',
                'status' => $overdue,
                'active' => true,
                'duedate' => time() - 23 * DAYSECS,
                'assigneddate' => time() - 90 * DAYSECS,
                'overduecounter' => 2,
                'prolongedcounter' => 1,
                'supervisor' => ['id' => 9, 'fullname' => 'Dr. Emily Smith', 'email' => 'e@example.org'],
                'caneditassignment' => true,
            ], $overrides),
            'targets' => [
                ['targettype' => 'moodlecourse', 'typelabel' => 'Moodle course', 'targetid' => 23,
                    'name' => 'Data protection basics', 'completed' => true,
                    'url' => 'https://example.org/course/view.php?id=23'],
                ['targettype' => 'bookingoption', 'typelabel' => 'Booking option', 'targetid' => 3,
                    'name' => 'Webinar', 'completed' => true, 'url' => ''],
                ['targettype' => 'competency', 'typelabel' => 'Competency', 'targetid' => 8,
                    'name' => 'GDPR proof', 'completed' => false, 'url' => '', 'evidence' => ['status' => 'underreview']],
            ],
            'open_requests' => [
                ['id' => 88, 'typelabel' => 'Request prolongation', 'treatedlabel' => 'Open', 'timecreated' => time(),
                    'comment' => 'Sick'],
            ],
            'history' => [
                ['type' => 'manual_change', 'typelabel' => 'Manual change', 'createdbyname' => 'Admin User',
                    'annotation' => 'note', 'timecreated' => time()],
            ],
            'chat_enabled' => true,
            'chat_total' => 3,
            'chat_preview' => [['fullname' => 'Anna Muster', 'text' => 'Can I get an extension?', 'timecreated' => time()]],
            'pending_tasks' => [['name' => 'check_assignment_status', 'nextruntime' => time() + 3600]],
            'links' => [
                'page' => 'x',
                'docs' => ['https://example.org/local/taskflow/documentation.php?file=user/assignments/01-status-lifecycle.md'],
            ],
        ];
    }

    /**
     * Full card: type, payload, badge colour and text, links, progress bar, collapsibles.
     */
    public function test_render_full_card(): void {
        $overdue = assignment_status_facade::get_status_identifier('overdue');
        $block = (new taskflow_assignment_preview_renderer())->render($this->data(), 'en');

        $this->assertNotNull($block);
        $this->assertSame(taskflow_preview_renderer_factory::TYPE_ASSIGNMENT, $block['type']);
        $this->assertSame([4711], $block['payload']['assignmentids']);
        $this->assertSame([5], $block['payload']['userids']);
        $html = $block['html'];

        $this->assertSame(1, substr_count($html, 'taskflow-ai-preview-item'));
        $this->assertStringContainsString('data-preview-type="taskflow_assignment"', $html);
        $this->assertStringContainsString(s(assignment_status_facade::get_specific_names($overdue, 'en')), $html);
        $this->assertStringContainsString('bg-danger', $html);
        $this->assertStringContainsString('visually-hidden', $html);
        $this->assertStringContainsString('assignment.php?id=4711', $html);
        $this->assertStringContainsString('editassignment.php?id=4711', $html);
        $this->assertStringContainsString('editrule.php?id=17', $html);
        $this->assertStringContainsString('user/profile.php?id=9', $html);
        $this->assertStringContainsString('role="progressbar"', $html);
        $this->assertStringContainsString('aria-valuenow="2"', $html);
        $this->assertStringContainsString('aria-valuemax="3"', $html);
        $this->assertStringContainsString('2/3', $html);
        $this->assertStringContainsString('course/view.php?id=23', $html);
        $this->assertStringContainsString('underreview', $html);
        $this->assertStringContainsString('<details', $html);
        $this->assertStringContainsString('Can I get an extension?', $html);
        $this->assertStringContainsString('check_assignment_status', $html);
        $this->assertStringContainsString('documentation.php?file=user/assignments/01-status-lifecycle.md', $html);
        $this->assertStringContainsString('target="_blank" rel="noopener"', $html);
    }

    /**
     * Free text is escaped; names go through format_string.
     */
    public function test_escaping(): void {
        $data = $this->data(['rulename' => '<script>alert(1)</script>', 'fullname' => 'Eve <img src=x>']);
        $data['chat_preview'][0]['text'] = '<script>alert(2)</script>';
        $block = (new taskflow_assignment_preview_renderer())->render($data);

        // Names pass format_string() (tags stripped), free text passes s() (tags escaped).
        $this->assertStringNotContainsString('<script>', $block['html']);
        $this->assertStringContainsString('&lt;script&gt;alert(2)&lt;/script&gt;', $block['html']);
        $this->assertStringNotContainsString('<img', $block['html']);
    }

    /**
     * Missing id yields null; minimal data renders without notices; chat hidden when disabled.
     */
    public function test_minimal_and_empty(): void {
        $renderer = new taskflow_assignment_preview_renderer();
        $this->assertNull($renderer->render([]));
        $this->assertNull($renderer->render(['assignment' => ['fullname' => 'x']]));

        $block = $renderer->render(['assignment' => ['id' => 1, 'status' => 0]]);
        $this->assertNotNull($block);
        $this->assertStringContainsString('assignment.php?id=1', $block['html']);
        $this->assertStringNotContainsString('editassignment.php', $block['html']);
        $this->assertStringContainsString('aria-valuenow="0"', $block['html']);
        $this->assertDebuggingNotCalled();

        $data = $this->data();
        $data['chat_enabled'] = false;
        $block = $renderer->render($data);
        $this->assertStringNotContainsString('Can I get an extension?', $block['html']);
    }

    /**
     * The render language is forced independently of the session language.
     */
    public function test_language_forced(): void {
        $overdue = assignment_status_facade::get_status_identifier('overdue');
        $de = (new taskflow_assignment_preview_renderer())->render($this->data(), 'de');
        $en = (new taskflow_assignment_preview_renderer())->render($this->data(), 'en');
        $this->assertStringContainsString(s(assignment_status_facade::get_specific_names($overdue, 'de')), $de['html']);
        $this->assertStringContainsString(s(assignment_status_facade::get_specific_names($overdue, 'en')), $en['html']);
        $this->assertStringContainsString(get_string('agent_preview_history', 'local_taskflow'), $en['html']);
    }
}
