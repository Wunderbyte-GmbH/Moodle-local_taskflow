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

namespace local_taskflow\assignments;

use advanced_testcase;
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\assignments\assignment;
use local_taskflow\local\assignments\assignment_manual_update_service;
use local_taskflow\local\assignments\status\assignment_status;
use local_taskflow\local\history\history;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/user/profile/lib.php');

/**
 * Test the assignment_manual_update_service.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_taskflow\local\assignments\assignment_manual_update_service
 */
final class assignment_manual_update_service_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        $this->resetAfterTest(true);
        \local_taskflow\local\units\unit_relations::reset_instances();
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingenerator->teardown();
    }

    /**
     * Creates a user with one assignment and returns [userid, assignmentid].
     *
     * @param array $ruleoptions
     * @return array
     */
    private function create_assignment(array $ruleoptions = []): array {
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingenerator->set_config_values('standard');

        $user = $this->getDataGenerator()->create_user();
        $this->setAdminUser();

        $ruleid = $plugingenerator->create_rule($ruleoptions);
        $assignment = $plugingenerator->create_user_assignment($user->id, $ruleid);

        return [$user->id, (int)$assignment->id];
    }

    /**
     * Given an assigned assignment, when the status is changed manually,
     * then the new status is stored and a manual_change history entry exists.
     */
    public function test_apply_changes_status_and_logs_history(): void {
        global $DB, $USER;

        [$userid, $assignmentid] = $this->create_assignment();
        $paused = assignment_status_facade::get_status_identifier('paused');

        $service = new assignment_manual_update_service();
        $result = $service->apply(
            $assignmentid,
            [
                'status' => $paused,
                'change_reason' => assignment_status::CHANGEREASON_SICKNESS,
                'keepchanges' => 1,
            ],
            $USER->id,
            'On sick leave'
        );

        $this->assertEquals($paused, (int)$result->status);

        $record = $DB->get_record('local_taskflow_assignment', ['id' => $assignmentid]);
        $this->assertEquals($paused, (int)$record->status);
        $this->assertEquals(1, (int)$record->keepchanges);
        $this->assertEquals($USER->id, (int)$record->usermodified);

        $entries = $DB->get_records('local_taskflow_history', [
            'assignmentid' => $assignmentid,
            'type' => history::TYPE_MANUAL_CHANGE,
        ]);
        $this->assertNotEmpty($entries);
        $entry = reset($entries);
        $this->assertStringContainsString('On sick leave', $entry->annotation);
        $this->assertEquals($userid, (int)$entry->userid);
    }

    /**
     * Given an assignment, when a new due date is applied, then it is stored.
     */
    public function test_apply_changes_duedate(): void {
        global $DB, $USER;

        [, $assignmentid] = $this->create_assignment();
        $newduedate = time() + WEEKSECS;

        $service = new assignment_manual_update_service();
        $service->apply($assignmentid, ['duedate' => $newduedate], $USER->id, 'later');

        $record = $DB->get_record('local_taskflow_assignment', ['id' => $assignmentid]);
        $this->assertEquals($newduedate, (int)$record->duedate);
    }

    /**
     * Given an overdue assignment, when the due date is moved into the future,
     * then the assignment becomes prolonged (intended behaviour of
     * assignment::set_prolonged_state_on_change()).
     */
    public function test_apply_sets_prolonged_when_overdue_duedate_moves_into_future(): void {
        global $DB, $USER;

        [, $assignmentid] = $this->create_assignment();
        $overdue = assignment_status_facade::get_status_identifier('overdue');
        $prolonged = assignment_status_facade::get_status_identifier('prolonged');

        $DB->set_field('local_taskflow_assignment', 'status', $overdue, ['id' => $assignmentid]);
        $DB->set_field('local_taskflow_assignment', 'duedate', time() - DAYSECS, ['id' => $assignmentid]);
        assignment::destroy_instance($assignmentid);

        $service = new assignment_manual_update_service();
        $result = $service->apply($assignmentid, ['duedate' => time() + WEEKSECS], $USER->id, 'extended');

        $this->assertEquals($prolonged, (int)$result->status);
        $record = $DB->get_record('local_taskflow_assignment', ['id' => $assignmentid]);
        $this->assertEquals($prolonged, (int)$record->status);
        $this->assertEquals(1, (int)$record->prolongedcounter);
    }

    /**
     * Given an adapter that excludes a status and a caller that asks for the
     * excluded statuses to be respected, when that status is requested,
     * then the status is not changed.
     */
    public function test_apply_respects_excluded_status_on_demand(): void {
        global $DB, $USER;

        [, $assignmentid] = $this->create_assignment();
        $paused = assignment_status_facade::get_status_identifier('paused');
        set_config('excludestatus', (string)$paused, 'taskflowadapter_standard');

        $before = $DB->get_field('local_taskflow_assignment', 'status', ['id' => $assignmentid]);

        $service = new assignment_manual_update_service();
        $service->apply(
            $assignmentid,
            ['status' => $paused, 'respectexcluded' => true],
            $USER->id,
            'nope'
        );

        $record = $DB->get_record('local_taskflow_assignment', ['id' => $assignmentid]);
        $this->assertEquals((int)$before, (int)$record->status);
    }

    /**
     * Given an adapter that excludes a status, when the form path (which never offers
     * excluded statuses) applies it, then the legacy behaviour is kept and it is written.
     */
    public function test_apply_writes_excluded_status_without_the_flag(): void {
        global $DB, $USER;

        [, $assignmentid] = $this->create_assignment();
        $paused = assignment_status_facade::get_status_identifier('paused');
        set_config('excludestatus', (string)$paused, 'taskflowadapter_standard');

        $service = new assignment_manual_update_service();
        $service->apply($assignmentid, ['status' => $paused], $USER->id, 'legacy');

        $record = $DB->get_record('local_taskflow_assignment', ['id' => $assignmentid]);
        $this->assertEquals($paused, (int)$record->status);
    }

    /**
     * Given an assignment, when an extension is granted,
     * then the due date moves, the status is prolonged and the counter is raised.
     */
    public function test_grant_extension(): void {
        global $DB, $USER;

        [, $assignmentid] = $this->create_assignment();
        $prolonged = assignment_status_facade::get_status_identifier('prolonged');
        $newduedate = time() + (2 * WEEKSECS);

        $service = new assignment_manual_update_service();
        $result = $service->grant_extension($assignmentid, $newduedate, $USER->id, 'granted');

        $this->assertEquals($prolonged, (int)$result->status);

        $record = $DB->get_record('local_taskflow_assignment', ['id' => $assignmentid]);
        $this->assertEquals($newduedate, (int)$record->duedate);
        $this->assertEquals($prolonged, (int)$record->status);
        $this->assertEquals(1, (int)$record->prolongedcounter);

        $entries = $DB->get_records('local_taskflow_history', [
            'assignmentid' => $assignmentid,
            'type' => history::TYPE_MANUAL_CHANGE,
        ]);
        $this->assertNotEmpty($entries);
    }

    /**
     * Given an assignment, when an extension is denied,
     * then the due date and status stay and only the prolongedcounter is raised.
     */
    public function test_deny_extension(): void {
        global $DB, $USER;

        [, $assignmentid] = $this->create_assignment();
        $before = $DB->get_record('local_taskflow_assignment', ['id' => $assignmentid]);

        $service = new assignment_manual_update_service();
        $service->deny_extension($assignmentid, $USER->id, 'denied');

        $record = $DB->get_record('local_taskflow_assignment', ['id' => $assignmentid]);
        $this->assertEquals((int)$before->duedate, (int)$record->duedate);
        $this->assertEquals((int)$before->status, (int)$record->status);
        $this->assertEquals((int)$before->prolongedcounter + 1, (int)$record->prolongedcounter);
    }

    /**
     * Given an unknown assignment id, when apply is called, then an exception is thrown.
     */
    public function test_apply_throws_for_unknown_assignment(): void {
        global $USER;

        $this->setAdminUser();
        $this->expectException(\moodle_exception::class);
        (new assignment_manual_update_service())->apply(999999, ['status' => 0], $USER->id);
    }
}
