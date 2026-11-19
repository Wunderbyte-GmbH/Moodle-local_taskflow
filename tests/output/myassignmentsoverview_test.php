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

namespace local_taskflow\output;

use advanced_testcase;
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\shortcodes;

/**
 * Tests for the [myassignmentsdashboard] shortcode and its output class.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_taskflow\output\myassignmentsoverview
 * @covers \local_taskflow\shortcodes::myassignmentsdashboard
 */
final class myassignmentsoverview_test extends advanced_testcase {
    /**
     * Inserts an assignment for the user.
     *
     * @param int $userid
     * @param int $ruleid
     * @param string $status Status type, e.g. 'overdue'.
     * @param array $fields Further fields of the record.
     * @return int
     */
    private function create_assignment(int $userid, int $ruleid, string $status, array $fields = []): int {
        global $DB;
        return $DB->insert_record('local_taskflow_assignment', (object)array_merge([
            'userid' => $userid,
            'ruleid' => $ruleid,
            'unitid' => 1,
            'active' => 1,
            'status' => assignment_status_facade::get_status_identifier($status),
            'assigneddate' => time(),
            'timecreated' => time(),
            'timemodified' => time(),
        ], $fields));
    }

    /**
     * Key figures, sort order, target progress and the next due assignment.
     */
    public function test_export_for_template(): void {
        global $DB, $PAGE;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $otheruser = $this->getDataGenerator()->create_user();
        $ruleid = $DB->insert_record('local_taskflow_rules', (object)['rulename' => 'Rule A', 'rulejson' => '{}']);

        $targets = json_encode([
            ['targettype' => 'moodlecourse', 'targetid' => 1, 'targetname' => 'Course 1', 'completionstatus' => 1],
            ['targettype' => 'moodlecourse', 'targetid' => 2, 'targetname' => 'Course 2', 'completionstatus' => 0],
        ]);
        $openid = $this->create_assignment($user->id, $ruleid, 'assigned', [
            'duedate' => time() + 10 * DAYSECS,
            'targets' => $targets,
        ]);
        $overdueid = $this->create_assignment($user->id, $ruleid, 'overdue', ['duedate' => time() - 3 * DAYSECS]);
        $this->create_assignment($user->id, $ruleid, 'completed', ['completeddate' => time()]);
        // Not shown: inactive, planned, and assignments of other users.
        $this->create_assignment($user->id, $ruleid, 'assigned', ['active' => 0]);
        $this->create_assignment($user->id, $ruleid, 'planned');
        $this->create_assignment($otheruser->id, $ruleid, 'assigned');

        $overview = new myassignmentsoverview($user->id);
        $data = $overview->export_for_template($PAGE->get_renderer('local_taskflow'));

        $this->assertTrue($data['hasassignments']);
        $this->assertCount(3, $data['assignments']);
        $this->assertEquals(
            ['overdue', 'open', 'completed'],
            array_column($data['assignments'], 'state')
        );
        $kpis = array_column($data['kpis'], 'value', 'key');
        $this->assertEquals(['open' => 1, 'overdue' => 1, 'completed' => 1], $kpis);

        // 1 of 2 targets completed.
        $this->assertEquals(50, $data['progress']);
        $this->assertEquals(50, $data['assignments'][1]['percent']);
        $this->assertEquals($openid, $data['assignments'][1]['id']);

        // Search text: rule name and target names, lower case. Completed assignments are shown by default.
        $this->assertEquals('rule a course 1 course 2', $data['assignments'][1]['searchtext']);
        $this->assertFalse($data['hidecompleted']);
        $this->assertTrue((new myassignmentsoverview($user->id, ['hidecompleted' => 1]))
            ->export_for_template($PAGE->get_renderer('local_taskflow'))['hidecompleted']);

        // The overdue assignment has the earliest due date.
        $this->assertTrue($data['hasnextdue']);
        $this->assertEquals($overdueid, $data['nextdue']['id']);
        $this->assertTrue($data['nextdue']['isoverdue']);
    }

    /**
     * Shortcode output with and without assignments; userid only for users who may edit assignments.
     */
    public function test_shortcode_renders_output(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $otheruser = $this->getDataGenerator()->create_user();
        $ruleid = $DB->insert_record('local_taskflow_rules', (object)['rulename' => 'Rule of other', 'rulejson' => '{}']);
        $this->create_assignment($otheruser->id, $ruleid, 'assigned', ['duedate' => time() + DAYSECS]);

        $this->setUser($user);
        $next = fn($a) => $a;
        $args = ['userid' => $otheruser->id];
        $output = shortcodes::myassignmentsdashboard('myassignmentsdashboard', $args, null, (object)[], $next);
        $this->assertStringContainsString('local-taskflow-mydashboard', $output);
        $this->assertStringContainsString(get_string('mydashboard:noassignments', 'local_taskflow'), $output);
        $this->assertStringNotContainsString('Rule of other', $output);

        $this->setAdminUser();
        $args = ['userid' => $otheruser->id];
        $output = shortcodes::myassignmentsdashboard('myassignmentsdashboard', $args, null, (object)[], $next);
        $this->assertStringContainsString('Rule of other', $output);
        $this->assertStringContainsString(get_string('mydashboard:duetomorrow', 'local_taskflow'), $output);
    }
}
