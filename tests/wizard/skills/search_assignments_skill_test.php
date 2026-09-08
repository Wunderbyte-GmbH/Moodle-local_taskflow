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
use local_taskflow\local\wizard\taskflow\skills\search_assignments_skill;
use local_taskflow\local\wizard\taskflow\taskflow_permission_resolver;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use local_taskflow\wizard\local_wizard_dependency;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../local_wizard_dependency.php');

/**
 * Scope and filter behaviour of local_taskflow.search_assignments.
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\skills\search_assignments_skill
 * @covers     \local_taskflow\local\wizard\taskflow\preview\taskflow_assignment_list_preview_renderer
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class search_assignments_skill_test extends advanced_testcase {
    /** @var \local_taskflow_generator */
    private $generator;

    /** @var array<string,int> Profile field ids by shortname. */
    private array $fields = [];

    /** @var \stdClass */
    private \stdClass $supervisor;

    /** @var \stdClass */
    private \stdClass $deputy;

    /** @var \stdClass Subordinate of $supervisor. */
    private \stdClass $employee;

    /** @var \stdClass Unrelated employee. */
    private \stdClass $other;

    /** @var int */
    private int $rulea = 0;

    /** @var int */
    private int $ruleb = 0;

    /** @var int Assignment employee / rule A (assigned, due in the future). */
    private int $employeea = 0;

    /** @var int Assignment employee / rule B (overdue). */
    private int $employeeb = 0;

    /** @var int Assignment other / rule A. */
    private int $othera = 0;

    /**
     * Setup: engine, standard adapter, supervisor/deputy fields, three assignments.
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
        $this->deputy = $this->getDataGenerator()->create_user(['firstname' => 'Bert', 'lastname' => 'Beispiel']);
        $this->employee = $this->getDataGenerator()->create_user(['firstname' => 'Anna', 'lastname' => 'Muster']);
        $this->other = $this->getDataGenerator()->create_user(['firstname' => 'Otto', 'lastname' => 'Other']);
        $this->set_profile((int)$this->employee->id, 'supervisor', (string)$this->supervisor->id);
        $this->set_profile((int)$this->supervisor->id, 'deputy', (string)$this->deputy->id);

        $this->rulea = (int)$this->generator->create_rule(['name' => 'Rule A']);
        $this->ruleb = (int)$this->generator->create_rule(['name' => 'Rule B']);

        $this->employeea = $this->create_assignment((int)$this->employee->id, $this->rulea);
        $this->employeeb = $this->create_assignment((int)$this->employee->id, $this->ruleb);
        $this->othera = $this->create_assignment((int)$this->other->id, $this->rulea);

        // Rule B assignment of the employee is overdue (engine status id, never a literal number).
        global $DB;
        $DB->update_record('local_taskflow_assignment', (object)[
            'id' => $this->employeeb,
            'status' => assignment_status_facade::get_status_identifier('overdue'),
            'duedate' => time() - 3 * DAYSECS,
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
     * Preflight + execute as the given user.
     *
     * @param array $input
     * @param int $userid
     * @return array{preflight:\local_taskflow\local\wizard\engine\preflight_result_v2|object,result:array|null}
     */
    private function run_skill(array $input, int $userid): array {
        $skill = new search_assignments_skill();
        $preflight = $skill->preflight($input, context_system::instance()->id, $userid);
        $result = null;
        if ($preflight->status === 'pass') {
            $result = $skill->execute($preflight->preparedinput, context_system::instance()->id, $userid);
        }
        return ['preflight' => $preflight, 'result' => $result];
    }

    /**
     * Assignment ids of a result.
     *
     * @param array $result
     * @return int[]
     */
    private function ids(array $result): array {
        $ids = array_map(static fn(array $row): int => (int)$row['id'], (array)$result['assignments']);
        sort($ids);
        return $ids;
    }

    /**
     * Admin sees every assignment; the result carries the base keys and a list preview.
     */
    public function test_admin_sees_all(): void {
        $run = $this->run_skill([], (int)get_admin()->id);
        $this->assertSame('pass', $run['preflight']->status);
        $result = $run['result'];

        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status']);
        $this->assertSame(taskflow_permission_resolver::SCOPE_ADMIN, $result['scope']);
        $this->assertSame(3, $result['total']);
        $expected = [$this->employeea, $this->employeeb, $this->othera];
        sort($expected);
        $this->assertSame($expected, $this->ids($result));
        foreach (['status', 'detail', 'usermessage', 'observation_full', 'links', 'debugmessage', 'issue_codes'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
        $row = $result['assignments'][0];
        foreach (['id', 'userid', 'fullname', 'ruleid', 'rulename', 'status', 'statuslabel', 'url', 'duedate'] as $key) {
            $this->assertArrayHasKey($key, $row);
        }
        $this->assertStringContainsString('Rule A', $result['observation_full']);

        // Preview: declared as data, rendered by the base class into a list card.
        $preview = (new search_assignments_skill())
            ->get_result_preview($result, context_system::instance()->id, (int)get_admin()->id);
        $this->assertNotNull($preview);
        $this->assertSame(taskflow_preview_renderer_factory::TYPE_ASSIGNMENT_LIST, $preview['type']);
        $this->assertStringContainsString('assignment.php?id=' . $this->employeea, $preview['html']);
        $this->assertStringContainsString('Anna Muster', $preview['html']);
        $this->assertEqualsCanonicalizing($expected, $preview['payload']['assignmentids']);
    }

    /**
     * Supervisor and deputy see only the subordinate's assignments; foreign user filter is denied.
     */
    public function test_supervisor_sees_only_subordinates_including_deputy_delegation(): void {
        $expected = [$this->employeea, $this->employeeb];
        sort($expected);

        $run = $this->run_skill([], (int)$this->supervisor->id);
        $this->assertSame('pass', $run['preflight']->status);
        $this->assertSame(taskflow_permission_resolver::SCOPE_SUPERVISOR, $run['result']['scope']);
        $this->assertSame(2, $run['result']['total']);
        $this->assertSame($expected, $this->ids($run['result']));

        $run = $this->run_skill(['userid' => (int)$this->employee->id], (int)$this->deputy->id);
        $this->assertSame('pass', $run['preflight']->status);
        $this->assertSame(taskflow_permission_resolver::SCOPE_SUPERVISOR, $run['result']['scope']);
        $this->assertSame($expected, $this->ids($run['result']));

        $run = $this->run_skill(['userid' => (int)$this->other->id], (int)$this->supervisor->id);
        $this->assertSame('hard_block', $run['preflight']->status);
        $this->assertContains(taskflow_skill_base::ISSUE_SCOPE_DENIED, $run['preflight']->issuecodes);
        $this->assertNull($run['result']);
    }

    /**
     * An employee sees only own assignments, also when filtering by e-mail; other users are denied.
     */
    public function test_employee_sees_only_own(): void {
        $run = $this->run_skill([], (int)$this->employee->id);
        $this->assertSame(taskflow_permission_resolver::SCOPE_SELF, $run['result']['scope']);
        $expected = [$this->employeea, $this->employeeb];
        sort($expected);
        $this->assertSame($expected, $this->ids($run['result']));

        $run = $this->run_skill(['userquery' => $this->other->email], (int)$this->other->id);
        $this->assertSame('pass', $run['preflight']->status);
        $this->assertSame([$this->othera], $this->ids($run['result']));

        $run = $this->run_skill(['userquery' => $this->employee->email], (int)$this->other->id);
        $this->assertSame('hard_block', $run['preflight']->status);
        $this->assertContains(taskflow_skill_base::ISSUE_SCOPE_DENIED, $run['preflight']->issuecodes);

        // Calling execute() without preflight is equally guarded.
        $result = (new search_assignments_skill())->execute(
            ['userid' => (int)$this->employee->id],
            context_system::instance()->id,
            (int)$this->other->id
        );
        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $result['status']);
        $this->assertContains(taskflow_skill_base::ISSUE_SCOPE_DENIED, $result['issue_codes']);
    }

    /**
     * Status filter by localized label / type name and overdueonly; unknown status is rejected.
     */
    public function test_filters_by_status_label_and_overdueonly(): void {
        $admin = (int)get_admin()->id;
        $overdueid = assignment_status_facade::get_status_identifier('overdue');

        $run = $this->run_skill(['status' => [assignment_status_facade::get_specific_names($overdueid)]], $admin);
        $this->assertSame('pass', $run['preflight']->status);
        $this->assertSame([$overdueid], $run['preflight']->preparedinput['status']);
        $this->assertSame([$this->employeeb], $this->ids($run['result']));

        $run = $this->run_skill(['status' => 'overdue'], $admin);
        $this->assertSame([$this->employeeb], $this->ids($run['result']));

        $run = $this->run_skill(['overdueonly' => true], $admin);
        $this->assertSame([$this->employeeb], $this->ids($run['result']));
        $this->assertTrue($run['result']['assignments'][0]['overdue']);
        $this->assertNotEmpty($run['result']['filters']);

        $run = $this->run_skill(['ruleid' => $this->rulea], $admin);
        $expected = [$this->employeea, $this->othera];
        sort($expected);
        $this->assertSame($expected, $this->ids($run['result']));

        $run = $this->run_skill(['duebefore' => date('Y-m-d', time() - DAYSECS)], $admin);
        $this->assertSame([$this->employeeb], $this->ids($run['result']));

        $run = $this->run_skill(['limit' => 1], $admin);
        $this->assertSame(3, $run['result']['total']);
        $this->assertCount(1, $run['result']['assignments']);

        $run = $this->run_skill(['status' => ['no-such-status']], $admin);
        $this->assertSame('hard_block', $run['preflight']->status);
        $this->assertContains(search_assignments_skill::ISSUE_STATUS_UNKNOWN, $run['preflight']->issuecodes);

        $run = $this->run_skill(['duebefore' => 'not a date'], $admin);
        $this->assertSame('hard_block', $run['preflight']->status);
        $this->assertContains(search_assignments_skill::ISSUE_DATE_INVALID, $run['preflight']->issuecodes);
    }

    /**
     * A userquery that resolves nobody is a hard stop echoing the query; the scope is never widened to "all".
     */
    public function test_unknown_userquery_is_hard_stop_and_never_widens_scope(): void {
        $admin = (int)get_admin()->id;

        $run = $this->run_skill(['userquery' => 'Facility'], $admin);
        $this->assertSame('hard_block', $run['preflight']->status);
        $this->assertContains(taskflow_skill_base::ISSUE_USER_NOT_FOUND, $run['preflight']->issuecodes);
        $this->assertNull($run['result']);
        $messages = implode(' ', array_map(
            static fn(array $issue): string => (string)$issue['message'],
            $run['preflight']->issues
        ));
        $this->assertStringContainsString('Facility', $messages);
        $this->assertStringNotContainsString('"0"', $messages);

        // Defence in depth: the read-only chat path executes with the raw input and no preflight.
        $result = (new search_assignments_skill())->execute(['userquery' => 'Facility'], context_system::instance()->id, $admin);
        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $result['status']);
        $this->assertContains(taskflow_skill_base::ISSUE_USER_NOT_FOUND, $result['issue_codes']);
        $this->assertStringContainsString('Facility', $result['usermessage']);
        $this->assertArrayNotHasKey('assignments', $result);
        $this->assertArrayNotHasKey('total', $result);
    }

    /**
     * A userquery matching several people is ambiguous (with candidates), not a widened search.
     */
    public function test_ambiguous_userquery_is_hard_stop_with_candidates(): void {
        $admin = (int)get_admin()->id;
        $this->getDataGenerator()->create_user(['firstname' => 'Max', 'lastname' => 'Muster']);

        $run = $this->run_skill(['userquery' => 'Muster'], $admin);
        $this->assertSame('hard_block', $run['preflight']->status);
        $this->assertContains(taskflow_skill_base::ISSUE_USER_AMBIGUOUS, $run['preflight']->issuecodes);
        $this->assertNull($run['result']);
        $issue = $run['preflight']->issues[0];
        $this->assertStringContainsString('Muster', (string)$issue['message']);
        $this->assertCount(2, $issue['candidates']);

        $result = (new search_assignments_skill())->execute(['userquery' => 'Muster'], context_system::instance()->id, $admin);
        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $result['status']);
        $this->assertContains(taskflow_skill_base::ISSUE_USER_AMBIGUOUS, $result['issue_codes']);
        $this->assertArrayNotHasKey('assignments', $result);
    }

    /**
     * Create a unit of the own-table backend (optionally below a parent) and return its id.
     *
     * @param string $name
     * @param int $parentid
     * @return int
     */
    private function create_unit(string $name, int $parentid = 0): int {
        global $DB, $USER;
        $unitid = (int)$DB->insert_record('local_taskflow_units', (object)[
            'name' => $name,
            'description' => '',
            'criteria' => '',
            'timecreated' => time(),
            'timemodified' => time(),
            'usermodified' => (int)$USER->id,
        ]);
        if ($parentid > 0) {
            $DB->insert_record('local_taskflow_unit_rel', (object)[
                'childid' => $unitid,
                'parentid' => $parentid,
                'active' => 1,
                'timecreated' => time(),
                'timemodified' => time(),
                'usermodified' => (int)$USER->id,
            ]);
        }
        \local_taskflow\local\units\unit_hierarchy::invalidate_cache();
        return $unitid;
    }

    /**
     * Add a member to a unit of the own-table backend.
     *
     * @param int $unitid
     * @param int $userid
     */
    private function add_member(int $unitid, int $userid): void {
        global $DB, $USER;
        $DB->insert_record('local_taskflow_unit_members', (object)[
            'unitid' => $unitid,
            'userid' => $userid,
            'active' => 1,
            'timeadded' => time(),
            'timemodified' => time(),
            'usermodified' => (int)$USER->id,
        ]);
    }

    /**
     * unitid / unitquery filter by membership (sub-units included), not by the unit of the rule;
     * ruleunitid keeps the rule-unit semantics; unknown or ambiguous unit names are hard stops.
     */
    public function test_unit_filter_uses_membership_including_subunits(): void {
        global $DB;
        set_config('organisational_unit_option', 'unit', 'local_taskflow');
        $facility = $this->create_unit('Abteilung Facility');
        $teamnord = $this->create_unit('Team Nord', $facility);
        $other = $this->create_unit('Team Nordwest');
        // The employee is a member of the sub-unit only; the rule of their assignments sits on the parent.
        $this->add_member($teamnord, (int)$this->employee->id);
        $this->add_member($other, (int)$this->other->id);
        $DB->set_field('local_taskflow_assignment', 'unitid', $facility, ['id' => $this->employeea]);
        $DB->set_field('local_taskflow_assignment', 'unitid', $facility, ['id' => $this->employeeb]);
        $admin = (int)get_admin()->id;

        // Membership of the sub-unit: both assignments of the employee, regardless of the rule unit.
        $run = $this->run_skill(['unitid' => $teamnord], $admin);
        $this->assertSame('pass', $run['preflight']->status);
        $this->assertSame([$this->employeea, $this->employeeb], $this->ids($run['result']));
        $this->assertStringContainsString('Team Nord', implode(' ', $run['result']['filters']));

        // Parent unit includes the members of its sub-units; overdueonly narrows to the overdue one.
        $run = $this->run_skill(['unitquery' => 'facility', 'overdueonly' => true], $admin);
        $this->assertSame('pass', $run['preflight']->status);
        $this->assertSame([$this->employeeb], $this->ids($run['result']));

        // Exact name wins over the partial match "Team Nordwest".
        $run = $this->run_skill(['unitquery' => 'Team Nord'], $admin);
        $this->assertSame('pass', $run['preflight']->status);
        $this->assertSame([$this->employeea, $this->employeeb], $this->ids($run['result']));

        // Rule-unit semantics stay available under ruleunitid; a member-less unit yields nothing.
        $run = $this->run_skill(['ruleunitid' => $facility], $admin);
        $this->assertSame([$this->employeea, $this->employeeb], $this->ids($run['result']));
        $empty = $this->create_unit('Leer');
        $run = $this->run_skill(['unitid' => $empty], $admin);
        $this->assertSame([], $this->ids($run['result']));
        $this->assertSame(0, $run['result']['total']);

        // Ambiguous ("Nord" matches two units) and unknown names are hard stops on both paths.
        $run = $this->run_skill(['unitquery' => 'Nord'], $admin);
        $this->assertSame('hard_block', $run['preflight']->status);
        $this->assertSame(search_assignments_skill::ISSUE_UNIT_AMBIGUOUS, $run['preflight']->issues[0]['code']);
        $this->assertCount(2, $run['preflight']->issues[0]['candidates']);
        $this->assertStringContainsString('Team Nordwest', (string)$run['preflight']->issues[0]['message']);
        $skill = new search_assignments_skill();
        $result = $skill->execute(['unitquery' => 'Marketing'], context_system::instance()->id, $admin);
        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $result['status']);
        $this->assertSame([search_assignments_skill::ISSUE_UNIT_NOT_FOUND], $result['issue_codes']);
        $this->assertStringContainsString('Marketing', $result['detail']);
        $result = $skill->execute(['unitid' => 999999], context_system::instance()->id, $admin);
        $this->assertSame(taskflow_skill_base::STATUS_ERROR, $result['status']);
        $this->assertSame([search_assignments_skill::ISSUE_UNIT_NOT_FOUND], $result['issue_codes']);
    }

    /**
     * Planner spellings of the filter keys (user_id, due_before, overdue-only) map onto the schema keys.
     */
    public function test_alias_keys_are_canonicalized(): void {
        $admin = (int)get_admin()->id;
        $run = $this->run_skill(['user_id' => (int)$this->employee->id, 'overdue-only' => true], $admin);
        $this->assertSame('pass', $run['preflight']->status);
        $this->assertSame([$this->employeeb], $this->ids($run['result']));

        $skill = new search_assignments_skill();
        $result = $skill->execute(
            ['userId' => (int)$this->employee->id, 'due_before' => date('Y-m-d', time() - DAYSECS)],
            context_system::instance()->id,
            $admin
        );
        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status']);
        $this->assertSame([$this->employeeb], $this->ids($result));
    }

    /**
     * A status filter for paused finds paused assignments although they are stored inactive.
     */
    public function test_status_filter_includes_inactive_rows(): void {
        global $DB;
        $DB->update_record('local_taskflow_assignment', (object)[
            'id' => $this->othera,
            'status' => assignment_status_facade::get_status_identifier('paused'),
            'active' => 0,
        ]);
        $admin = (int)get_admin()->id;
        $run = $this->run_skill(['status' => ['paused']], $admin);
        $this->assertSame([$this->othera], $this->ids($run['result']));
        // Without a status filter the inactive row stays hidden; activeonly=true wins over the status default.
        $run = $this->run_skill([], $admin);
        $this->assertNotContains($this->othera, $this->ids($run['result']));
        $run = $this->run_skill(['status' => ['paused'], 'activeonly' => true], $admin);
        $this->assertSame([], $this->ids($run['result']));
    }

    /**
     * Status values outside the engine's value set are rejected on both paths; valid names/labels resolve.
     */
    public function test_unknown_status_value_is_rejected_on_both_paths(): void {
        $admin = (int)get_admin()->id;
        $pausedid = assignment_status_facade::get_status_identifier('paused');

        foreach (['pause', 'open'] as $value) {
            $run = $this->run_skill(['status' => [$value]], $admin);
            $this->assertSame('hard_block', $run['preflight']->status, $value);
            $this->assertContains(search_assignments_skill::ISSUE_STATUS_UNKNOWN, $run['preflight']->issuecodes, $value);
            $this->assertNull($run['result'], $value);
            $message = (string)$run['preflight']->issues[0]['message'];
            $this->assertStringContainsString($value, $message);
            $this->assertStringContainsString('paused', $message);

            // Raw execute(): "pause" must not be cast to status id 0 (= assigned) any more.
            $result = (new search_assignments_skill())->execute(['status' => [$value]], context_system::instance()->id, $admin);
            $this->assertSame(taskflow_skill_base::STATUS_ERROR, $result['status'], $value);
            $this->assertContains(search_assignments_skill::ISSUE_STATUS_UNKNOWN, $result['issue_codes'], $value);
            $this->assertArrayNotHasKey('assignments', $result, $value);
        }

        // Valid type label, localized name and id all resolve to the paused status.
        global $DB;
        $DB->set_field('local_taskflow_assignment', 'status', $pausedid, ['id' => $this->employeea]);
        foreach (['paused', assignment_status_facade::get_specific_names($pausedid), (string)$pausedid] as $value) {
            $run = $this->run_skill(['status' => [$value]], $admin);
            $this->assertSame('pass', $run['preflight']->status, $value);
            $this->assertSame([$pausedid], $run['preflight']->preparedinput['status'], $value);
            $this->assertSame([$this->employeea], $this->ids($run['result']), $value);
        }
        $result = (new search_assignments_skill())->execute(['status' => ['paused']], context_system::instance()->id, $admin);
        $this->assertSame(taskflow_skill_base::STATUS_EXECUTED, $result['status']);
        $this->assertSame([$this->employeea], $this->ids($result));
    }
}
