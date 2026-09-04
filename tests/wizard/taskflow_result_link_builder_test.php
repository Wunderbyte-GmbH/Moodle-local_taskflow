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

namespace local_taskflow\wizard;

use advanced_testcase;
use local_taskflow\local\documentation\documentation_viewer;
use local_taskflow\local\wizard\taskflow\taskflow_result_link_builder;

/**
 * Page URLs and documentation deep links.
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\taskflow_result_link_builder
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class taskflow_result_link_builder_test extends advanced_testcase {
    /**
     * Page URLs point at the taskflow pages with the id parameter.
     */
    public function test_page_urls(): void {
        $builder = taskflow_result_link_builder::class;
        $this->assertStringEndsWith('/local/taskflow/assignment.php?id=4711', $builder::assignment_url(4711));
        $this->assertStringEndsWith('/local/taskflow/editassignment.php?id=4711', $builder::edit_assignment_url(4711));
        $this->assertStringEndsWith('/local/taskflow/editrule.php?id=17', taskflow_result_link_builder::edit_rule_url(17));
        $this->assertStringEndsWith('/local/taskflow/index.php', taskflow_result_link_builder::dashboard_url());
        $this->assertStringEndsWith('/local/taskflow/message_form/editmessage.php', $builder::edit_message_url());
        $this->assertStringEndsWith('/local/taskflow/message_form/editmessage.php?id=5', $builder::edit_message_url(5));
        $this->assertStringEndsWith('/local/taskflow/mycertificates.php?userid=3', $builder::my_certificates_url(3));
        $this->assertStringEndsWith('/user/profile.php?id=3', taskflow_result_link_builder::user_url(3));
    }

    /**
     * Every documentation anchor points at an existing file below docs/.
     */
    public function test_docs_anchor_map_matches_docs_tree(): void {
        $root = documentation_viewer::docs_root();
        $this->assertNotEmpty(taskflow_result_link_builder::DOCS_ANCHORS);
        foreach (taskflow_result_link_builder::DOCS_ANCHORS as $key => $relpath) {
            $this->assertFileExists($root . '/' . $relpath, 'Docs anchor ' . $key);
            $this->assertSame($relpath, taskflow_result_link_builder::docs_anchor($key));
            $url = taskflow_result_link_builder::docs_link($key);
            $this->assertStringContainsString('/local/taskflow/documentation.php?file=', $url);
            $this->assertStringContainsString(urlencode($relpath), $url);
        }
    }

    /**
     * Relative paths are accepted directly; unknown keys and escaping paths yield empty strings.
     */
    public function test_docs_url_validation(): void {
        $this->assertSame('user/rules/02-filters.md', taskflow_result_link_builder::docs_anchor('user/rules/02-filters.md'));
        $this->assertSame('', taskflow_result_link_builder::docs_anchor('does_not_exist'));
        $this->assertSame('', taskflow_result_link_builder::docs_link('does_not_exist'));
        $this->assertSame('', taskflow_result_link_builder::docs_url('../../config.php'));
        $this->assertSame('', taskflow_result_link_builder::docs_url(''));
        $this->assertStringContainsString(
            'file=' . urlencode('user/README.md'),
            taskflow_result_link_builder::docs_url('./user/../user/README.md')
        );
    }

    /**
     * The links block always has page + docs, drops unknown anchors and keeps extras.
     */
    public function test_links_block(): void {
        $links = taskflow_result_link_builder::links(
            taskflow_result_link_builder::assignment_url(1),
            ['assignments_status_lifecycle', 'does_not_exist', 'assignments_status_lifecycle'],
            ['edit' => taskflow_result_link_builder::edit_assignment_url(1), 'page' => 'ignored', 'empty' => '']
        );
        $this->assertStringEndsWith('assignment.php?id=1', $links['page']);
        $this->assertCount(1, $links['docs']);
        $this->assertStringEndsWith('editassignment.php?id=1', $links['edit']);
        $this->assertArrayNotHasKey('empty', $links);

        $empty = taskflow_result_link_builder::links(null);
        $this->assertNull($empty['page']);
        $this->assertSame([], $empty['docs']);
    }
}
