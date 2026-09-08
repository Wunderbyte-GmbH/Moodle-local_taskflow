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
use local_taskflow\local\history\history;
use local_taskflow\local\requests;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\skills\get_assignment_details_skill;
use local_taskflow\local\wizard\taskflow\taskflow_permission_resolver;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use local_taskflow\wizard\local_wizard_dependency;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../local_wizard_dependency.php');

/**
 * Scope and payload of local_taskflow.get_assignment_details.
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\skills\get_assignment_details_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class get_assignment_details_skill_test extends advanced_testcase {
    /** @var \local_taskflow_generator */
    private $generator;

    /** @var \stdClass */
    private \stdClass $supervisor;

    /** @var \stdClass */
    private \stdClass $employee;

    /** @var \stdClass */
    private \stdClass $stranger;

    /** @var \stdClass */
    private \stdClass $course;

    /** @var \stdClass */
    private \stdClass $course2;

    /** @var int */
    private int $ruleid = 0;

    /** @var int */
    private int $assignmentid = 0;

    /**
     * Setup: standard adapter, one assignment with two course targets, history, chat and a request.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        // Pin the mocked clock of tool_mocktesttime to now: other suites advance it and never reset it.
        if (class_exists('\\tool_mocktesttime\\time_mock')) {
            \tool_mocktesttime\time_mock::reset_mock_time();
        }
        local_wizard_dependency::require_installed();
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_taskflow');
        $this->generator->set_config_values('standard');
        $fields = $this->generator->create_custom_profile_fields(['supervisor', 'deputy']);
        \local_taskflow\local\external_adapter\external_api_base::destroy_instance();
        set_config('allowinternalcommunication', 1, 'local_taskflow');

        $this->supervisor = $this->getDataGenerator()->create_user(['firstname' => 'Emily', 'lastname' => 'Smith']);
        $this->employee = $this->getDataGenerator()->create_user(['firstname' => 'Anna', 'lastname' => 'Muster']);
        $this->stranger = $this->getDataGenerator()->create_user();
        $DB->insert_record('user_info_data', (object)[
            'userid' => $this->employee->id,
            'fieldid' => $fields['supervisor'],
            'data' => (string)$this->supervisor->id,
            'dataformat' => 0,
        ]);

        $this->course = $this->getDataGenerator()->create_course(['fullname' => 'Data protection basics']);
        $this->course2 = $this->getDataGenerator()->create_course(['fullname' => 'First aid refresher']);
        $this->ruleid = (int)$this->generator->create_rule(['name' => 'Rule <b>Detail</b>']);
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
            'targets' => json_encode([
                ['targettype' => 'moodlecourse', 'targetid' => (int)$this->course->id, 'completionstatus' => 1,
                    'completebeforenext' => 0, 'sortorder' => 1, 'actiontype' => 'enroll'],
                ['targettype' => 'moodlecourse', 'targetid' => (int)$this->course2->id, 'completionstatus' => 0,
                    'completebeforenext' => 0, 'sortorder' => 2, 'actiontype' => 'enroll'],
            ]),
        ]);
        assignment::destroy_instance();

        history::log(
            $this->assignmentid,
            (int)$this->employee->id,
            history::TYPE_MANUAL_CHANGE,
            ['status' => 'x'],
            null,
            'Manual note'
        );
        $DB->insert_record('local_taskflow_int_com', (object)[
            'assignmentid' => $this->assignmentid,
            'message' => '<p>Can I get an extension?</p>',
            'usermodified' => $this->employee->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $DB->insert_record('local_taskflow_requests', (object)[
            'request' => \local_taskflow\local\requests\request_types\types\allowselfextension::ID,
            'userid' => $this->employee->id,
            'assignmentid' => $this->assignmentid,
            'status' => 0,
            'usermodified' => $this->employee->id,
            'timecreated' => time(),
            'timemodified' => time(),
            'treated' => requests::TREATED_STATUS_UNTREATED,
            'comment' => 'Sick leave',
        ]);
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
        $skill = new get_assignment_details_skill();
        $preflight = $skill->preflight($input, context_system::instance()->id, $userid);
        $result = null;
        if ($preflight->status === 'pass') {
            $result = $skill->execute($preflight->preparedinput, context_system::instance()->id, $userid);
        }
        return ['preflight' => $preflight, 'result' => $result];
    }

    /**
     * The assignee reads the full payload (self scope, no edit link).
     */
    public function test_assignee_gets_full_payload(): void {
        $run = $this->run_skill(['assignmentid' => $this->assignmentid], (int)$this->employee->id);
        $this->assertSame('pass', $run['preflight']->status);
        $result = $run['result'];

        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status']);
        $this->assertSame($this->assignmentid, $result['resultid']);
        $assignment = $result['assignment'];
        $this->assertSame($this->assignmentid, $assignment['id']);
        $this->assertSame((int)$this->employee->id, $assignment['userid']);
        $this->assertSame('Anna Muster', $assignment['fullname']);
        $this->assertSame($this->ruleid, $assignment['ruleid']);
        $this->assertSame('Rule <b>Detail</b>', $assignment['rulename']);
        $this->assertSame(assignment_status_facade::get_status_identifier('overdue'), $assignment['status']);
        $this->assertSame(assignment_status_facade::get_specific_names($assignment['status']), $assignment['statuslabel']);
        $this->assertTrue($assignment['overdue']);
        $this->assertSame(taskflow_permission_resolver::SCOPE_SELF, $assignment['scope']);
        $this->assertFalse($assignment['caneditassignment']);
        $this->assertSame((int)$this->supervisor->id, $assignment['supervisor']['id']);
        $this->assertSame('Emily Smith', $assignment['supervisor']['fullname']);

        $this->assertCount(2, $result['targets']);
        $this->assertSame(1, $result['targets_done']);
        $this->assertSame('Data protection basics', $result['targets'][0]['name']);
        $this->assertTrue($result['targets'][0]['completed']);
        $this->assertFalse($result['targets'][1]['completed']);
        $this->assertStringContainsString('course/view.php?id=' . $this->course2->id, $result['targets'][1]['url']);

        $this->assertCount(1, $result['open_requests']);
        $this->assertSame(1, $result['requests_total']);
        $this->assertSame('Sick leave', $result['open_requests'][0]['comment']);
        $this->assertSame(
            \local_taskflow\local\requests\request_types\types\allowselfextension::ID,
            $result['open_requests'][0]['type']
        );

        $this->assertNotEmpty($result['history']);
        $manual = array_values(array_filter(
            $result['history'],
            static fn(array $entry): bool => $entry['type'] === history::TYPE_MANUAL_CHANGE
        ));
        $this->assertCount(1, $manual);
        $this->assertSame('Manual note', $manual[0]['annotation']);
        $this->assertSame(get_string('status:manualchange', 'local_taskflow'), $manual[0]['typelabel']);

        $this->assertTrue($result['chat_enabled']);
        $this->assertSame(1, $result['chat_total']);
        $this->assertSame('Can I get an extension?', $result['chat_preview'][0]['text']);

        // The generator queued a check_assignment_status adhoc task for this assignment.
        $this->assertNotEmpty($result['pending_tasks']);
        $this->assertContains('check_assignment_status', array_column($result['pending_tasks'], 'name'));

        $this->assertSame('standard', $result['adapter']['name']);
        $this->assertFalse($result['adapter']['usingprolongedstate']);
        $this->assertStringContainsString('assignment.php?id=' . $this->assignmentid, $result['links']['page']);
        $this->assertArrayNotHasKey('edit', $result['links']);
        $this->assertNotEmpty($result['links']['docs']);
        $this->assertStringContainsString('Manual note', $result['observation_full']);
        $this->assertStringContainsString('First aid refresher', $result['observation_full']);

        $preview = (new get_assignment_details_skill())->get_result_preview(
            $result,
            context_system::instance()->id,
            (int)$this->employee->id
        );
        $this->assertSame(taskflow_preview_renderer_factory::TYPE_ASSIGNMENT, $preview['type']);
        $this->assertStringContainsString('assignment.php?id=' . $this->assignmentid, $preview['html']);
        $this->assertStringContainsString('aria-valuenow="1"', $preview['html']);
        $this->assertStringContainsString('Detail', $preview['html']);
        $this->assertStringNotContainsString('<b>Detail</b>', $preview['html']);
        $this->assertSame([$this->assignmentid], $preview['payload']['assignmentids']);
    }

    /**
     * Chat is omitted when internal communication is disabled.
     */
    public function test_chat_hidden_when_internal_communication_disabled(): void {
        set_config('allowinternalcommunication', 0, 'local_taskflow');
        $run = $this->run_skill(['assignmentid' => $this->assignmentid, 'historylimit' => 0], (int)$this->employee->id);
        $this->assertFalse($run['result']['chat_enabled']);
        $this->assertSame([], $run['result']['chat_preview']);
        $this->assertSame([], $run['result']['history']);
    }

    /**
     * A foreign user is denied in preflight and in execute; unknown ids are reported.
     */
    public function test_foreign_user_is_denied(): void {
        $run = $this->run_skill(['assignmentid' => $this->assignmentid], (int)$this->stranger->id);
        $this->assertSame('hard_block', $run['preflight']->status);
        $this->assertContains(taskflow_skill_base::ISSUE_SCOPE_DENIED, $run['preflight']->issuecodes);
        $this->assertNull($run['result']);

        $result = (new get_assignment_details_skill())->execute(
            ['assignmentid' => $this->assignmentid],
            context_system::instance()->id,
            (int)$this->stranger->id
        );
        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $result['status']);
        $this->assertContains(taskflow_skill_base::ISSUE_SCOPE_DENIED, $result['issue_codes']);
        $this->assertStringNotContainsString('Anna', json_encode($result, JSON_UNESCAPED_UNICODE));

        $run = $this->run_skill(['assignmentid' => 999999], (int)get_admin()->id);
        $this->assertSame('hard_block', $run['preflight']->status);
        $this->assertContains(taskflow_skill_base::ISSUE_ASSIGNMENT_NOT_FOUND, $run['preflight']->issuecodes);

        $run = $this->run_skill([], (int)get_admin()->id);
        $this->assertSame('hard_block', $run['preflight']->status);
        $this->assertContains('VALIDATION_ERROR', $run['preflight']->issuecodes);
    }

    /**
     * Admin and supervisor read the assignment with an edit link.
     */
    public function test_admin_and_supervisor_get_edit_link(): void {
        $run = $this->run_skill(['assignmentid' => $this->assignmentid], (int)get_admin()->id);
        $this->assertSame('pass', $run['preflight']->status);
        $this->assertSame(taskflow_permission_resolver::SCOPE_ADMIN, $run['result']['assignment']['scope']);
        $this->assertTrue($run['result']['assignment']['caneditassignment']);
        $this->assertStringContainsString('editassignment.php?id=' . $this->assignmentid, $run['result']['links']['edit']);

        $run = $this->run_skill(['assignmentid' => (string)$this->assignmentid], (int)$this->supervisor->id);
        $this->assertSame('pass', $run['preflight']->status);
        $this->assertSame(taskflow_permission_resolver::SCOPE_SUPERVISOR, $run['result']['assignment']['scope']);
        $this->assertArrayHasKey('edit', $run['result']['links']);
    }
}
