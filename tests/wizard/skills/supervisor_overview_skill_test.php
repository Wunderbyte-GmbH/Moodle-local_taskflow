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
use local_taskflow\local\requests;
use local_taskflow\local\requests\request_types\types\allowselfnotrelevant;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\skills\supervisor_overview_skill;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use local_taskflow\wizard\local_wizard_dependency;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../local_wizard_dependency.php');

/**
 * Team counters and scope behaviour of local_taskflow.supervisor_overview.
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\skills\supervisor_overview_skill
 * @covers     \local_taskflow\local\wizard\taskflow\preview\taskflow_supervisor_overview_preview_renderer
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class supervisor_overview_skill_test extends advanced_testcase {
    /** @var \local_taskflow_generator */
    private $generator;

    /** @var array<string,int> Profile field ids by shortname. */
    private array $fields = [];

    /** @var \stdClass */
    private \stdClass $supervisor;

    /** @var \stdClass Deputy of $supervisor. */
    private \stdClass $deputy;

    /** @var \stdClass First subordinate (one overdue assignment, one open request, one unread chat). */
    private \stdClass $employeea;

    /** @var \stdClass Second subordinate (one assignment, nothing overdue). */
    private \stdClass $employeeb;

    /** @var \stdClass Unrelated employee. */
    private \stdClass $other;

    /**
     * Setup: engine, standard adapter, two subordinates and a deputy.
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
        $this->fields = $this->generator->create_custom_profile_fields(['supervisor', 'deputy']);
        \local_taskflow\local\external_adapter\external_api_base::destroy_instance();

        $this->supervisor = $this->getDataGenerator()->create_user(['firstname' => 'Emily', 'lastname' => 'Smith']);
        $this->deputy = $this->getDataGenerator()->create_user(['firstname' => 'Dana', 'lastname' => 'Deputy']);
        $this->employeea = $this->getDataGenerator()->create_user(['firstname' => 'Anna', 'lastname' => 'Muster']);
        $this->employeeb = $this->getDataGenerator()->create_user(['firstname' => 'Bert', 'lastname' => 'Beispiel']);
        $this->other = $this->getDataGenerator()->create_user(['firstname' => 'Otto', 'lastname' => 'Other']);
        $this->set_profile((int)$this->employeea->id, 'supervisor', (string)$this->supervisor->id);
        $this->set_profile((int)$this->employeeb->id, 'supervisor', (string)$this->supervisor->id);
        $this->set_profile((int)$this->supervisor->id, 'deputy', (string)$this->deputy->id);

        $ruleid = (int)$this->generator->create_rule(['name' => 'Data protection']);
        $assignmenta = $this->create_assignment((int)$this->employeea->id, $ruleid);
        $this->create_assignment((int)$this->employeeb->id, $ruleid);
        $this->create_assignment((int)$this->other->id, $ruleid);

        // Employee A is overdue (status id from the facade, never a literal number).
        $DB->update_record('local_taskflow_assignment', (object)[
            'id' => $assignmenta,
            'status' => assignment_status_facade::get_status_identifier('overdue'),
            'duedate' => time() - 3 * DAYSECS,
        ]);
        assignment::destroy_instance();

        // One open request of employee A addressed to the supervisor receiver.
        $DB->insert_record('local_taskflow_requests', (object)[
            'request' => allowselfnotrelevant::ID,
            'status' => allowselfnotrelevant::ID,
            'userid' => (int)$this->employeea->id,
            'assignmentid' => $assignmenta,
            'treated' => requests::TREATED_STATUS_UNTREATED,
            'forhr' => 0,
            'comment' => '',
            'usermodified' => (int)$this->employeea->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        // One internal message of employee A that the supervisor has never seen.
        $DB->insert_record('local_taskflow_int_com', (object)[
            'assignmentid' => $assignmenta,
            'message' => 'Any news?',
            'usermodified' => (int)$this->employeea->id,
            'timecreated' => time(),
            'timemodified' => time(),
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
     * Create an assignment and return its id.
     *
     * @param int $userid
     * @param int $ruleid
     * @return int
     */
    private function create_assignment(int $userid, int $ruleid): int {
        global $DB;
        $this->generator->create_user_assignment($userid, $ruleid);
        return (int)$DB->get_field('local_taskflow_assignment', 'id', ['userid' => $userid, 'ruleid' => $ruleid], MUST_EXIST);
    }

    /**
     * Grant a capability to a user through a fresh system role.
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
     * Preflight + execute as the given user.
     *
     * @param array $input
     * @param int $userid
     * @return array{preflight:object,result:array|null}
     */
    private function run_skill(array $input, int $userid): array {
        $skill = new supervisor_overview_skill();
        $preflight = $skill->preflight($input, context_system::instance()->id, $userid);
        $result = null;
        if ($preflight->status === 'pass') {
            $result = $skill->execute($preflight->preparedinput, context_system::instance()->id, $userid);
        }
        return ['preflight' => $preflight, 'result' => $result];
    }

    /**
     * One row of a result by user id.
     *
     * @param array $result
     * @param int $userid
     * @return array
     */
    private function row(array $result, int $userid): array {
        foreach ((array)$result['subordinates'] as $row) {
            if ((int)$row['userid'] === $userid) {
                return (array)$row;
            }
        }
        return [];
    }

    /**
     * The supervisor sees both subordinates with their counters; overdue sorts first.
     */
    public function test_supervisor_sees_own_team(): void {
        $this->grant((int)$this->supervisor->id, [supervisor_overview_skill::CAP_ISSUPERVISOR]);

        $run = $this->run_skill([], (int)$this->supervisor->id);
        $this->assertSame('pass', $run['preflight']->status);
        $result = $run['result'];

        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status']);
        $this->assertCount(2, $result['subordinates']);
        $this->assertEqualsCanonicalizing(
            [(int)$this->employeea->id, (int)$this->employeeb->id],
            array_column($result['subordinates'], 'userid')
        );

        // Overdue first (server-side sorting).
        $this->assertSame((int)$this->employeea->id, (int)$result['subordinates'][0]['userid']);

        $rowa = $this->row($result, (int)$this->employeea->id);
        $this->assertSame(1, $rowa['overdue']);
        $this->assertSame(1, $rowa['open_requests']);
        $this->assertSame(1, $rowa['unread_chats']);
        $this->assertSame('Anna Muster', $rowa['fullname']);

        $rowb = $this->row($result, (int)$this->employeeb->id);
        $this->assertSame(0, $rowb['overdue']);
        $this->assertSame(0, $rowb['open_requests']);
        $this->assertSame(0, $rowb['unread_chats']);

        $this->assertSame(1, $result['totals']['overdue']);
        $this->assertSame(1, $result['totals']['open_requests']);
        $this->assertSame(1, $result['totals']['unread_chats']);
        foreach (['status', 'detail', 'usermessage', 'observation_full', 'links', 'issue_codes'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }

        // Preview: declared as data, rendered by the base class.
        $preview = (new supervisor_overview_skill())
            ->get_result_preview($result, context_system::instance()->id, (int)$this->supervisor->id);
        $this->assertNotNull($preview);
        $this->assertSame(taskflow_preview_renderer_factory::TYPE_SUPERVISOR_OVERVIEW, $preview['type']);
        $this->assertStringContainsString('Anna Muster', $preview['html']);
        $this->assertContains((int)$this->supervisor->id, $preview['payload']['userids']);
    }

    /**
     * The deputy sees the delegated team of the supervisor.
     */
    public function test_deputy_sees_delegated_team(): void {
        $this->grant((int)$this->deputy->id, [supervisor_overview_skill::CAP_ISSUPERVISOR]);

        $run = $this->run_skill([], (int)$this->deputy->id);
        $this->assertSame('pass', $run['preflight']->status);
        $this->assertEqualsCanonicalizing(
            [(int)$this->employeea->id, (int)$this->employeeb->id],
            array_column($run['result']['subordinates'], 'userid')
        );
    }

    /**
     * Reading a foreign team needs viewreports; without it the scope is denied.
     */
    public function test_foreign_team_requires_viewreports(): void {
        $this->grant((int)$this->other->id, [supervisor_overview_skill::CAP_ISSUPERVISOR]);

        $run = $this->run_skill(['supervisorid' => (int)$this->supervisor->id], (int)$this->other->id);
        $this->assertSame('hard_block', $run['preflight']->status);
        $this->assertContains(taskflow_skill_base::ISSUE_SCOPE_DENIED, $run['preflight']->issuecodes);
        $this->assertNull($run['result']);

        // Calling execute() without preflight is equally guarded.
        $result = (new supervisor_overview_skill())->execute(
            ['supervisorid' => (int)$this->supervisor->id],
            context_system::instance()->id,
            (int)$this->other->id
        );
        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $result['status']);
        $this->assertContains(taskflow_skill_base::ISSUE_SCOPE_DENIED, $result['issue_codes']);

        $this->grant((int)$this->other->id, [supervisor_overview_skill::CAP_VIEWREPORTS]);
        $run = $this->run_skill(['supervisorid' => (int)$this->supervisor->id], (int)$this->other->id);
        $this->assertSame('pass', $run['preflight']->status);
        $this->assertCount(2, $run['result']['subordinates']);
    }

    /**
     * A user without a team gets an empty list and zero totals.
     */
    public function test_user_without_team(): void {
        $this->grant((int)$this->other->id, [supervisor_overview_skill::CAP_ISSUPERVISOR]);

        $run = $this->run_skill([], (int)$this->other->id);
        $this->assertSame('pass', $run['preflight']->status);
        $this->assertSame([], $run['result']['subordinates']);
        $this->assertSame(0, $run['result']['totals']['open']);
    }
}
