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

namespace local_taskflow\history;

use advanced_testcase;
use local_taskflow\local\history\history;

/**
 * Test unit class of local_taskflow.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class history_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\history\history
     */
    public function test_history(): void {
        global $DB;
        $this->resetAfterTest(true);

        // Create a fake assignment and user.
        $user = $this->getDataGenerator()->create_user();
        $assignmentid = 12345;

        // Insert a history entry.
        $data = ['somekey' => 'somevalue'];
        $historyid = history::log($assignmentid, $user->id, history::TYPE_MESSAGE, $data);

        $this->assertNotEmpty($historyid);

        // Call get_history.
        $results = history::get_history($assignmentid);
        $this->assertNotEmpty($results);
        $this->assertArrayHasKey($historyid, $results);

        $record = $results[$historyid];
        $this->assertEquals($assignmentid, $record->assignmentid);
        $this->assertEquals($user->id, $record->userid);
        $this->assertEquals(history::TYPE_MESSAGE, $record->type);

        // Call return_sql directly and validate its structure.
        [$select, $from, $where, $params] = history::return_sql($assignmentid, $user->id, history::TYPE_MESSAGE, 10);

        $this->assertEquals('*', $select);
        $this->assertEquals('{local_taskflow_history}', $from);
        $this->assertStringContainsString('assignmentid = :assignmentid', $where);
        $this->assertStringContainsString('userid = :userid', $where);
        $this->assertStringContainsString('type = :historytype', $where);

        $this->assertEquals($assignmentid, $params['assignmentid']);
        $this->assertEquals($user->id, $params['userid']);
        $this->assertEquals(history::TYPE_MESSAGE, $params['historytype']);
    }
}
