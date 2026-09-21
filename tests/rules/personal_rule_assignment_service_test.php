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

namespace local_taskflow\local\rules;

use advanced_testcase;
use local_taskflow\local\assignment_status\assignment_status_facade;

/**
 * Assigning rules and curricula to individual persons.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_taskflow\local\rules\personal_rule_assignment_service
 */
final class personal_rule_assignment_service_test extends advanced_testcase {
    /**
     * Setup.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('organisational_unit_option', 'cohort', 'local_taskflow');
        set_config('external_api_option', 'standard', 'local_taskflow');
    }

    /**
     * A curriculum (rule without audience) assigned on the person page creates an assignment
     * immediately; removing it drops the user out.
     */
    public function test_assign_and_unassign_curriculum(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $generator = $this->getDataGenerator()->get_plugin_generator('local_taskflow');
        $ruleid = $generator->create_rule([
            'name' => 'Leadership curriculum',
            'targets' => [['targettype' => 'moodlecourse', 'targetid' => $course->id, 'targetname' => $course->fullname]],
        ]);

        $service = new personal_rule_assignment_service();
        $service->assign($ruleid, (int)$user->id, 2, 'Onboarding wish');

        $this->assertSame([$ruleid], personal_rule_assignment_service::get_rule_ids_for_user((int)$user->id));
        $this->assertSame([(int)$user->id], personal_rule_assignment_service::get_user_ids_for_rule($ruleid));

        $assignment = $DB->get_record('local_taskflow_assignment', ['userid' => $user->id, 'ruleid' => $ruleid], '*', MUST_EXIST);
        $this->assertEquals(1, $assignment->active);
        $this->assertEquals(assignment_status_facade::get_status_identifier('assigned'), $assignment->status);
        $this->assertTrue(is_enrolled(\context_course::instance($course->id), $user->id));

        // Assigning twice keeps one row and one assignment.
        $service->assign($ruleid, (int)$user->id, 2, 'Second note');
        $this->assertEquals(1, $DB->count_records('local_taskflow_rule_users', ['ruleid' => $ruleid, 'userid' => $user->id]));
        $this->assertEquals(1, $DB->count_records('local_taskflow_assignment', ['userid' => $user->id, 'ruleid' => $ruleid]));
        $this->assertSame('Second note', $DB->get_field('local_taskflow_rule_users', 'annotation', ['ruleid' => $ruleid]));

        $service->unassign($ruleid, (int)$user->id);
        $this->assertSame([], personal_rule_assignment_service::get_rule_ids_for_user((int)$user->id));
        $assignment = $DB->get_record('local_taskflow_assignment', ['userid' => $user->id, 'ruleid' => $ruleid], '*', MUST_EXIST);
        $this->assertEquals(assignment_status_facade::get_status_identifier('droppedout'), $assignment->status);
        $this->assertEquals(0, $assignment->active);
    }

    /**
     * A rule saved for a unit also reaches the persons it was assigned to individually.
     */
    public function test_preprocessor_includes_personally_assigned_users(): void {
        global $DB;

        $cohort = $this->getDataGenerator()->create_cohort();
        $member = $this->getDataGenerator()->create_user();
        $outsider = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_taskflow_unit_members', (object)[
            'unitid' => $cohort->id, 'userid' => $member->id, 'active' => 1, 'timeadded' => time(),
        ]);
        $generator = $this->getDataGenerator()->get_plugin_generator('local_taskflow');
        $ruleid = $generator->create_rule(['name' => 'Unit rule', 'unitid' => $cohort->id]);
        $DB->insert_record('local_taskflow_rule_users', (object)[
            'ruleid' => $ruleid, 'userid' => $outsider->id, 'usermodified' => 2, 'timecreated' => time(), 'timemodified' => time(),
        ]);

        $rule = $DB->get_record('local_taskflow_rules', ['id' => $ruleid]);
        $preprocessor = new \local_taskflow\local\assignment_process\assignment_preprocessor((array)$rule);
        $preprocessor->set_affected_users();
        $users = array_map('intval', $preprocessor->get_affected_users());
        sort($users);
        $expected = [(int)$member->id, (int)$outsider->id];
        sort($expected);
        $this->assertSame($expected, $users);
    }

    /**
     * Removing a personal assignment keeps the assignment when the user still belongs to the rule's unit.
     */
    public function test_unassign_keeps_unit_assignment(): void {
        global $DB;

        $cohort = $this->getDataGenerator()->create_cohort();
        $member = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_taskflow_unit_members', (object)[
            'unitid' => $cohort->id, 'userid' => $member->id, 'active' => 1, 'timeadded' => time(),
        ]);
        $generator = $this->getDataGenerator()->get_plugin_generator('local_taskflow');
        $ruleid = $generator->create_rule(['name' => 'Unit rule', 'unitid' => $cohort->id]);

        $service = new personal_rule_assignment_service();
        $service->assign($ruleid, (int)$member->id, 2);
        $service->unassign($ruleid, (int)$member->id);

        $assignment = $DB->get_record('local_taskflow_assignment', ['userid' => $member->id, 'ruleid' => $ruleid], '*', MUST_EXIST);
        $this->assertEquals(1, $assignment->active);
        $this->assertNotEquals(assignment_status_facade::get_status_identifier('droppedout'), $assignment->status);
    }
}
