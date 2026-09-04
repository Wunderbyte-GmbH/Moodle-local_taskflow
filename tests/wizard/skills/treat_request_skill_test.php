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
use local_taskflow\local\history\history;
use local_taskflow\local\requests;
use local_taskflow\local\requests\request_types\types\allowselfextension;
use local_taskflow\local\requests\request_types\types\allowselfnotrelevant;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\skills\treat_request_skill;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use local_taskflow\wizard\local_wizard_dependency;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../local_wizard_dependency.php');

/**
 * Receiver scope, decision handling and verification of local_taskflow.treat_request.
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\skills\treat_request_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class treat_request_skill_test extends advanced_testcase {
    /** @var \local_taskflow_generator */
    private $generator;

    /** @var array<string,int> Profile field ids by shortname. */
    private array $fields = [];

    /** @var \stdClass Supervisor of the employee. */
    private \stdClass $supervisor;

    /** @var \stdClass Owner of the assignment. */
    private \stdClass $employee;

    /** @var \stdClass Unrelated user holding treatrequests. */
    private \stdClass $stranger;

    /** @var int Assignment of the employee. */
    private int $assignmentid = 0;

    /** @var int Open not-relevant request. */
    private int $notrelevantrequest = 0;

    /** @var int Open extension request. */
    private int $extensionrequest = 0;

    /**
     * Setup: engine, standard adapter, supervisor mapping, one assignment, two open requests.
     */
    protected function setUp(): void {
        parent::setUp();
        local_wizard_dependency::require_installed();
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_taskflow');
        $this->generator->set_config_values('standard');
        $this->fields = $this->generator->create_custom_profile_fields(['supervisor', 'deputy']);
        \local_taskflow\local\external_adapter\external_api_base::destroy_instance();

        $this->supervisor = $this->getDataGenerator()->create_user(['firstname' => 'Emily', 'lastname' => 'Smith']);
        $this->employee = $this->getDataGenerator()->create_user(['firstname' => 'Anna', 'lastname' => 'Muster']);
        $this->stranger = $this->getDataGenerator()->create_user(['firstname' => 'Otto', 'lastname' => 'Other']);
        $this->set_profile((int)$this->employee->id, 'supervisor', (string)$this->supervisor->id);

        $ruleid = (int)$this->generator->create_rule(['name' => 'Data protection']);
        $this->generator->create_user_assignment((int)$this->employee->id, $ruleid);
        global $DB;
        $this->assignmentid = (int)$DB->get_field(
            'local_taskflow_assignment',
            'id',
            ['userid' => (int)$this->employee->id, 'ruleid' => $ruleid],
            MUST_EXIST
        );

        $this->notrelevantrequest = $this->create_request(allowselfnotrelevant::ID, requests::TREATED_STATUS_UNTREATED);
        $this->extensionrequest = $this->create_request(allowselfextension::ID, requests::TREATED_STATUS_UNTREATED);
    }

    /**
     * Teardown: singletons.
     */
    protected function tearDown(): void {
        parent::tearDown();
        $this->generator->teardown();
    }

    /**
     * Write a custom profile field value.
     *
     * @param int $userid
     * @param string $shortname
     * @param string $value
     */
    private function set_profile(int $userid, string $shortname, string $value): void {
        global $DB;
        $DB->insert_record('user_info_data', (object)[
            'userid' => $userid,
            'fieldid' => $this->fields[$shortname],
            'data' => $value,
            'dataformat' => 0,
        ]);
    }

    /**
     * Insert a request addressed to the supervisor receiver.
     *
     * @param int $type
     * @param int $treated
     * @return int
     */
    private function create_request(int $type, int $treated): int {
        global $DB;
        return (int)$DB->insert_record('local_taskflow_requests', (object)[
            'request' => $type,
            'status' => $type,
            'userid' => (int)$this->employee->id,
            'assignmentid' => $this->assignmentid,
            'treated' => $treated,
            'forhr' => 0,
            'comment' => 'Please decide',
            'usermodified' => (int)$this->employee->id,
            'timecreated' => time() - HOURSECS,
            'timemodified' => time() - HOURSECS,
        ]);
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
        return (new treat_request_skill())->preflight($input, context_system::instance()->id, $userid);
    }

    /**
     * The proposal is confirmable and its rows name person, assignment, decision and effect.
     */
    public function test_confirmable_proposal(): void {
        $this->grant((int)$this->supervisor->id, [treat_request_skill::CAPABILITY]);

        $skill = new treat_request_skill();
        $this->setUser((int)$this->supervisor->id);
        $preflight = $skill->preflight([
            'requestid' => $this->notrelevantrequest,
            'decision' => treat_request_skill::DECISION_CONFIRM,
        ], context_system::instance()->id, (int)$this->supervisor->id);

        $this->assertSame('soft_block', $preflight->status);
        $this->assertContains(treat_request_skill::ISSUE_CONFIRM, $preflight->issuecodes);

        $description = $skill->describe_proposed_action($preflight->preparedinput);
        $this->assertIsArray($description);
        $values = array_column($description['rows'], 'value');
        $this->assertContains('Anna Muster', $values);
        $this->assertNotEmpty($description['summary']);
        $this->assertStringContainsString('#' . $this->assignmentid, implode(' ', $values));
    }

    /**
     * Confirming a not-relevant request sets the assignment status and verifies both facts.
     */
    public function test_confirm_notrelevant_request(): void {
        global $DB;

        $this->grant((int)$this->supervisor->id, [treat_request_skill::CAPABILITY]);
        $preflight = $this->preflight([
            'requestid' => $this->notrelevantrequest,
            'decision' => treat_request_skill::DECISION_CONFIRM,
            'comment' => 'Agreed',
        ], (int)$this->supervisor->id);

        $result = (new treat_request_skill())->execute(
            $preflight->preparedinput,
            context_system::instance()->id,
            (int)$this->supervisor->id
        );

        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status'], (string)$result['detail']);
        $this->assertSame([], $result['verification']['unverified']);
        $this->assertSame(
            requests::TREATED_STATUS_CONFIRMED,
            (int)$DB->get_field('local_taskflow_requests', 'treated', ['id' => $this->notrelevantrequest])
        );
        $this->assertSame(
            (int)assignment_status_facade::get_status_identifier('notrelevant'),
            (int)$DB->get_field('local_taskflow_assignment', 'status', ['id' => $this->assignmentid])
        );
        $this->assertSame(
            taskflow_preview_renderer_factory::TYPE_REQUEST_LIST,
            $result['preview']['type']
        );
    }

    /**
     * Confirming an extension request with a new due date moves the due date.
     */
    public function test_confirm_extension_with_new_duedate(): void {
        global $DB;

        $this->grant((int)$this->supervisor->id, [treat_request_skill::CAPABILITY]);
        $newduedate = time() + 30 * DAYSECS;

        $preflight = $this->preflight([
            'requestid' => $this->extensionrequest,
            'decision' => treat_request_skill::DECISION_CONFIRM,
            'newduedate' => $newduedate,
        ], (int)$this->supervisor->id);
        $this->assertSame('soft_block', $preflight->status);

        $result = (new treat_request_skill())->execute(
            $preflight->preparedinput,
            context_system::instance()->id,
            (int)$this->supervisor->id
        );

        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status'], (string)$result['detail']);
        $this->assertSame(
            requests::TREATED_STATUS_CONFIRMED,
            (int)$DB->get_field('local_taskflow_requests', 'treated', ['id' => $this->extensionrequest])
        );
        $this->assertSame(
            $newduedate,
            (int)$DB->get_field('local_taskflow_assignment', 'duedate', ['id' => $this->assignmentid])
        );

        // A new due date is only meaningful for an extension request.
        $preflight = $this->preflight([
            'requestid' => $this->notrelevantrequest,
            'decision' => treat_request_skill::DECISION_CONFIRM,
            'newduedate' => $newduedate,
        ], (int)$this->supervisor->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(treat_request_skill::ISSUE_DUEDATE_UNSUPPORTED, $preflight->issuecodes);
    }

    /**
     * Declining marks the request as declined without touching the assignment.
     */
    public function test_decline_leaves_assignment_untouched(): void {
        global $DB;

        $this->grant((int)$this->supervisor->id, [treat_request_skill::CAPABILITY]);
        $statusbefore = (int)$DB->get_field('local_taskflow_assignment', 'status', ['id' => $this->assignmentid]);

        $preflight = $this->preflight([
            'requestid' => $this->notrelevantrequest,
            'decision' => treat_request_skill::DECISION_DECLINE,
        ], (int)$this->supervisor->id);
        $result = (new treat_request_skill())->execute(
            $preflight->preparedinput,
            context_system::instance()->id,
            (int)$this->supervisor->id
        );

        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status'], (string)$result['detail']);
        $this->assertSame(
            requests::TREATED_STATUS_DECLINED,
            (int)$DB->get_field('local_taskflow_requests', 'treated', ['id' => $this->notrelevantrequest])
        );
        $this->assertSame(
            $statusbefore,
            (int)$DB->get_field('local_taskflow_assignment', 'status', ['id' => $this->assignmentid])
        );
    }

    /**
     * An already treated request is refused and the message names who treated it when.
     */
    public function test_already_treated_is_rejected(): void {
        global $DB;

        $this->grant((int)$this->supervisor->id, [treat_request_skill::CAPABILITY]);
        $treated = $this->create_request(allowselfnotrelevant::ID, requests::TREATED_STATUS_CONFIRMED);
        history::log(
            $this->assignmentid,
            (int)$this->employee->id,
            history::TYPE_REQUEST_CONFIRMED,
            ['action' => 'created', 'data' => (object)['requestid' => $treated]],
            (int)$this->supervisor->id
        );

        $preflight = $this->preflight([
            'requestid' => $treated,
            'decision' => treat_request_skill::DECISION_CONFIRM,
        ], (int)$this->supervisor->id);

        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(treat_request_skill::ISSUE_ALREADY_TREATED, $preflight->issuecodes);
        $this->assertStringContainsString('Emily Smith', $preflight->issues[0]['message']);

        $direct = (new treat_request_skill())->execute([
            'requestid' => $treated,
            'decision' => treat_request_skill::DECISION_CONFIRM,
        ], context_system::instance()->id, (int)$this->supervisor->id);
        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $direct['status']);
        $this->assertContains(treat_request_skill::ISSUE_ALREADY_TREATED, $direct['issue_codes']);
        $this->assertSame(2, $DB->count_records('local_taskflow_requests', ['treated' => requests::TREATED_STATUS_UNTREATED]));
    }

    /**
     * Only the addressed receiver (or a holder of viewallrequests) may treat the request.
     */
    public function test_scope_is_limited_to_the_receiver(): void {
        global $DB;

        // No treatrequests at all.
        $preflight = $this->preflight([
            'requestid' => $this->notrelevantrequest,
            'decision' => treat_request_skill::DECISION_CONFIRM,
        ], (int)$this->stranger->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(taskflow_skill_base::ISSUE_SCOPE_DENIED, $preflight->issuecodes);

        // The user holds treatrequests, but is not the receiver of this request.
        $this->grant((int)$this->stranger->id, [treat_request_skill::CAPABILITY]);
        $preflight = $this->preflight([
            'requestid' => $this->notrelevantrequest,
            'decision' => treat_request_skill::DECISION_CONFIRM,
        ], (int)$this->stranger->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(treat_request_skill::ISSUE_NOT_RECEIVER, $preflight->issuecodes);
        $this->assertSame(
            requests::TREATED_STATUS_UNTREATED,
            (int)$DB->get_field('local_taskflow_requests', 'treated', ['id' => $this->notrelevantrequest])
        );

        // The capability viewallrequests replaces the receiver relationship.
        $this->grant((int)$this->stranger->id, [treat_request_skill::CAPABILITY_ALL]);
        $preflight = $this->preflight([
            'requestid' => $this->notrelevantrequest,
            'decision' => treat_request_skill::DECISION_CONFIRM,
        ], (int)$this->stranger->id);
        $this->assertSame('soft_block', $preflight->status);
    }
}
