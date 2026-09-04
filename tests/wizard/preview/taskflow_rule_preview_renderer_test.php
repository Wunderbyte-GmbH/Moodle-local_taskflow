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
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\preview\taskflow_rule_preview_renderer;

/**
 * Renderer of the taskflow_rule preview (engine-independent).
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\preview\taskflow_rule_preview_renderer
 * @covers     \local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_base
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class taskflow_rule_preview_renderer_test extends advanced_testcase {
    /**
     * Setup.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Data as get_rule_details_skill delivers it.
     *
     * @param array $overrides
     * @return array
     */
    private function data(array $overrides = []): array {
        $completed = (int)assignment_status_facade::get_status_identifier('completed');
        $overdue = (int)assignment_status_facade::get_status_identifier('overdue');
        $data = [
            'rule' => [
                'id' => 17,
                'name' => 'Data protection basics',
                'description' => 'Mandatory for all staff',
                'type' => 'unit',
                'unitid' => 12,
                'unitname' => 'Administration',
                'userid' => 0,
                'enabled' => true,
                'isactive' => true,
                'duedatetype' => 'duration',
                'duration' => 90 * DAYSECS,
                'fixeddate' => 0,
                'extensionperiod' => 14 * DAYSECS,
                'activationdelay' => 0,
                'cyclicvalidation' => true,
                'cyclicduration' => 365 * DAYSECS,
                'inheritance' => true,
                'recursive' => false,
            ],
            'filters' => [
                ['filtertype' => 'user_profile_field', 'field' => 'contract', 'operator' => 'not_equals',
                    'operator_label' => 'does not equal', 'value' => 'external', 'date' => 0],
            ],
            'targets' => [
                ['targettype' => 'moodlecourse', 'targetid' => 23, 'name' => 'Data protection course',
                    'completebeforenext' => true, 'sortorder' => 2, 'actiontype' => 'enroll'],
                ['targettype' => 'competency', 'targetid' => 7, 'name' => 'GDPR proof',
                    'completebeforenext' => false, 'sortorder' => 2, 'actiontype' => 'enroll'],
            ],
            'messages' => [
                ['id' => 12, 'name' => 'Reminder 7d', 'class' => 'standard', 'exists' => true],
                ['id' => 99, 'name' => '', 'class' => '', 'exists' => false],
            ],
            'requests' => [
                'allowselfextension' => 'not_allowed',
                'allowselfnotrelevant' => 'supervisor',
                'allowuploadevidence' => 'hr',
            ],
            'assignments_by_status' => [
                ['status' => $completed, 'label' => 'x', 'count' => 30],
                ['status' => $overdue, 'label' => 'x', 'count' => 4],
            ],
            'assignments_total' => 34,
            'pending_update_rule_tasks' => 1,
        ];
        // Scalars of 'rule' are merged; every other key (lists) is replaced as a whole.
        if (isset($overrides['rule'])) {
            $data['rule'] = array_replace($data['rule'], (array)$overrides['rule']);
            unset($overrides['rule']);
        }
        return array_replace($data, $overrides);
    }

    /**
     * Full card: type, payload, badges, sections, statistics, pending task and links.
     */
    public function test_render_full_card(): void {
        $completed = (int)assignment_status_facade::get_status_identifier('completed');
        $overdue = (int)assignment_status_facade::get_status_identifier('overdue');

        $preview = (new taskflow_rule_preview_renderer())->render($this->data());

        $this->assertNotNull($preview);
        $this->assertSame(taskflow_preview_renderer_factory::TYPE_RULE, $preview['type']);
        $this->assertSame(['ruleids' => [17]], $preview['payload']);

        $html = $preview['html'];
        $this->assertSame(1, substr_count($html, 'taskflow-ai-preview-item'));
        $this->assertStringContainsString('data-preview-type="taskflow_rule"', $html);
        $this->assertStringContainsString(get_string('agent_preview_rule_heading', 'local_taskflow', 17), $html);
        $this->assertStringContainsString('Data protection basics', $html);
        $this->assertStringContainsString('Mandatory for all staff', $html);
        $this->assertStringContainsString('editrule.php?id=17', $html);
        $this->assertStringContainsString(get_string('activityactive', 'local_taskflow'), $html);
        $this->assertStringContainsString('badge bg-success', $html);
        $this->assertStringContainsString(get_string('agent_preview_rule_type_unit', 'local_taskflow'), $html);
        $this->assertStringContainsString('Administration', $html);
        $this->assertStringContainsString(get_string('agent_preview_inheritance', 'local_taskflow'), $html);

        $days = get_string('agent_preview_days', 'local_taskflow');
        $this->assertStringContainsString(
            get_string('agent_preview_duedate_duration', 'local_taskflow', '90 ' . $days),
            $html
        );
        $this->assertStringContainsString('14 ' . $days, $html);
        $this->assertStringContainsString(
            get_string('agent_preview_cyclic_every', 'local_taskflow', '365 ' . $days),
            $html
        );

        $this->assertStringContainsString('contract', $html);
        $this->assertStringContainsString('does not equal', $html);
        $this->assertStringContainsString('external', $html);
        $this->assertStringContainsString(get_string('moodlecourse', 'local_taskflow'), $html);
        $this->assertStringContainsString('Data protection course', $html);
        $this->assertStringContainsString(get_string('agent_preview_complete_before_next', 'local_taskflow'), $html);
        $this->assertStringContainsString('GDPR proof', $html);
        $this->assertStringContainsString('Reminder 7d', $html);
        $this->assertStringContainsString(get_string('agent_preview_message_missing', 'local_taskflow'), $html);
        $this->assertStringContainsString(get_string('agent_preview_request_not_allowed', 'local_taskflow'), $html);
        $this->assertStringContainsString(get_string('agent_preview_request_hr', 'local_taskflow'), $html);

        $this->assertStringContainsString(assignment_status_facade::get_specific_names($completed), $html);
        $this->assertStringContainsString(assignment_status_facade::get_specific_names($overdue), $html);
        $this->assertStringContainsString('bg-danger', $html);
        $this->assertStringContainsString('30', $html);
        $this->assertStringContainsString(get_string('agent_preview_pending_update_rule', 'local_taskflow', 1), $html);
        $this->assertStringContainsString('bg-warning', $html);
        $this->assertStringContainsString('/local/taskflow/documentation.php?file=', $html);
        $this->assertStringContainsString(get_string('agent_preview_open_rule', 'local_taskflow'), $html);
    }

    /**
     * Inactive rule with fixed date, personal scope and no sections.
     */
    public function test_render_inactive_fixed_date_minimal(): void {
        $data = $this->data([
            'rule' => [
                'type' => 'user', 'unitid' => 0, 'unitname' => '', 'userid' => 5, 'isactive' => false,
                'duedatetype' => 'fixeddate', 'fixeddate' => 1900000000, 'cyclicvalidation' => false,
                'inheritance' => false,
            ],
            'filters' => [],
            'targets' => [],
            'messages' => [],
            'requests' => [],
            'assignments_by_status' => [],
            'assignments_total' => 0,
            'pending_update_rule_tasks' => 0,
        ]);

        $preview = (new taskflow_rule_preview_renderer())->render($data);
        $this->assertNotNull($preview);
        $html = $preview['html'];
        $this->assertStringContainsString(get_string('activityinactive', 'local_taskflow'), $html);
        $this->assertStringContainsString('badge bg-secondary', $html);
        $this->assertStringContainsString(get_string('agent_preview_rule_type_user', 'local_taskflow'), $html);
        $fixed = userdate(1900000000, get_string('strftimedate', 'langconfig'));
        $this->assertStringContainsString(get_string('agent_preview_duedate_fixed', 'local_taskflow', $fixed), $html);
        $this->assertStringContainsString(get_string('agent_preview_none', 'local_taskflow'), $html);
        $this->assertStringNotContainsString(get_string('agent_preview_pending_tasks', 'local_taskflow'), $html);
        $this->assertDebuggingNotCalled();
    }

    /**
     * Rule, unit and target names are escaped.
     */
    public function test_escaping(): void {
        $preview = (new taskflow_rule_preview_renderer())->render($this->data([
            'rule' => ['name' => '<script>alert(1)</script>', 'unitname' => '<b>unit</b>'],
            'targets' => [['name' => '<img src=x onerror=alert(2)>']],
            'filters' => [['value' => '<i>x</i>']],
        ]));

        // Names go through format_string(): the tag is removed, the text survives.
        $html = $preview['html'];
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('alert(1)', $html);
        $this->assertStringNotContainsString('<b>unit</b>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<i>x</i>', $html);
    }

    /**
     * Output language is honoured for status labels and plugin strings.
     */
    public function test_language_forced(): void {
        $completed = (int)assignment_status_facade::get_status_identifier('completed');
        $renderer = new taskflow_rule_preview_renderer();

        $en = $renderer->render($this->data(), 'en')['html'];
        $this->assertStringContainsString(assignment_status_facade::get_specific_names($completed, 'en'), $en);
        $this->assertStringContainsString(
            get_string_manager()->get_string('agent_preview_filters', 'local_taskflow', null, 'en'),
            $en
        );

        if (get_string_manager()->translation_exists('de', false)) {
            $de = $renderer->render($this->data(), 'de')['html'];
            $this->assertStringContainsString(
                get_string_manager()->get_string('agent_preview_filters', 'local_taskflow', null, 'de'),
                $de
            );
        }
    }

    /**
     * Data without a rule id is not renderable; minimal data renders without debugging.
     */
    public function test_unusable_and_minimal_data(): void {
        $renderer = new taskflow_rule_preview_renderer();
        $this->assertNull($renderer->render([]));
        $this->assertNull($renderer->render(['rule' => ['name' => 'no id']]));

        $preview = $renderer->render(['rule' => ['id' => 3]]);
        $this->assertNotNull($preview);
        $this->assertSame(['ruleids' => [3]], $preview['payload']);
        $this->assertStringContainsString('editrule.php?id=3', $preview['html']);
        $this->assertDebuggingNotCalled();
    }
}
