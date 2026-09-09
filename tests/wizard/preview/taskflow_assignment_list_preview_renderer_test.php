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
use local_taskflow\local\wizard\taskflow\preview\taskflow_assignment_list_preview_renderer;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;

/**
 * Rendering of the taskflow_assignment_list side-pane table (no engine needed).
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\preview\taskflow_assignment_list_preview_renderer
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class taskflow_assignment_list_preview_renderer_test extends advanced_testcase {
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
     * Table with two rows, scope badge, links, status badges, payload lists.
     */
    public function test_render_table(): void {
        $overdue = assignment_status_facade::get_status_identifier('overdue');
        $assigned = assignment_status_facade::get_status_identifier('assigned');
        $data = [
            'scope' => 'supervisor',
            'total' => 7,
            'filters' => ['overdue only'],
            'assignments' => [
                ['id' => 4711, 'userid' => 5, 'fullname' => 'Anna Muster', 'ruleid' => 17, 'rulename' => 'Data protection',
                    'status' => $overdue, 'duedate' => time() - 23 * DAYSECS, 'overduecounter' => 2, 'prolongedcounter' => 0,
                    'active' => true],
                ['id' => 4720, 'userid' => 6, 'fullname' => 'Bert <b>Beispiel</b>', 'ruleid' => 18, 'rulename' => 'First aid',
                    'status' => $assigned, 'duedate' => time() + 26 * DAYSECS, 'active' => false],
            ],
        ];
        $block = (new taskflow_assignment_list_preview_renderer())->render($data, 'en');

        $this->assertNotNull($block);
        $this->assertSame(taskflow_preview_renderer_factory::TYPE_ASSIGNMENT_LIST, $block['type']);
        $this->assertSame([4711, 4720], $block['payload']['assignmentids']);
        $this->assertSame([5, 6], $block['payload']['userids']);
        $html = $block['html'];

        $this->assertSame(1, substr_count($html, 'taskflow-ai-preview-item'));
        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('assignment.php?id=4711', $html);
        $this->assertStringContainsString('assignment.php?id=4720', $html);
        $this->assertStringContainsString('editrule.php?id=17', $html);
        $this->assertStringContainsString('user/profile.php?id=5', $html);
        $this->assertStringContainsString(s(assignment_status_facade::get_specific_names($overdue, 'en')), $html);
        $this->assertStringContainsString(s(assignment_status_facade::get_specific_names($assigned, 'en')), $html);
        $this->assertStringContainsString('bg-danger', $html);
        $this->assertStringContainsString('bg-primary', $html);
        $this->assertStringContainsString(get_string('agent_scope_supervisor', 'local_taskflow'), $html);
        $this->assertStringContainsString('overdue only', $html);
        $this->assertStringContainsString(
            get_string('agent_preview_showing', 'local_taskflow', (object)['shown' => 2, 'total' => 7]),
            $html
        );
        $this->assertStringContainsString(get_string('activityinactive', 'local_taskflow'), $html);
        $this->assertStringContainsString('Beispiel', $html);
        $this->assertStringNotContainsString('<b>Beispiel</b>', $html);
        $this->assertStringContainsString('local/taskflow/index.php', $html);
        $this->assertDebuggingNotCalled();
    }

    /**
     * Empty list renders the empty-state card with the dashboard link; rows without id are skipped.
     */
    public function test_empty_state(): void {
        $block = (new taskflow_assignment_list_preview_renderer())->render(['assignments' => [], 'scope' => 'self']);
        $this->assertNotNull($block);
        $this->assertStringContainsString(get_string('agent_preview_empty_assignment_list', 'local_taskflow'), $block['html']);
        $this->assertStringNotContainsString('<table', $block['html']);
        $this->assertSame([], $block['payload']['assignmentids']);

        $block = (new taskflow_assignment_list_preview_renderer())->render(['assignments' => [['fullname' => 'x']]]);
        $this->assertStringNotContainsString('<table', $block['html']);
    }

    /**
     * The "open rules dashboard" link is offered only to users who may open the rules/admin dashboard.
     */
    public function test_dashboard_link_requires_rules_dashboard_access(): void {
        $label = get_string('agent_preview_open_dashboard', 'local_taskflow');
        $employee = $this->getDataGenerator()->create_user();
        $manager = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/taskflow:viewreports', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, (int)$manager->id, \context_system::instance()->id);

        $block = (new taskflow_assignment_list_preview_renderer())
            ->render(['assignments' => [], 'scope' => 'self', '_userid' => (int)$employee->id]);
        $this->assertStringNotContainsString($label, $block['html']);
        $this->assertStringNotContainsString('/local/taskflow/index.php', $block['html']);

        $block = (new taskflow_assignment_list_preview_renderer())
            ->render(['assignments' => [], 'scope' => 'self', '_userid' => (int)$manager->id]);
        $this->assertStringContainsString($label, $block['html']);

        // Without an explicit user the current (admin) user decides.
        $block = (new taskflow_assignment_list_preview_renderer())->render(['assignments' => [], 'scope' => 'self']);
        $this->assertStringContainsString($label, $block['html']);
    }

    /**
     * A change column appears only when a row carries a change text (mutation skills).
     */
    public function test_change_column(): void {
        $assigned = assignment_status_facade::get_status_identifier('assigned');
        $rows = [['id' => 1, 'userid' => 2, 'fullname' => 'A', 'ruleid' => 0, 'rulename' => 'R', 'status' => $assigned]];
        $block = (new taskflow_assignment_list_preview_renderer())->render(['assignments' => $rows]);
        $this->assertStringNotContainsString(get_string('agent_preview_change', 'local_taskflow'), $block['html']);

        $rows[0]['change'] = 'assigned → paused';
        $block = (new taskflow_assignment_list_preview_renderer())->render(['assignments' => $rows]);
        $this->assertStringContainsString(get_string('agent_preview_change', 'local_taskflow'), $block['html']);
        $this->assertStringContainsString('assigned → paused', $block['html']);
    }
}
