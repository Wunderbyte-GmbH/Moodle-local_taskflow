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
use local_taskflow\local\requests;
use local_taskflow\local\requests\request_types\types\allowselfextension;
use local_taskflow\local\requests\request_types\types\allowselfnotrelevant;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\skills\create_request_skill;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use local_taskflow\wizard\local_wizard_dependency;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../local_wizard_dependency.php');

/**
 * Gates and mutation of local_taskflow.create_request.
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\skills\create_request_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class create_request_skill_test extends advanced_testcase {
    /** @var \local_taskflow_generator */
    private $generator;

    /** @var \stdClass Owner of the assignment. */
    private \stdClass $employee;

    /** @var \stdClass Unrelated user. */
    private \stdClass $other;

    /** @var int Assignment of the employee. */
    private int $assignmentid = 0;

    /** @var int Assignment of the unrelated user. */
    private int $otherassignmentid = 0;

    /** @var int Rule allowing both self service request types. */
    private int $ruleid = 0;

    /**
     * Setup: engine, standard adapter, one rule that routes both request types to the supervisor.
     */
    protected function setUp(): void {
        parent::setUp();
        local_wizard_dependency::require_installed();
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_taskflow');
        $this->generator->set_config_values('standard');
        \local_taskflow\local\external_adapter\external_api_base::destroy_instance();
        set_config('allowselfnotrelevant', 1, 'local_taskflow');
        set_config('allowselfextension', 1, 'local_taskflow');

        $this->employee = $this->getDataGenerator()->create_user(['firstname' => 'Anna', 'lastname' => 'Muster']);
        $this->other = $this->getDataGenerator()->create_user(['firstname' => 'Otto', 'lastname' => 'Other']);

        $this->ruleid = (int)$this->generator->create_rule([
            'name' => 'Data protection',
            'requests' => [
                'receiver_' . allowselfnotrelevant::SETTINGKEY => 0,
                'receiver_' . allowselfextension::SETTINGKEY => 0,
            ],
        ]);
        $this->assignmentid = $this->create_assignment((int)$this->employee->id);
        $this->otherassignmentid = $this->create_assignment((int)$this->other->id);
    }

    /**
     * Teardown: singletons.
     */
    protected function tearDown(): void {
        parent::tearDown();
        $this->generator->teardown();
    }

    /**
     * Create an assignment for the shared rule and return its id.
     *
     * @param int $userid
     * @return int
     */
    private function create_assignment(int $userid): int {
        global $DB;
        $this->generator->create_user_assignment($userid, $this->ruleid);
        return (int)$DB->get_field(
            'local_taskflow_assignment',
            'id',
            ['userid' => $userid, 'ruleid' => $this->ruleid],
            MUST_EXIST
        );
    }

    /**
     * Grant capabilities through a fresh system role.
     *
     * @param int $userid
     * @param string[] $capabilities
     */
    private function grant(int $userid, array $capabilities): void {
        $roleid = $this->getDataGenerator()->create_role();
        foreach ($capabilities as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, context_system::instance()->id, true);
        }
        role_assign($roleid, $userid, context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Preflight as the given user.
     *
     * @param array $input
     * @param int $userid
     * @return object
     */
    private function preflight(array $input, int $userid) {
        $this->setUser($userid);
        return (new create_request_skill())->preflight($input, context_system::instance()->id, $userid);
    }

    /**
     * The proposal is confirmable and its rows name assignment, type and receiver.
     */
    public function test_confirmable_proposal(): void {
        $this->grant((int)$this->employee->id, [create_request_skill::CAPABILITY]);

        $skill = new create_request_skill();
        $this->setUser((int)$this->employee->id);
        $preflight = $skill->preflight([
            'assignmentid' => $this->assignmentid,
            'type' => allowselfextension::ID,
            'comment' => 'Project until May',
        ], context_system::instance()->id, (int)$this->employee->id);

        $this->assertSame('soft_block', $preflight->status);
        $this->assertContains(create_request_skill::ISSUE_CONFIRM, $preflight->issuecodes);

        $description = $skill->describe_proposed_action($preflight->preparedinput);
        $this->assertIsArray($description);
        $this->assertNotEmpty($description['title']);
        $this->assertNotEmpty($description['summary']);
        $values = array_column($description['rows'], 'value');
        $this->assertContains('#' . $this->assignmentid, $values);
        $this->assertContains('Project until May', $values);
        $this->assertContains('Data protection', $values);
    }

    /**
     * The request row is written and verified.
     */
    public function test_creates_and_verifies_request(): void {
        global $DB;

        $this->grant((int)$this->employee->id, [create_request_skill::CAPABILITY]);
        $preflight = $this->preflight([
            'assignmentid' => $this->assignmentid,
            'type' => allowselfnotrelevant::ID,
            'comment' => 'Not part of my job',
        ], (int)$this->employee->id);
        $this->assertSame('soft_block', $preflight->status);

        $result = (new create_request_skill())->execute(
            $preflight->preparedinput,
            context_system::instance()->id,
            (int)$this->employee->id
        );

        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status']);
        $requestid = (int)$result['resultid'];
        $this->assertGreaterThan(0, $requestid);

        $record = $DB->get_record('local_taskflow_requests', ['id' => $requestid], '*', MUST_EXIST);
        $this->assertSame(allowselfnotrelevant::ID, (int)$record->request);
        $this->assertSame($this->assignmentid, (int)$record->assignmentid);
        $this->assertSame((int)$this->employee->id, (int)$record->userid);
        $this->assertSame(requests::TREATED_STATUS_UNTREATED, (int)$record->treated);
        $this->assertSame('Not part of my job', (string)$record->comment);
        $this->assertSame([], $result['verification']['unverified']);

        $this->assertSame(
            taskflow_preview_renderer_factory::TYPE_REQUEST_LIST,
            $result['preview']['type']
        );
        $this->assertSame([$requestid], $result['preview']['payload']['requestids']);
    }

    /**
     * A second untreated request of the same type is refused, naming the existing one.
     */
    public function test_duplicate_is_rejected(): void {
        $this->grant((int)$this->employee->id, [create_request_skill::CAPABILITY]);
        $input = ['assignmentid' => $this->assignmentid, 'type' => allowselfnotrelevant::ID];

        $preflight = $this->preflight($input, (int)$this->employee->id);
        $result = (new create_request_skill())->execute(
            $preflight->preparedinput,
            context_system::instance()->id,
            (int)$this->employee->id
        );
        $requestid = (int)$result['resultid'];

        $second = $this->preflight($input, (int)$this->employee->id);
        $this->assertSame('hard_block', $second->status);
        $this->assertContains(create_request_skill::ISSUE_DUPLICATE, $second->issuecodes);
        $this->assertStringContainsString('#' . $requestid, $second->issues[0]['message']);

        // The execute() call is guarded as well and never creates a second row.
        $direct = (new create_request_skill())->execute($input, context_system::instance()->id, (int)$this->employee->id);
        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $direct['status']);
        $this->assertContains(create_request_skill::ISSUE_DUPLICATE, $direct['issue_codes']);
    }

    /**
     * A globally disabled type and a rule that forbids the type are both refused.
     */
    public function test_type_must_be_enabled_globally_and_by_the_rule(): void {
        $this->grant((int)$this->employee->id, [create_request_skill::CAPABILITY]);

        set_config('allowselfextension', 0, 'local_taskflow');
        $preflight = $this->preflight([
            'assignmentid' => $this->assignmentid,
            'type' => allowselfextension::ID,
        ], (int)$this->employee->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(create_request_skill::ISSUE_TYPE_DISABLED, $preflight->issuecodes);
        set_config('allowselfextension', 1, 'local_taskflow');

        $closedruleid = (int)$this->generator->create_rule([
            'name' => 'Closed rule',
            'requests' => [
                'receiver_' . allowselfnotrelevant::SETTINGKEY => create_request_skill::RECEIVER_NOT_ALLOWED,
                'receiver_' . allowselfextension::SETTINGKEY => create_request_skill::RECEIVER_NOT_ALLOWED,
            ],
        ]);
        $this->ruleid = $closedruleid;
        $closedassignment = $this->create_assignment((int)$this->employee->id);

        $preflight = $this->preflight([
            'assignmentid' => $closedassignment,
            'type' => allowselfextension::ID,
        ], (int)$this->employee->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(create_request_skill::ISSUE_RULE_DISALLOWS, $preflight->issuecodes);
    }

    /**
     * Requests can only be created for own assignments.
     */
    public function test_scope_is_limited_to_own_assignments(): void {
        global $DB;

        $this->grant((int)$this->employee->id, [create_request_skill::CAPABILITY]);

        $preflight = $this->preflight([
            'assignmentid' => $this->otherassignmentid,
            'type' => allowselfnotrelevant::ID,
        ], (int)$this->employee->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(taskflow_skill_base::ISSUE_SCOPE_DENIED, $preflight->issuecodes);

        // Without the capability even the own assignment is denied and nothing is written.
        $prohibited = $this->getDataGenerator()->create_role();
        assign_capability(
            create_request_skill::CAPABILITY,
            CAP_PROHIBIT,
            $prohibited,
            context_system::instance()->id,
            true
        );
        role_assign($prohibited, (int)$this->other->id, context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->setUser((int)$this->other->id);
        $preflight = (new create_request_skill())->preflight([
            'assignmentid' => $this->otherassignmentid,
            'type' => allowselfnotrelevant::ID,
        ], context_system::instance()->id, (int)$this->other->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(taskflow_skill_base::ISSUE_SCOPE_DENIED, $preflight->issuecodes);
        $this->assertSame(0, $DB->count_records('local_taskflow_requests'));
    }
}
