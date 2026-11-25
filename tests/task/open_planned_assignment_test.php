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

namespace local_taskflow\task;

use advanced_testcase;
use local_taskflow\local\assignment_status\assignment_status_facade;

/**
 * Opening a planned assignment must not revive an assignment which is not planned anymore.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class open_planned_assignment_test extends advanced_testcase {
    /**
     * A dropped out assignment stays dropped out and no update of the assignment is queued.
     *
     * @covers \local_taskflow\task\open_planned_assignment
     * @covers \local_taskflow\local\assignments\assignments_facade::open_planned_assignment
     */
    public function test_dropped_out_assignment_is_not_opened(): void {
        global $DB;
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $droppedout = assignment_status_facade::get_status_identifier('droppedout');
        $assignmentid = $DB->insert_record('local_taskflow_assignment', (object)[
            'userid' => $user->id,
            'ruleid' => 1,
            'unitid' => 1,
            'active' => 0,
            'status' => $droppedout,
            'timecreated' => 100,
            'timemodified' => 100,
        ]);

        $task = new open_planned_assignment();
        $task->set_custom_data([
            'assignmentid' => $assignmentid,
            'userid' => $user->id,
            'id' => 1,
        ]);
        $task->execute();

        $assignment = $DB->get_record('local_taskflow_assignment', ['id' => $assignmentid]);
        $this->assertSame($droppedout, (int)$assignment->status);
        $this->assertSame(0, (int)$assignment->active);
        $this->assertNull($assignment->assigneddate);
        $this->assertSame(100, (int)$assignment->timemodified);
        $this->assertFalse(
            $DB->record_exists('task_adhoc', ['classname' => '\\' . update_assignment::class])
        );
    }
}
