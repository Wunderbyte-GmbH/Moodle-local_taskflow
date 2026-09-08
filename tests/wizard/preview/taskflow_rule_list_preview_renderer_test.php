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
use local_taskflow\local\wizard\taskflow\preview\taskflow_rule_list_preview_renderer;

/**
 * Renderer of the taskflow_rule_list preview (engine-independent).
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\preview\taskflow_rule_list_preview_renderer
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class taskflow_rule_list_preview_renderer_test extends advanced_testcase {
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
     * Data as search_rules_skill delivers it.
     *
     * @return array
     */
    private function data(): array {
        return [
            'rules' => [
                ['id' => 17, 'name' => 'Data protection basics', 'type' => 'unit', 'unitid' => 12,
                    'unitname' => 'Administration', 'userid' => 0, 'isactive' => true,
                    'targettypes' => ['moodlecourse', 'bookingoption'], 'assignments_count' => 42,
                    'edit_url' => 'https://example.org/local/taskflow/editrule.php?id=17'],
                ['id' => 33, 'name' => 'DS for externals (old)', 'type' => 'user', 'unitid' => 0,
                    'unitname' => '', 'userid' => 5, 'isactive' => false,
                    'targettypes' => [], 'assignments_count' => 0,
                    'edit_url' => 'https://example.org/local/taskflow/editrule.php?id=33'],
            ],
            'total' => 61,
            'query' => 'Data',
            'limit' => 2,
        ];
    }

    /**
     * Table with rows, badges, glyphs, links and the "showing N of M" note.
     */
    public function test_render_rows(): void {
        $preview = (new taskflow_rule_list_preview_renderer())->render($this->data());

        $this->assertNotNull($preview);
        $this->assertSame(taskflow_preview_renderer_factory::TYPE_RULE_LIST, $preview['type']);
        $this->assertSame(['ruleids' => [17, 33]], $preview['payload']);

        $html = $preview['html'];
        $this->assertSame(1, substr_count($html, 'taskflow-ai-preview-item'));
        $this->assertStringContainsString(get_string('agent_preview_rule_list_title', 'local_taskflow', 2), $html);
        $this->assertStringContainsString(s(get_string('agent_preview_rule_list_query', 'local_taskflow', 'Data')), $html);
        $this->assertStringContainsString(
            get_string('agent_preview_rule_list_showing', 'local_taskflow', (object)['shown' => 2, 'total' => 61]),
            $html
        );
        $this->assertStringContainsString('Data protection basics', $html);
        $this->assertStringContainsString('editrule.php?id=17', $html);
        $this->assertStringContainsString('editrule.php?id=33', $html);
        $this->assertStringContainsString('Administration', $html);
        $this->assertStringContainsString('>42<', $html);
        $this->assertStringContainsString(get_string('agent_preview_rule_type_unit', 'local_taskflow'), $html);
        $this->assertStringContainsString(get_string('agent_preview_rule_type_user', 'local_taskflow'), $html);
        $this->assertStringContainsString(get_string('moodlecourse', 'local_taskflow'), $html);
        $this->assertStringContainsString(get_string('bookingoption', 'local_taskflow'), $html);
        $this->assertStringContainsString(get_string('activityactive', 'local_taskflow'), $html);
        $this->assertStringContainsString(get_string('activityinactive', 'local_taskflow'), $html);
        $this->assertStringContainsString('✓', $html);
        $this->assertStringContainsString('✗', $html);
        $this->assertStringContainsString('/local/taskflow/index.php', $html);
        $this->assertDebuggingNotCalled();
    }

    /**
     * No rules: empty-state text plus dashboard link, empty payload.
     */
    public function test_empty_state(): void {
        $preview = (new taskflow_rule_list_preview_renderer())->render(['rules' => [], 'total' => 0, 'query' => 'x']);

        $this->assertNotNull($preview);
        $this->assertSame(['ruleids' => []], $preview['payload']);
        $this->assertStringContainsString(get_string('agent_preview_empty_rule_list', 'local_taskflow'), $preview['html']);
        $this->assertStringContainsString(get_string('agent_preview_open_dashboard', 'local_taskflow'), $preview['html']);
        $this->assertStringNotContainsString('<table', $preview['html']);
    }

    /**
     * Rule and unit names are escaped; the query too.
     */
    public function test_escaping(): void {
        $data = $this->data();
        $data['rules'][0]['name'] = '<script>alert(1)</script>';
        $data['rules'][0]['unitname'] = '<b>unit</b>';
        $data['query'] = '<i>q</i>';

        // Names go through format_string(): the tag is removed, the text survives.
        $html = (new taskflow_rule_list_preview_renderer())->render($data)['html'];
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('alert(1)', $html);
        $this->assertStringNotContainsString('<b>unit</b>', $html);
        $this->assertStringNotContainsString('<i>q</i>', $html);
    }

    /**
     * Rows without an id are skipped; the render language is honoured.
     */
    public function test_language_and_invalid_rows(): void {
        $data = $this->data();
        $data['rules'][] = ['name' => 'no id'];

        $preview = (new taskflow_rule_list_preview_renderer())->render($data, 'en');
        $this->assertSame(['ruleids' => [17, 33]], $preview['payload']);
        $this->assertStringNotContainsString('no id', $preview['html']);
        $this->assertStringContainsString(
            get_string_manager()->get_string('agent_preview_rule_type_unit', 'local_taskflow', null, 'en'),
            $preview['html']
        );
    }
}
