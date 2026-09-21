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
use local_taskflow\local\requests\request_types\requests_manager;
use local_taskflow\local\requests\request_types\types\allowselfextension;
use local_taskflow\local\requests\request_types\types\allowselfnotrelevant;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\skills\list_requests_skill;
use local_taskflow\local\wizard\taskflow\taskflow_permission_resolver;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use local_taskflow\wizard\local_wizard_dependency;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../local_wizard_dependency.php');

/**
 * Visibility and filter behaviour of local_taskflow.list_requests.
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\skills\list_requests_skill
 * @covers     \local_taskflow\local\wizard\taskflow\preview\taskflow_request_list_preview_renderer
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class list_requests_skill_test extends advanced_testcase {
    /** @var \local_taskflow_generator */
    private $generator;

    /** @var array<string,int> Profile field ids by shortname. */
    private array $fields = [];

    /** @var \stdClass */
    private \stdClass $supervisor;

    /** @var \stdClass Subordinate of $supervisor. */
    private \stdClass $employee;

    /** @var \stdClass Unrelated employee. */
    private \stdClass $other;

    /** @var int Open not-relevant request of the employee (supervisor receiver). */
    private int $employeeopen = 0;

    /** @var int Confirmed prolongation request of the employee (supervisor receiver). */
    private int $employeetreated = 0;

    /** @var int Open request of the unrelated employee. */
    private int $otheropen = 0;

    /**
     * Setup: engine, standard adapter, supervisor field, three requests.
     */
    protected function setUp(): void {
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
        $this->employee = $this->getDataGenerator()->create_user(['firstname' => 'Anna', 'lastname' => 'Muster']);
        $this->other = $this->getDataGenerator()->create_user(['firstname' => 'Otto', 'lastname' => 'Other']);
        $this->set_profile((int)$this->employee->id, 'supervisor', (string)$this->supervisor->id);

        $ruleid = (int)$this->generator->create_rule(['name' => 'Data protection']);
        $employeeassignment = $this->create_assignment((int)$this->employee->id, $ruleid);
        $otherassignment = $this->create_assignment((int)$this->other->id, $ruleid);

        $this->employeeopen = $this->create_request(
            (int)$this->employee->id,
            $employeeassignment,
            allowselfnotrelevant::ID,
            requests::TREATED_STATUS_UNTREATED
        );
        $this->employeetreated = $this->create_request(
            (int)$this->employee->id,
            $employeeassignment,
            allowselfextension::ID,
            requests::TREATED_STATUS_CONFIRMED
        );
        $this->otheropen = $this->create_request(
            (int)$this->other->id,
            $otherassignment,
            allowselfnotrelevant::ID,
            requests::TREATED_STATUS_UNTREATED
        );
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
     * Insert a request row addressed to the supervisor receiver.
     *
     * @param int $userid
     * @param int $assignmentid
     * @param int $type
     * @param int $treated
     * @return int
     */
    private function create_request(int $userid, int $assignmentid, int $type, int $treated): int {
        global $DB;
        return (int)$DB->insert_record('local_taskflow_requests', (object)[
            'request' => $type,
            'status' => $type,
            'userid' => $userid,
            'assignmentid' => $assignmentid,
            'treated' => $treated,
            'forhr' => 0,
            'comment' => 'Please decide',
            'usermodified' => $userid,
            'timecreated' => time() - $type * MINSECS,
            'timemodified' => time(),
        ]);
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
        $skill = new list_requests_skill();
        $preflight = $skill->preflight($input, context_system::instance()->id, $userid);
        $result = null;
        if ($preflight->status === 'pass') {
            $result = $skill->execute($preflight->preparedinput, context_system::instance()->id, $userid);
        }
        return ['preflight' => $preflight, 'result' => $result];
    }

    /**
     * Request ids of a result.
     *
     * @param array $result
     * @return int[]
     */
    private function ids(array $result): array {
        $ids = array_map(static fn(array $row): int => (int)$row['id'], (array)$result['requests']);
        sort($ids);
        return $ids;
    }

    /**
     * An employee with viewrequests sees only the own requests.
     */
    public function test_employee_sees_own_requests(): void {
        $this->grant((int)$this->employee->id, [list_requests_skill::CAP_VIEWREQUESTS]);

        $run = $this->run_skill([], (int)$this->employee->id);
        $this->assertSame('pass', $run['preflight']->status);
        $result = $run['result'];

        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status']);
        $this->assertSame(taskflow_permission_resolver::SCOPE_SELF, $result['scope']);
        $expected = [$this->employeeopen, $this->employeetreated];
        sort($expected);
        $this->assertSame($expected, $this->ids($result));
        $this->assertSame(2, $result['total']);

        $row = $result['requests'][0];
        foreach (
            [
            'id', 'type', 'typelabel', 'treated', 'treatedlabel', 'userid', 'fullname',
            'assignmentid', 'rulename', 'receiver', 'comment', 'timecreated',
            ] as $key
        ) {
            $this->assertArrayHasKey($key, $row);
        }
        $this->assertSame('Anna Muster', $row['fullname']);
        $this->assertNotSame('', $row['typelabel']);

        // A foreign user filter is denied.
        $run = $this->run_skill(['userid' => (int)$this->other->id], (int)$this->employee->id);
        $this->assertSame('hard_block', $run['preflight']->status);
        $this->assertContains(taskflow_skill_base::ISSUE_SCOPE_DENIED, $run['preflight']->issuecodes);
    }

    /**
     * A supervisor with viewrequests only (the dashboard gate) sees the requests addressed to them as well.
     */
    public function test_supervisor_with_viewrequests_sees_addressed_requests(): void {
        $this->grant((int)$this->supervisor->id, [list_requests_skill::CAP_VIEWREQUESTS]);

        $run = $this->run_skill([], (int)$this->supervisor->id);
        $this->assertSame('pass', $run['preflight']->status);
        $this->assertSame(taskflow_permission_resolver::SCOPE_SUPERVISOR, $run['result']['scope']);
        $expected = [$this->employeeopen, $this->employeetreated];
        sort($expected);
        $this->assertSame($expected, $this->ids($run['result']));
        $this->assertNotContains($this->otheropen, $this->ids($run['result']));
    }

    /**
     * A supervisor with treatrequests sees the requests addressed to them, not foreign ones.
     */
    public function test_supervisor_sees_requests_addressed_to_them(): void {
        $this->grant((int)$this->supervisor->id, [list_requests_skill::CAP_TREATREQUESTS]);

        $run = $this->run_skill([], (int)$this->supervisor->id);
        $this->assertSame('pass', $run['preflight']->status);
        $result = $run['result'];
        $this->assertSame(taskflow_permission_resolver::SCOPE_SUPERVISOR, $result['scope']);
        $expected = [$this->employeeopen, $this->employeetreated];
        sort($expected);
        $this->assertSame($expected, $this->ids($result));
        $this->assertNotContains($this->otheropen, $this->ids($result));

        // Preview: declared as data, rendered by the base class into a list card.
        $preview = (new list_requests_skill())
            ->get_result_preview($result, context_system::instance()->id, (int)$this->supervisor->id);
        $this->assertNotNull($preview);
        $this->assertSame(taskflow_preview_renderer_factory::TYPE_REQUEST_LIST, $preview['type']);
        $this->assertStringContainsString('Anna Muster', $preview['html']);
        $this->assertEqualsCanonicalizing($expected, $preview['payload']['requestids']);
    }

    /**
     * all = true needs viewallrequests: without it a hard block, with it every request.
     */
    public function test_all_requires_capability(): void {
        $this->grant((int)$this->supervisor->id, [list_requests_skill::CAP_TREATREQUESTS]);

        $run = $this->run_skill(['all' => true], (int)$this->supervisor->id);
        $this->assertSame('hard_block', $run['preflight']->status);
        $this->assertContains(list_requests_skill::ISSUE_ALL_DENIED, $run['preflight']->issuecodes);
        $this->assertNull($run['result']);

        // Calling execute() without preflight is equally guarded.
        $result = (new list_requests_skill())
            ->execute(['all' => true], context_system::instance()->id, (int)$this->supervisor->id);
        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $result['status']);
        $this->assertContains(list_requests_skill::ISSUE_ALL_DENIED, $result['issue_codes']);

        $run = $this->run_skill(['all' => true], (int)get_admin()->id);
        $this->assertSame('pass', $run['preflight']->status);
        $this->assertSame(taskflow_permission_resolver::SCOPE_ADMIN, $run['result']['scope']);
        $this->assertSame(3, $run['result']['total']);
    }

    /**
     * The type and treated filters narrow the list; unknown values are rejected.
     */
    public function test_type_and_treated_filters(): void {
        $admin = (int)get_admin()->id;

        $run = $this->run_skill(['all' => true, 'type' => allowselfextension::ID], $admin);
        $this->assertSame([$this->employeetreated], $this->ids($run['result']));

        $run = $this->run_skill(['all' => true, 'treated' => requests::TREATED_STATUS_UNTREATED], $admin);
        $expected = [$this->employeeopen, $this->otheropen];
        sort($expected);
        $this->assertSame($expected, $this->ids($run['result']));

        $run = $this->run_skill([
            'all' => true,
            'treated' => requests::TREATED_STATUS_CONFIRMED,
            'type' => allowselfextension::ID,
        ], $admin);
        $this->assertSame([$this->employeetreated], $this->ids($run['result']));
        $this->assertNotEmpty($run['result']['filters']);

        $run = $this->run_skill(['all' => true, 'limit' => 1], $admin);
        $this->assertSame(3, $run['result']['total']);
        $this->assertCount(1, $run['result']['requests']);

        $run = $this->run_skill(['all' => true, 'type' => 99], $admin);
        $this->assertSame('hard_block', $run['preflight']->status);
        $this->assertContains(list_requests_skill::ISSUE_TYPE_UNKNOWN, $run['preflight']->issuecodes);

        $run = $this->run_skill(['all' => true, 'treated' => 9], $admin);
        $this->assertSame('hard_block', $run['preflight']->status);
        $this->assertContains(list_requests_skill::ISSUE_TREATED_UNKNOWN, $run['preflight']->issuecodes);
    }

    /**
     * Without any of the three capabilities nothing is visible.
     */
    public function test_without_capability_nothing_is_visible(): void {
        $run = $this->run_skill([], (int)$this->other->id);
        $this->assertSame('pass', $run['preflight']->status);
        $this->assertSame([], $this->ids($run['result']));
        $this->assertSame(0, $run['result']['total']);
    }

    /**
     * execute() with the raw input (read-only chat path, no preflight) resolves userquery, enforces the
     * scope and rejects unknown enum values instead of widening the list to every visible request.
     */
    public function test_execute_validates_raw_input_without_preflight(): void {
        $contextid = context_system::instance()->id;
        $this->grant((int)$this->supervisor->id, [list_requests_skill::CAP_TREATREQUESTS]);
        $skill = new list_requests_skill();

        $result = $skill->execute(['userquery' => 'nobody.nowhere@example.invalid'], $contextid, (int)$this->supervisor->id);
        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $result['status']);
        // This path reports a PREFLIGHT issue, which already carries severity 'needs_clarification' — the
        // engine turns it into a question by itself, so it needs no RECOVERABLE_INPUT_ERROR marker.
        // That marker belongs to error_result(), the EXECUTION path, where no severity survives.
        $this->assertSame([taskflow_skill_base::ISSUE_USER_NOT_FOUND], $result['issue_codes']);
        $this->assertArrayNotHasKey('requests', $result);

        // Otto is not a subordinate of the supervisor: foreign scope, no rows.
        $result = $skill->execute(['userquery' => $this->other->email], $contextid, (int)$this->supervisor->id);
        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $result['status']);
        $this->assertSame([taskflow_skill_base::ISSUE_SCOPE_DENIED], $result['issue_codes']);
        $this->assertArrayNotHasKey('requests', $result);

        $admin = (int)get_admin()->id;
        $result = $skill->execute(['all' => true, 'type' => 'extension'], $contextid, $admin);
        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $result['status']);
        $this->assertSame([list_requests_skill::ISSUE_TYPE_UNKNOWN], $result['issue_codes']);
        $this->assertArrayNotHasKey('requests', $result);
        // The user text names no schema ids (taskflow #462).
        $this->assertStringNotContainsString(
            implode(', ', array_keys((new requests_manager())->get_request_types_with_ids())),
            (string)$result['detail']
        );

        $result = $skill->execute(['all' => true, 'treated' => 'open'], $contextid, $admin);
        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $result['status']);
        $this->assertSame([list_requests_skill::ISSUE_TREATED_UNKNOWN], $result['issue_codes']);
        $this->assertArrayNotHasKey('requests', $result);

        // The resolvable query narrows to that person's requests.
        $result = $skill->execute(['userquery' => $this->employee->email], $contextid, (int)$this->supervisor->id);
        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status']);
        $expected = [$this->employeeopen, $this->employeetreated];
        sort($expected);
        $this->assertSame($expected, $this->ids($result));
    }

    /**
     * An unknown type is a clarification that carries the localized type titles as candidates and
     * no schema ids in its text (taskflow #462, LR-1/LR-3: "valid values: 1, 2 or 3" and English
     * enum labels reached the user).
     */
    public function test_unknown_type_offers_localized_candidates_without_ids(): void {
        $admin = (int)get_admin()->id;
        $types = (new requests_manager())->get_request_types_with_ids();

        $run = $this->run_skill(['all' => true, 'type' => 'extension,not-relevant'], $admin);
        $this->assertNotSame('pass', $run['preflight']->status);
        $issues = json_decode(json_encode($run['preflight']->issues), true);
        $issue = (array)($issues[0] ?? []);
        $this->assertSame(list_requests_skill::ISSUE_TYPE_UNKNOWN, (string)($issue['code'] ?? ''), json_encode($issues));
        $this->assertSame('needs_clarification', (string)($issue['severity'] ?? ''));
        $this->assertStringNotContainsString(implode(', ', array_keys($types)), (string)($issue['message'] ?? ''));

        $expected = [];
        foreach ($types as $id => $key) {
            $expected[] = ['id' => (int)$id, 'label' => get_string($key . '_title', 'local_taskflow')];
        }
        $this->assertEqualsCanonicalizing($expected, (array)($issue['candidates'] ?? []), 'candidates carry localized titles');
    }

    /**
     * type accepts a list of type ids; a single id keeps working (taskflow #462).
     */
    public function test_type_accepts_a_list_of_ids(): void {
        $admin = (int)get_admin()->id;

        $run = $this->run_skill(['all' => true, 'type' => [allowselfnotrelevant::ID, allowselfextension::ID]], $admin);
        $this->assertSame('pass', $run['preflight']->status, json_encode($run['preflight']->issuecodes));
        $expected = [$this->employeeopen, $this->employeetreated, $this->otheropen];
        sort($expected);
        $this->assertSame($expected, $this->ids($run['result']));

        $run = $this->run_skill(['all' => true, 'type' => [allowselfextension::ID]], $admin);
        $this->assertSame([$this->employeetreated], $this->ids($run['result']));

        $run = $this->run_skill(['all' => true, 'type' => allowselfextension::ID], $admin);
        $this->assertSame([$this->employeetreated], $this->ids($run['result']));
    }

    /**
     * Type keys and localized type titles resolve to the id like status names do (taskflow #465);
     * unknown words stay a clarification.
     */
    public function test_type_accepts_keys_and_localized_titles(): void {
        $admin = (int)get_admin()->id;

        $run = $this->run_skill(['all' => true, 'type' => ['allowselfextension']], $admin);
        $this->assertSame('pass', $run['preflight']->status, json_encode($run['preflight']->issuecodes));
        $this->assertSame([allowselfextension::ID], $run['preflight']->preparedinput['type']);
        $this->assertSame([$this->employeetreated], $this->ids($run['result']));

        $title = get_string('allowselfnotrelevant_title', 'local_taskflow');
        $run = $this->run_skill(['all' => true, 'type' => $title . ', allowselfextension'], $admin);
        $this->assertSame('pass', $run['preflight']->status, json_encode($run['preflight']->issuecodes));
        $this->assertEqualsCanonicalizing(
            [allowselfnotrelevant::ID, allowselfextension::ID],
            $run['preflight']->preparedinput['type']
        );

        $run = $this->run_skill(['all' => true, 'type' => 'no-such-type'], $admin);
        $this->assertNotSame('pass', $run['preflight']->status);
        $this->assertContains(list_requests_skill::ISSUE_TYPE_UNKNOWN, $run['preflight']->issuecodes);
    }

    /**
     * A viewassignment holder without all=true still sees the requests of their own team
     * (taskflow #463: the unrestricted visibility (null) collapsed to an empty subordinate list).
     */
    public function test_viewassignment_holder_sees_team_requests_without_all(): void {
        $this->grant((int)$this->supervisor->id, [list_requests_skill::CAP_TREATREQUESTS, 'local/taskflow:viewassignment']);

        $run = $this->run_skill([], (int)$this->supervisor->id);
        $this->assertSame('pass', $run['preflight']->status);
        $expected = [$this->employeeopen, $this->employeetreated];
        sort($expected);
        $this->assertSame($expected, $this->ids($run['result']));
        $this->assertNotContains($this->otheropen, $this->ids($run['result']));
    }

    /**
     * createdafter / createdbefore narrow the list by creation date; an unparsable value is a
     * clarification, never silently ignored (run 8 LR-4 "of last month", #470).
     */
    public function test_created_range_filters_by_creation_date(): void {
        global $DB;
        $admin = (int)get_admin()->id;
        $DB->set_field('local_taskflow_requests', 'timecreated', time() - 10 * DAYSECS, ['id' => $this->employeetreated]);
        $cut = (string)(time() - 5 * DAYSECS);

        $run = $this->run_skill(['all' => true, 'createdafter' => $cut], $admin);
        $this->assertSame('pass', $run['preflight']->status, json_encode($run['preflight']->issues));
        $expected = [$this->employeeopen, $this->otheropen];
        sort($expected);
        $this->assertSame($expected, $this->ids($run['result']));

        $run = $this->run_skill(['all' => true, 'createdbefore' => $cut], $admin);
        $this->assertSame('pass', $run['preflight']->status);
        $this->assertSame([$this->employeetreated], $this->ids($run['result']));

        $run = $this->run_skill(['all' => true, 'createdafter' => date('Y-m-d', time() - 5 * DAYSECS)], $admin);
        $this->assertSame('pass', $run['preflight']->status, 'ISO dates are accepted');
        $this->assertSame(2, $run['result']['total']);

        $run = $this->run_skill(['all' => true, 'createdafter' => 'kein datum'], $admin);
        $this->assertNotSame('pass', $run['preflight']->status);
        $this->assertContains(list_requests_skill::ISSUE_DATE_INVALID, $run['preflight']->issuecodes);
    }

    /**
     * self=true lists only the acting user's own requests and beats a guessed userquery (F36, #470).
     */
    public function test_self_returns_only_the_acting_users_requests(): void {
        $this->grant((int)$this->supervisor->id, [list_requests_skill::CAP_VIEWREQUESTS]);
        $ruleid = (int)$this->generator->create_rule(['name' => 'Fire safety']);
        $own = $this->create_request(
            (int)$this->supervisor->id,
            $this->create_assignment((int)$this->supervisor->id, $ruleid),
            allowselfextension::ID,
            requests::TREATED_STATUS_UNTREATED
        );

        $run = $this->run_skill(['self' => true, 'userquery' => 'Anna Muster'], (int)$this->supervisor->id);
        $this->assertSame('pass', $run['preflight']->status, json_encode($run['preflight']->issues));
        $this->assertArrayNotHasKey('self', (array)$run['preflight']->preparedinput);
        $this->assertSame([$own], $this->ids($run['result']));
        $this->assertSame(1, $run['result']['total']);
    }
}
