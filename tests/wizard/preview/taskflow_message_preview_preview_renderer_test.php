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
use local_taskflow\local\wizard\taskflow\preview\taskflow_message_preview_preview_renderer;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;

/**
 * Renderer of the taskflow_message_preview preview (engine-independent).
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\preview\taskflow_message_preview_preview_renderer
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class taskflow_message_preview_preview_renderer_test extends advanced_testcase {
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
     * Data as preview_message_skill delivers it.
     *
     * @return array
     */
    private function data(): array {
        return [
            'messageid' => 5,
            'assignmentid' => 4711,
            'ruleid' => 17,
            'userid' => 12,
            'template' => ['id' => 5, 'name' => 'Reminder 7d', 'class' => 'standard', 'priority' => 2],
            'subject' => 'Reminder: Data protection basics due 12.08.2026',
            'body_html' => '<p>Hello Anna, please finish your training.</p>',
            'recipients' => [
                ['userid' => 12, 'fullname' => 'Anna Muster', 'email' => 'anna@example.org',
                    'role' => 'to', 'deliverable' => true],
                ['userid' => 13, 'fullname' => 'Emily Smith', 'email' => 'emily@example.org',
                    'role' => 'cc', 'deliverable' => true],
            ],
            'placeholders_used' => ['<firstname>', '<due_date>'],
            'sent' => false,
        ];
    }

    /**
     * Mail card with To/CC, subject, body and the "nothing is sent" notice.
     */
    public function test_render_card(): void {
        $preview = (new taskflow_message_preview_preview_renderer())->render($this->data());

        $this->assertNotNull($preview);
        $this->assertSame(taskflow_preview_renderer_factory::TYPE_MESSAGE_PREVIEW, $preview['type']);
        $this->assertSame(['messageids' => [5], 'assignmentids' => [4711]], $preview['payload']);

        $html = $preview['html'];
        $this->assertSame(1, substr_count($html, 'taskflow-ai-preview-item'));
        $this->assertStringContainsString(
            s(get_string('agent_preview_message_title', 'local_taskflow', (object)[
                'name' => 'Reminder 7d',
                'id' => 5,
            ])),
            $html
        );
        $this->assertStringContainsString(get_string('agent_preview_message_nosend', 'local_taskflow'), $html);
        $this->assertStringContainsString(get_string('agent_preview_message_to', 'local_taskflow'), $html);
        $this->assertStringContainsString(get_string('agent_preview_message_cc', 'local_taskflow'), $html);
        $this->assertStringContainsString('Anna Muster', $html);
        $this->assertStringContainsString('Emily Smith', $html);
        $this->assertStringContainsString('anna@example.org', $html);
        $this->assertStringContainsString('Reminder: Data protection basics', $html);
        // The body arrives already sanitised and is inserted as HTML.
        $this->assertStringContainsString('<p>Hello Anna, please finish your training.</p>', $html);
        $this->assertStringContainsString('user/profile.php?id=12', $html);
        $this->assertStringContainsString('assignment.php?id=4711', $html);
        $this->assertStringContainsString('editmessage.php?id=5', $html);
        $this->assertDebuggingNotCalled();
    }

    /**
     * Placeholders are listed as escaped badges.
     */
    public function test_placeholders(): void {
        $html = (new taskflow_message_preview_preview_renderer())->render($this->data())['html'];

        $this->assertStringContainsString(get_string('agent_preview_message_placeholders', 'local_taskflow'), $html);
        $this->assertStringContainsString('&lt;firstname&gt;', $html);
        $this->assertStringContainsString('&lt;due_date&gt;', $html);
    }

    /**
     * Without any recipients the To line falls back to the "none" label.
     */
    public function test_without_recipients(): void {
        $data = $this->data();
        $data['recipients'] = [];

        $html = (new taskflow_message_preview_preview_renderer())->render($data)['html'];

        $this->assertStringContainsString(get_string('agent_preview_none', 'local_taskflow'), $html);
    }

    /**
     * Empty data yields the empty state.
     */
    public function test_empty_state(): void {
        $preview = (new taskflow_message_preview_preview_renderer())->render([]);

        $this->assertNotNull($preview);
        $this->assertSame(['messageids' => [], 'assignmentids' => []], $preview['payload']);
        $this->assertStringContainsString(
            get_string('agent_preview_empty_message_preview', 'local_taskflow'),
            $preview['html']
        );
    }

    /**
     * The recipient name is escaped; the mail address is shown in angle brackets.
     */
    public function test_escaping(): void {
        $data = $this->data();
        $data['recipients'][0]['fullname'] = '<script>alert(1)</script>';
        $data['subject'] = '<i>subject</i>';

        $html = (new taskflow_message_preview_preview_renderer())->render($data)['html'];

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<i>subject</i>', $html);
        $this->assertStringContainsString('&lt;anna@example.org&gt;', $html);
    }
}
