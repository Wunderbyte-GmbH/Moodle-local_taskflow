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

namespace local_taskflow\assignment_process;

use advanced_testcase;
use cache_helper;
use local_taskflow\local\repositories\external_api_repository;

/**
 * Test unit class of local_taskflow.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class adhoc_process_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        \local_taskflow\local\units\unit_relations::reset_instances();
        $userid = $this->set_db_user();
        $courseids = $this->set_courses_db();
        $messageids = $this->set_messages_db();
        $this->set_db_assignments($userid, $courseids, $messageids);
    }

    /**
     * Setup the test environment.
     */
    protected function set_db_user(): int {
        $user = [
            'auth' => 'manual',
            'confirmed' => 1,
            'username' => 'testuser9999',
            'password' => 'P@ssword1',
            'firstname' => 'Test',
            'lastname' => 'User',
            'email' => 'testuser9999@example.com',
            'mnethostid' => 1,
        ];

        require_once(__DIR__ . '/../../../../user/lib.php');
        return user_create_user((object)$user, false, false);
    }

    /**
     * Setup the test environment.
     */
    protected function set_courses_db(): array {
        global $DB;
        $courses = json_decode(file_get_contents(__DIR__ . '/../mock/courses/courses.json'));
        $courseids = [];
        foreach ($courses as $course) {
            $newcourse = create_course((object)$course);
            $courseids[] = $newcourse->id;
        }
        return $courseids;
    }

    /**
     * Setup the test environment.
     * @param int $userid
     * @param array $courses
     * @param array $messages
     */
    protected function set_db_assignments($userid, $courses, $messages): void {
        global $DB;
        $assignments = json_decode(file_get_contents(__DIR__ . '/../mock/assignments/assignments.json'));
        foreach ($assignments as $assignment) {
            $assignment->userid = $userid;
            $assignment->messages = json_encode(self::change_message_ids($assignment->messages, $messages));
            $assignment->targets = json_encode(self::change_target_ids($assignment->targets, $courses));
            $DB->insert_record('local_taskflow_assignment', $assignment);
        }
    }

    /**
     * Setup the test environment.
     * @param array $messages
     * @param array $messageids
     */
    protected function change_message_ids(&$messages, $messageids): array {
        foreach ($messages as $message) {
            $randomkey = array_rand($messageids);
            $message->messageid = $messageids[$randomkey];
        }
        return $messages;
    }

    /**
     * Setup the test environment.
     * @param array $targets
     * @param array $courseids
     */
    protected function change_target_ids($targets, $courseids): array {
        foreach ($targets as $target) {
            $randomkey = array_rand($courseids);
            $target->targetid = $courseids[$randomkey];
        }
        return $targets;
    }

    /**
     * Setup the test environment.
     */
    protected function set_messages_db(): array {
        global $DB;
        $messageids = [];
        $messages = json_decode(file_get_contents(__DIR__ . '/../mock/messages/messages.json'));
        foreach ($messages as $message) {
            $messageids[] = $DB->insert_record('local_taskflow_messages', $message);
        }
        return $messageids;
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\external_adapter\adapters\external_thour_api
     */
    public function test_external_data_is_loaded(): void {
        global $DB;
        $this->assertEquals($DB->count_records('user'), 3);
        $this->assertEquals($DB->count_records('local_taskflow_assignment'), 3);
        $this->assertEquals($DB->count_records('course'), 3);
        $this->assertEquals($DB->count_records('local_taskflow_messages'), 2);
    }
}
