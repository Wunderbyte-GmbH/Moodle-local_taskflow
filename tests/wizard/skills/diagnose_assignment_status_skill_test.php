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

namespace local_taskflow\wizard\skills;

use advanced_testcase;
use context_system;
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\assignments\assignment;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\skills\diagnose_assignment_status_skill;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use local_taskflow\wizard\local_wizard_dependency;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../local_wizard_dependency.php');

/**
 * Explanations, live target checks and settings of local_taskflow.diagnose_assignment_status.
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\skills\diagnose_assignment_status_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class diagnose_assignment_status_skill_test extends advanced_testcase {
    /** @var \local_taskflow_generator */
    private $generator;

    /** @var \stdClass */
    private \stdClass $employee;

    /** @var \stdClass */
    private \stdClass $stranger;

    /** @var \stdClass */
    private \stdClass $course;

    /** @var int */
    private int $ruleid = 0;

    /** @var int */
    private int $assignmentid = 0;

    /**
     * Setup: one overdue assignment with a course target and keepchanges set.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        local_wizard_dependency::require_installed();
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_taskflow');
        $this->generator->set_config_values('standard');
        $this->generator->create_custom_profile_fields(['supervisor', 'deputy']);
        \local_taskflow\local\external_adapter\external_api_base::destroy_instance();

        $this->employee = $this->getDataGenerator()->create_user(['firstname' => 'Anna', 'lastname' => 'Muster']);
        $this->stranger = $this->getDataGenerator()->create_user();
        $this->course = $this->getDataGenerator()->create_course(['fullname' => 'Data protection basics']);

        $this->ruleid = (int)$this->generator->create_rule(['name' => 'Data protection', 'extensionperiod' => WEEKSECS]);
        $this->generator->create_user_assignment((int)$this->employee->id, $this->ruleid);
        $this->assignmentid = (int)$DB->get_field(
            'local_taskflow_assignment',
            'id',
            ['userid' => $this->employee->id, 'ruleid' => $this->ruleid],
            MUST_EXIST
        );
        $DB->update_record('local_taskflow_assignment', (object)[
            'id' => $this->assignmentid,
            'status' => assignment_status_facade::get_status_identifier('overdue'),
            'duedate' => time() - 2 * DAYSECS,
            'overduecounter' => 2,
            'keepchanges' => 1,
            'targets' => json_encode([
                ['targettype' => 'moodlecourse', 'targetid' => (int)$this->course->id, 'completionstatus' => 1,
                    'completebeforenext' => 0, 'sortorder' => 1, 'actiontype' => 'enroll'],
            ]),
        ]);
        assignment::destroy_instance();
    }

    /**
     * Teardown: singletons.
     */
    protected function tearDown(): void {
        parent::tearDown();
        $this->generator->teardown();
    }

    /**
     * Preflight + execute as the given user.
     *
     * @param array $input
     * @param int $userid
     * @return array{preflight:object,result:array|null}
     */
    private function run_skill(array $input, int $userid): array {
        $skill = new diagnose_assignment_status_skill();
        $preflight = $skill->preflight($input, context_system::instance()->id, $userid);
        $result = null;
        if ($preflight->status === 'pass') {
            $result = $skill->execute($preflight->preparedinput, context_system::instance()->id, $userid);
        }
        return ['preflight' => $preflight, 'result' => $result];
    }

    /**
     * The overdue explanation names the adhoc task and keepchanges is reported.
     */
    public function test_overdue_explanation_names_the_check_task(): void {
        $run = $this->run_skill(['assignmentid' => $this->assignmentid], (int)get_admin()->id);
        $this->assertSame('pass', $run['preflight']->status);
        $result = $run['result'];
        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status']);
        $this->assertSame($this->assignmentid, $result['resultid']);
        $this->assertSame(assignment_status_facade::get_status_identifier('overdue'), $result['assignment_status']['id']);
        $this->assertTrue($result['assignment_status']['active']);
        $this->assertTrue($result['assignment']['overdue']);
        $this->assertSame(2, $result['counters']['overduecounter']);

        $codes = array_column($result['explanations'], 'code');
        $this->assertContains('duedate_passed', $codes);
        $this->assertContains('keepchanges', $codes);

        $texts = implode("\n", array_column($result['explanations'], 'text'));
        $this->assertStringContainsString('check_assignment_status', $texts);

        $settings = array_column($result['settings_in_effect'], 'value', 'name');
        $this->assertArrayHasKey('assignment/keepchanges', $settings);
        $this->assertTrue($settings['assignment/keepchanges']);
        $this->assertSame(WEEKSECS, $settings['rule/extensionperiod']);
        $this->assertStringContainsString('check_assignment_status', $result['observation_full']);

        $preview = (new diagnose_assignment_status_skill())->get_result_preview(
            $result,
            context_system::instance()->id,
            (int)get_admin()->id
        );
        $this->assertSame(taskflow_preview_renderer_factory::TYPE_DIAGNOSTIC_CHECKLIST, $preview['type']);
        $this->assertStringContainsString('assignment.php?id=' . $this->assignmentid, $preview['html']);
        $this->assertSame([$this->assignmentid], $preview['payload']['assignmentids']);
    }

    /**
     * A course target is probed live and the stored completion state is compared with it.
     */
    public function test_target_completion_is_probed_live(): void {
        $run = $this->run_skill(['assignmentid' => $this->assignmentid], (int)get_admin()->id);
        $result = $run['result'];

        $this->assertCount(1, $result['targets']);
        $target = $result['targets'][0];
        $this->assertSame('moodlecourse', $target['targettype']);
        $this->assertSame((int)$this->course->id, $target['targetid']);
        $this->assertTrue($target['live_checked']);
        $this->assertTrue($target['completed_stored']);
        // The course has no completion criteria, so the live check contradicts the stored state.
        $this->assertFalse($target['completed_now']);
        $this->assertContains('target_mismatch', array_column($result['explanations'], 'code'));
    }

    /**
     * The excluded statuses of the tuines adapter and its prolonged state are reported.
     */
    public function test_adapter_settings_are_reported(): void {
        $this->generator->set_config_values('tuines');
        \local_taskflow\local\external_adapter\external_api_base::destroy_instance();

        $run = $this->run_skill(['assignmentid' => $this->assignmentid], (int)get_admin()->id);
        $result = $run['result'];

        $settings = array_column($result['settings_in_effect'], 'value', 'name');
        $this->assertSame([3, 7], $settings['taskflowadapter_tuines/excludestatus']);
        $this->assertTrue($settings['taskflowadapter_tuines/usingprolongedstate']);

        $codes = array_column($result['explanations'], 'code');
        $this->assertContains('excludestatus', $codes);
        $this->assertContains('prolongedstate', $codes);
        $texts = implode("\n", array_column($result['explanations'], 'text'));
        $this->assertStringContainsString('tuines', $texts);
    }

    /**
     * The assignee reads the diagnosis, a foreign user does not.
     */
    public function test_scope(): void {
        $run = $this->run_skill(['assignmentid' => $this->assignmentid], (int)$this->employee->id);
        $this->assertSame('pass', $run['preflight']->status);
        $this->assertSame('self', $run['result']['scope']);

        $run = $this->run_skill(['assignmentid' => $this->assignmentid], (int)$this->stranger->id);
        $this->assertSame('hard_block', $run['preflight']->status);
        $this->assertContains(taskflow_skill_base::ISSUE_SCOPE_DENIED, $run['preflight']->issuecodes);

        $run = $this->run_skill(['assignmentid' => 999999], (int)get_admin()->id);
        $this->assertSame('hard_block', $run['preflight']->status);
        $this->assertContains(taskflow_skill_base::ISSUE_ASSIGNMENT_NOT_FOUND, $run['preflight']->issuecodes);

        $run = $this->run_skill([], (int)get_admin()->id);
        $this->assertSame('hard_block', $run['preflight']->status);
        $this->assertContains('VALIDATION_ERROR', $run['preflight']->issuecodes);
    }
}
