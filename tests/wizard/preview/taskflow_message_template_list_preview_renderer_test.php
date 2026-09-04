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
use local_taskflow\local\wizard\taskflow\preview\taskflow_message_template_list_preview_renderer;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;

/**
 * Renderer of the taskflow_message_template_list preview (engine-independent).
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\preview\taskflow_message_template_list_preview_renderer
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class taskflow_message_template_list_preview_renderer_test extends advanced_testcase {
    /**
     * Setup.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Data as search_message_templates_skill delivers it.
     *
     * @return array
     */
    private function data(): array {
        return [
            'templates' => [
                [
                    'id' => 5,
                    'name' => 'Reminder 7d',
                    'type' => 'standard',
                    'class' => 'standard',
                    'subject' => 'Reminder: training due',
                    'recipients' => ['assignee'],
                    'cc' => ['supervisor'],
                    'timing' => ['sendstart' => 'end', 'senddirection' => 'before', 'senddays' => '7'],
                    'timing_label' => '7 days before Due date',
                    'package' => ['Onboarding'],
                    'priority' => 1,
                    'used_in_rules' => [
                        ['id' => 17, 'name' => 'Data protection basics',
                            'url' => 'https://example.org/local/taskflow/editrule.php?id=17'],
                    ],
                    'edit_url' => 'https://example.org/local/taskflow/message_form/editmessage.php?id=5',
                ],
                [
                    'id' => 6,
                    'name' => 'Overdue',
                    'type' => 'standard',
                    'class' => 'onevent',
                    'subject' => '',
                    'recipients' => [],
                    'cc' => [],
                    'timing' => [],
                    'timing_label' => '',
                    'package' => [],
                    'priority' => 3,
                    'used_in_rules' => [],
                    'edit_url' => 'https://example.org/local/taskflow/message_form/editmessage.php?id=6',
                ],
            ],
            'total' => 9,
            'query' => 'Reminder',
            'type' => 'standard',
            'limit' => 2,
        ];
    }

    /**
     * Table with rows, badges, labels, links and the "showing N of M" note.
     */
    public function test_render_rows(): void {
        $preview = (new taskflow_message_template_list_preview_renderer())->render($this->data());

        $this->assertNotNull($preview);
        $this->assertSame(taskflow_preview_renderer_factory::TYPE_MESSAGE_TEMPLATE_LIST, $preview['type']);
        $this->assertSame(['messageids' => [5, 6]], $preview['payload']);

        $html = $preview['html'];
        $this->assertSame(1, substr_count($html, 'taskflow-ai-preview-item'));
        $this->assertStringContainsString(
            get_string('agent_preview_message_template_list_title', 'local_taskflow', 2),
            $html
        );
        $this->assertStringContainsString(
            s(get_string('agent_preview_message_template_list_type', 'local_taskflow', 'standard')),
            $html
        );
        $this->assertStringContainsString(
            get_string('agent_preview_rule_list_showing', 'local_taskflow', (object)['shown' => 2, 'total' => 9]),
            $html
        );
        $this->assertStringContainsString('Reminder 7d', $html);
        $this->assertStringContainsString('editmessage.php?id=5', $html);
        $this->assertStringContainsString('editmessage.php?id=6', $html);
        $this->assertStringContainsString('editrule.php?id=17', $html);
        $this->assertStringContainsString('Data protection basics', $html);
        $this->assertStringContainsString('Onboarding', $html);
        $this->assertStringContainsString('7 days before Due date', $html);
        $this->assertStringContainsString(get_string('assignee', 'local_taskflow'), $html);
        $this->assertStringContainsString(get_string('prioritylow', 'local_taskflow'), $html);
        $this->assertStringContainsString(get_string('priorityhigh', 'local_taskflow'), $html);
        $this->assertStringContainsString(get_string('recipientrole', 'local_taskflow'), $html);
        $this->assertDebuggingNotCalled();
    }

    /**
     * No templates: empty-state text plus editor link, empty payload.
     */
    public function test_empty_state(): void {
        $renderer = new taskflow_message_template_list_preview_renderer();
        $preview = $renderer->render(['templates' => [], 'total' => 0, 'query' => 'x']);

        $this->assertNotNull($preview);
        $this->assertSame(['messageids' => []], $preview['payload']);
        $this->assertStringContainsString(
            get_string('agent_preview_empty_message_template_list', 'local_taskflow'),
            $preview['html']
        );
        $this->assertStringContainsString(
            get_string('agent_preview_open_messages', 'local_taskflow'),
            $preview['html']
        );
        $this->assertStringNotContainsString('<table', $preview['html']);
    }

    /**
     * Names, tags and the subject are escaped.
     */
    public function test_escaping(): void {
        $data = $this->data();
        $data['templates'][0]['name'] = '<script>alert(1)</script>';
        $data['templates'][0]['subject'] = '<i>subject</i>';
        $data['templates'][0]['package'] = ['<b>tag</b>'];

        $preview = (new taskflow_message_template_list_preview_renderer())->render($data);

        $this->assertNotNull($preview);
        $this->assertStringNotContainsString('<script>', $preview['html']);
        $this->assertStringNotContainsString('<i>subject</i>', $preview['html']);
        $this->assertStringContainsString('alert(1)', $preview['html']);
    }
}
