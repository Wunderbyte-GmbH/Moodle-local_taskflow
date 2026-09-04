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
use local_taskflow\local\requests\request_types\types\allowuploadevidence;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\skills\review_evidence_skill;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use local_taskflow\wizard\local_wizard_dependency;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../local_wizard_dependency.php');

/**
 * Decision handling, capability gate and verification of local_taskflow.review_evidence.
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\skills\review_evidence_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class review_evidence_skill_test extends advanced_testcase {
    /** @var \local_taskflow_generator */
    private $generator;

    /** @var \stdClass Reviewer holding the gating capability. */
    private \stdClass $reviewer;

    /** @var \stdClass Owner of the assignment and of the evidence. */
    private \stdClass $employee;

    /** @var \stdClass User without any taskflow capability. */
    private \stdClass $stranger;

    /** @var int Assignment of the employee. */
    private int $assignmentid = 0;

    /** @var int Uploaded evidence of the employee. */
    private int $assgincompid = 0;

    /** @var int Open evidence request belonging to the evidence. */
    private int $requestid = 0;

    /** Competency id used by the fixture (no real competency needed for the status logic). */
    private const COMPETENCYID = 4711;

    /**
     * Setup: engine, standard adapter, one assignment with one uploaded evidence and its request.
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        local_wizard_dependency::require_installed();
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_taskflow');
        $this->generator->set_config_values('standard');
        \local_taskflow\local\external_adapter\external_api_base::destroy_instance();

        $this->reviewer = $this->getDataGenerator()->create_user(['firstname' => 'Emily', 'lastname' => 'Smith']);
        $this->employee = $this->getDataGenerator()->create_user(['firstname' => 'Anna', 'lastname' => 'Muster']);
        $this->stranger = $this->getDataGenerator()->create_user(['firstname' => 'Otto', 'lastname' => 'Other']);

        $ruleid = (int)$this->generator->create_rule(['name' => 'Data protection']);
        $this->generator->create_user_assignment((int)$this->employee->id, $ruleid);
        $this->assignmentid = (int)$DB->get_field(
            'local_taskflow_assignment',
            'id',
            ['userid' => (int)$this->employee->id, 'ruleid' => $ruleid],
            MUST_EXIST
        );

        $this->assgincompid = $this->create_evidence();
        $this->requestid = $this->create_request($this->assgincompid);
    }

    /**
     * Teardown: singletons.
     */
    protected function tearDown(): void {
        parent::tearDown();
        $this->generator->teardown();
    }

    /**
     * Insert one uploaded evidence row of the employee.
     *
     * The competency evidence id stays 0 on purpose: assignment_competency::set_competency()
     * and ::delete_competency() then do not touch the core competency subsystem, so the test
     * covers exactly the status and request logic of the skill.
     *
     * @return int
     */
    private function create_evidence(): int {
        global $DB;
        return (int)$DB->insert_record('local_taskflow_assgin_comp', (object)[
            'competencyevidenceid' => 0,
            'assignmentid' => $this->assignmentid,
            'userid' => (int)$this->employee->id,
            'competencyid' => self::COMPETENCYID,
            'status' => 'underreview',
            'validationondate' => 0,
            'timecreated' => time() - HOURSECS,
            'timemodified' => time() - HOURSECS,
        ]);
    }

    /**
     * Insert the evidence request belonging to an evidence row.
     *
     * @param int $assgincompid
     * @return int
     */
    private function create_request(int $assgincompid): int {
        global $DB;
        return (int)$DB->insert_record('local_taskflow_requests', (object)[
            'request' => allowuploadevidence::ID,
            'status' => allowuploadevidence::ID,
            'userid' => (int)$this->employee->id,
            'assignmentid' => $this->assignmentid,
            'treated' => requests::TREATED_STATUS_UNTREATED,
            'forhr' => 0,
            'comment' => 'Please review my certificate',
            'json' => json_encode(['assingmentcompetencyid' => $assgincompid]),
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
        return (new review_evidence_skill())->preflight($input, context_system::instance()->id, $userid);
    }

    /**
     * Approving sets the evidence status and confirms the belonging request.
     */
    public function test_approve_sets_status_and_treats_request(): void {
        global $DB;

        $this->grant((int)$this->reviewer->id, [
            review_evidence_skill::CAPABILITY,
            // The assignment card of the result preview is only built for a reader of the assignment.
            'local/taskflow:viewassignment',
        ]);
        $validuntil = time() + 365 * DAYSECS;

        $preflight = $this->preflight([
            'assgincompid' => $this->assgincompid,
            'decision' => review_evidence_skill::DECISION_APPROVE,
            'validuntil' => $validuntil,
            'comment' => 'Certificate accepted',
        ], (int)$this->reviewer->id);

        $this->assertSame('soft_block', $preflight->status);
        $this->assertContains(review_evidence_skill::ISSUE_CONFIRM, $preflight->issuecodes);

        $skill = new review_evidence_skill();
        $description = $skill->describe_proposed_action($preflight->preparedinput);
        $this->assertIsArray($description);
        $labels = array_column($description['rows'], 'label');
        $values = implode(' ', array_column($description['rows'], 'value'));
        $this->assertContains(get_string('agent_review_evidence_row_evidence', 'local_taskflow'), $labels);
        $this->assertContains(get_string('agent_review_evidence_row_decision', 'local_taskflow'), $labels);
        $this->assertContains(get_string('agent_review_evidence_row_validuntil', 'local_taskflow'), $labels);
        // The odd capability gating is named in the confirmation preview.
        $this->assertStringContainsString(review_evidence_skill::CAPABILITY, $values);

        $result = $skill->execute(
            $preflight->preparedinput,
            context_system::instance()->id,
            (int)$this->reviewer->id
        );

        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status'], (string)$result['detail']);
        $this->assertSame([], $result['verification']['unverified']);
        $this->assertSame(
            review_evidence_skill::STATUS_APPROVED,
            (string)$DB->get_field('local_taskflow_assgin_comp', 'status', ['id' => $this->assgincompid])
        );
        $this->assertSame(
            $validuntil,
            (int)$DB->get_field('local_taskflow_assgin_comp', 'validationondate', ['id' => $this->assgincompid])
        );
        $this->assertSame(
            requests::TREATED_STATUS_CONFIRMED,
            (int)$DB->get_field('local_taskflow_requests', 'treated', ['id' => $this->requestid])
        );
        $this->assertSame(
            taskflow_preview_renderer_factory::TYPE_ASSIGNMENT,
            (string)$result['preview']['type']
        );
    }

    /**
     * Rejecting sets the evidence to rejected and declines the belonging request.
     */
    public function test_reject_declines_request(): void {
        global $DB;

        $this->grant((int)$this->reviewer->id, [review_evidence_skill::CAPABILITY]);

        $preflight = $this->preflight([
            'assignmentid' => $this->assignmentid,
            'competencyid' => self::COMPETENCYID,
            'decision' => review_evidence_skill::DECISION_REJECT,
        ], (int)$this->reviewer->id);
        $this->assertSame('soft_block', $preflight->status);
        $this->assertSame($this->assgincompid, (int)$preflight->preparedinput['assgincompid']);

        $result = (new review_evidence_skill())->execute(
            $preflight->preparedinput,
            context_system::instance()->id,
            (int)$this->reviewer->id
        );

        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status'], (string)$result['detail']);
        $this->assertSame(
            review_evidence_skill::STATUS_REJECTED,
            (string)$DB->get_field('local_taskflow_assgin_comp', 'status', ['id' => $this->assgincompid])
        );
        $this->assertSame(
            requests::TREATED_STATUS_DECLINED,
            (int)$DB->get_field('local_taskflow_requests', 'treated', ['id' => $this->requestid])
        );
    }

    /**
     * Without local/taskflow:editmessages nothing is decided; uploaduserevidence is not enough.
     */
    public function test_missing_capability_blocks(): void {
        global $DB;

        $preflight = $this->preflight([
            'assgincompid' => $this->assgincompid,
            'decision' => review_evidence_skill::DECISION_APPROVE,
        ], (int)$this->stranger->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(taskflow_skill_base::ISSUE_SCOPE_DENIED, $preflight->issuecodes);

        // The view capability alone does not open the decision either (form gating, T-D1).
        $this->grant((int)$this->stranger->id, [review_evidence_skill::CAPABILITY_VIEW]);
        $preflight = $this->preflight([
            'assgincompid' => $this->assgincompid,
            'decision' => review_evidence_skill::DECISION_APPROVE,
        ], (int)$this->stranger->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(taskflow_skill_base::ISSUE_SCOPE_DENIED, $preflight->issuecodes);

        $direct = (new review_evidence_skill())->execute([
            'assgincompid' => $this->assgincompid,
            'decision' => review_evidence_skill::DECISION_APPROVE,
        ], context_system::instance()->id, (int)$this->stranger->id);
        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $direct['status']);
        $this->assertContains(taskflow_skill_base::ISSUE_SCOPE_DENIED, $direct['issue_codes']);
        $this->assertSame(
            'underreview',
            (string)$DB->get_field('local_taskflow_assgin_comp', 'status', ['id' => $this->assgincompid])
        );
        $this->assertSame(
            requests::TREATED_STATUS_UNTREATED,
            (int)$DB->get_field('local_taskflow_requests', 'treated', ['id' => $this->requestid])
        );
    }

    /**
     * An evidence without a belonging request is refused instead of being half written.
     */
    public function test_evidence_without_request_is_refused(): void {
        global $DB;

        $this->grant((int)$this->reviewer->id, [review_evidence_skill::CAPABILITY]);
        $orphan = $this->create_evidence();

        $preflight = $this->preflight([
            'assgincompid' => $orphan,
            'decision' => review_evidence_skill::DECISION_APPROVE,
        ], (int)$this->reviewer->id);

        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(review_evidence_skill::ISSUE_REQUEST_MISSING, $preflight->issuecodes);
        $this->assertSame(
            'underreview',
            (string)$DB->get_field('local_taskflow_assgin_comp', 'status', ['id' => $orphan])
        );
    }

    /**
     * An unknown decision or a missing target is refused before anything is read.
     */
    public function test_validation_of_decision_and_target(): void {
        $this->grant((int)$this->reviewer->id, [review_evidence_skill::CAPABILITY]);

        $preflight = $this->preflight([
            'assgincompid' => $this->assgincompid,
            'decision' => 'maybe',
        ], (int)$this->reviewer->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(review_evidence_skill::ISSUE_DECISION_UNKNOWN, $preflight->issuecodes);

        $preflight = $this->preflight([
            'decision' => review_evidence_skill::DECISION_APPROVE,
        ], (int)$this->reviewer->id);
        $this->assertSame('hard_block', $preflight->status);
        $this->assertContains(review_evidence_skill::ISSUE_EVIDENCE_UNIDENTIFIED, $preflight->issuecodes);
    }
}
