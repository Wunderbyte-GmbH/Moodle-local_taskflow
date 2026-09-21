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
use local_taskflow\local\wizard\taskflow\preview\taskflow_catalog_preview_renderer;
use local_taskflow\local\wizard\taskflow\preview\taskflow_message_template_list_preview_renderer;

/**
 * No documentation comment of a preview template may leak into the rendered card.
 *
 * Baseline run 8 (2026-09-16, preview audit P6, Moodle-local_taskflow#467): 11 of 84 result previews
 * started with `], "emptyhtml": "", "haslinks": true, ...` because a `}}` inside the example
 * context of the template comment closed the `{{! ... }}` block early.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_taskflow\local\wizard\taskflow\preview\taskflow_catalog_preview_renderer
 * @covers     \local_taskflow\local\wizard\taskflow\preview\taskflow_message_template_list_preview_renderer
 */
final class taskflow_preview_template_comment_test extends advanced_testcase {
    /**
     * Setup.
     */
    protected function setUp(): void {
        parent::setUp();
        if (class_exists('\\tool_mocktesttime\\time_mock')) {
            \tool_mocktesttime\time_mock::reset_mock_time();
        }
        $this->resetAfterTest();
    }

    /**
     * The rendered HTML starts with the card and contains no fragment of the template comment.
     *
     * @param string $html Rendered preview HTML.
     */
    private function assert_no_comment_leak(string $html): void {
        $this->assertStringStartsWith('<div', ltrim($html));
        $this->assertStringNotContainsString('"emptyhtml"', $html);
        $this->assertStringNotContainsString('"haslinks"', $html);
        $this->assertStringNotContainsString('Example context', $html);
    }

    /**
     * Catalog card (list_settings, list_rule_properties).
     */
    public function test_catalog_template_comment_does_not_leak(): void {
        $block = (new taskflow_catalog_preview_renderer())->render([
            'title' => 'Settings',
            'sections' => [['title' => 'local_taskflow', 'open' => true, 'rows' => [
                ['label' => 'external_api_option', 'code' => true, 'value' => 'tuines', 'hint' => 'Adapter'],
            ]]],
            'links' => [['url' => 'https://example.com/settings', 'label' => 'Open settings']],
        ], 'en', ['settingids' => [1]]);
        $this->assertNotNull($block);
        $this->assert_no_comment_leak((string)$block['html']);
    }

    /**
     * Message template list card (search_message_templates).
     */
    public function test_message_template_list_template_comment_does_not_leak(): void {
        $preview = (new taskflow_message_template_list_preview_renderer())->render(
            ['templates' => [], 'total' => 0, 'query' => 'x']
        );
        $this->assertNotNull($preview);
        $this->assert_no_comment_leak((string)$preview['html']);
    }

    /**
     * Every wizard preview template keeps its documentation comment closed only at the intended end.
     */
    public function test_no_template_comment_contains_a_premature_close(): void {
        $leaks = [];
        foreach (glob(__DIR__ . '/../../../templates/wizard/preview_*.mustache') as $file) {
            $source = file_get_contents($file);
            // Mustache closes a {{! ... }} comment at the FIRST "}}". Our documentation comments end
            // with "}}" on a line of its own, so a comment whose body does not end with a newline
            // was closed early by a "}}" inside the body (e.g. nested JSON in the example context).
            if (preg_match_all('/\{\{!(.*?)\}\}/s', $source, $matches)) {
                foreach ($matches[1] as $body) {
                    if (!str_ends_with($body, "\n")) {
                        $leaks[] = basename($file);
                        break;
                    }
                }
            }
        }
        $this->assertSame([], $leaks, 'template comments closed early in: ' . implode(', ', $leaks));
    }
}
