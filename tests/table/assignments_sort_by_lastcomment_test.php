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

namespace local_taskflow\table;

use advanced_testcase;
use local_taskflow\local\assignments\assignment;
use stdClass;
use tool_mocktesttime\time_mock;

/**
 * Sorting the chat column of the assignments dashboard by the latest internal message.
 *
 * @package     local_taskflow
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class assignments_sort_by_lastcomment_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        $this->resetAfterTest(true);
        \local_taskflow\local\units\unit_relations::reset_instances();
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');

        $plugingenerator->create_custom_profile_fields([
            'supervisor',
            'units',
            'externalid',
        ]);
        $plugingenerator->set_config_values('standard');
    }

    /**
     * Insert one internal chat message for an assignment.
     *
     * @param int $assignmentid
     * @param int $userid
     * @param string $message
     * @param int $timecreated
     * @return int id of the inserted message
     */
    private function add_message(int $assignmentid, int $userid, string $message, int $timecreated): int {
        global $DB;
        return $DB->insert_record('local_taskflow_int_com', (object)[
            'assignmentid' => $assignmentid,
            'message' => $message,
            'usermodified' => $userid,
            'timecreated' => $timecreated,
            'timemodified' => $timecreated,
        ]);
    }

    /**
     * The chat column carries the id of the newest internal message, so sorting on
     * it orders assignments by the recency of their latest chat message. Assignments
     * without messages sort last (value 0).
     *
     * @covers \local_taskflow\local\assignments\assignment::return_user_assignments_sql
     */
    public function test_sorting_by_chat_column_orders_by_latest_message(): void {
        global $DB;

        $generator = self::getDataGenerator();
        $plugingenerator = $generator->get_plugin_generator('local_taskflow');
        $ruleid = $plugingenerator->create_rule();

        $users = [];
        for ($i = 0; $i < 4; $i++) {
            $users[$i] = $generator->create_user();
            $plugingenerator->create_user_assignment($users[$i]->id, $ruleid);
        }

        $assignmentids = [];
        foreach ($users as $i => $user) {
            $assignmentids[$i] = (int)$DB->get_field(
                'local_taskflow_assignment',
                'id',
                ['userid' => $user->id],
                MUST_EXIST
            );
        }

        $now = time();
        // Assignment 0: an old message AND the newest message overall.
        $this->add_message($assignmentids[0], $users[0]->id, 'old opener', $now - 3600);
        // Assignment 1: one message in the middle.
        $this->add_message($assignmentids[1], $users[1]->id, 'middle message', $now - 1800);
        // Assignment 2: two messages within the SAME second (id decides, deterministically).
        $this->add_message($assignmentids[2], $users[2]->id, 'same second one', $now - 900);
        $this->add_message($assignmentids[2], $users[2]->id, 'same second two', $now - 900);
        // Assignment 0 gets the newest message AFTER assignment 2 wrote its pair.
        $this->add_message($assignmentids[0], $users[0]->id, 'freshest reply', $now - 60);
        // Assignment 3: no messages at all.

        $assignments = assignment::get_instance();
        [$select, $from, $where, $params] = $assignments->return_user_assignments_sql(0, 1);

        $rows = $DB->get_records_sql(
            "SELECT {$select} FROM {$from} WHERE {$where} ORDER BY lastinternalcomment DESC",
            $params
        );
        $order = array_values(array_map(fn($row) => (int)$row->id, $rows));
        $this->assertSame(
            [$assignmentids[0], $assignmentids[2], $assignmentids[1], $assignmentids[3]],
            $order,
            'DESC must order by recency of the latest message, chatless assignments last.'
        );

        $rows = $DB->get_records_sql(
            "SELECT {$select} FROM {$from} WHERE {$where} ORDER BY lastinternalcomment ASC",
            $params
        );
        $order = array_values(array_map(fn($row) => (int)$row->id, $rows));
        $this->assertSame(
            [$assignmentids[3], $assignmentids[1], $assignmentids[2], $assignmentids[0]],
            $order,
            'ASC is the exact inverse, chatless assignments first.'
        );

        // The sort value itself is exposed on the column and the blob still carries the text.
        $rows = $DB->get_records_sql("SELECT {$select} FROM {$from} WHERE {$where}", $params);
        foreach ($rows as $row) {
            if ((int)$row->id === $assignmentids[3]) {
                $this->assertEquals(0, $row->lastinternalcomment);
                $this->assertEmpty($row->lastinternalcommentblob);
            }
            if ((int)$row->id === $assignmentids[0]) {
                $this->assertGreaterThan(0, $row->lastinternalcomment);
                $this->assertStringContainsString('freshest reply', $row->lastinternalcommentblob);
                // The blob keeps the newest message first for the preview.
                $this->assertLessThan(
                    strpos($row->lastinternalcommentblob, 'old opener'),
                    strpos($row->lastinternalcommentblob, 'freshest reply')
                );
            }
        }
    }

    /**
     * The renderer reads the blob field and is unaffected by the numeric sort column.
     *
     * @covers \local_taskflow\table\assignments_table::col_lastinternalcomment
     */
    public function test_renderer_reads_blob_field(): void {
        $this->setAdminUser();
        $table = new assignments_table('testtable');

        $row = new stdClass();
        $row->id = 4242;
        $row->lastinternalcomment = 17;
        $row->lastinternalcommentblob = '5 | Jane Doe | ' . time() . ' | hello from the chat';
        $this->assertStringContainsString('hello from the chat', $table->col_lastinternalcomment($row));

        $empty = new stdClass();
        $empty->id = 4243;
        $empty->lastinternalcomment = 0;
        $empty->lastinternalcommentblob = null;
        $this->assertSame(
            get_string('nocomments', 'local_taskflow'),
            $table->col_lastinternalcomment($empty)
        );
    }
}
