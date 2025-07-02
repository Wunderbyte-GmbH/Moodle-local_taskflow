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

namespace local_taskflow\assignment_operators;

use advanced_testcase;
use core_user;
use local_taskflow\local\adhoc_task_process\adhoc_task_controller;
use local_taskflow\local\assignment_process\assignments\assignments_controller;
use local_taskflow\local\assignment_process\filters\filters_controller;
use local_taskflow\local\messages\messages_factory;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/user/profile/lib.php');

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
        $ruleids = $this->set_rules_db($courseids, $messageids);
        $this->set_db_assignments($userid, $courseids, $messageids, $ruleids);
        $this->set_sheduled_message($userid, $messageids, $ruleids);
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

        $unitinfo = [
            'unitid' => 1,
            'role' => 'Krankenschwester',
            'since' => 23233232222,
            'exit' => 26233232222,
            'manager' => null,
        ];
        require_once(__DIR__ . '/../../../../user/lib.php');

        $newuserid = user_create_user((object)$user, false, false);
        $moodleuser = core_user::get_user($newuserid);
        $moodleuser->profile_field_unit_info = json_encode([$unitinfo]);
        profile_save_data($moodleuser);
        return $newuserid;
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
     * @param array $courses
     * @param array $messages
     * @return array
     */
    protected function set_rules_db($courses, $messages): array {
        global $DB;
        $rules = json_decode(file_get_contents(__DIR__ . '/../mock/rules/taskflow_rule_assignment.json'));
        $ruleids = [];
        foreach ($rules as $rulejson) {
            foreach ($rulejson->rulejson->rule->actions as $actions) {
                $actions->messages = self::change_messageids($actions->messages, $messages);
                $actions->targets = self::change_target_ids($actions->targets, $courses);
            }
            $rule = [
                'unitid' => 1,
                'rulename' => 'Testing rule',
                'rulejson' => json_encode($rulejson),
                'eventname' => 'Testing event',
                'isactive' => 1,
            ];
            $newrule = $DB->insert_record('local_taskflow_rules', $rule);
            $ruleids[] = $newrule;
        }
        return $ruleids;
    }

    /**
     * Setup the test environment.
     *
     * @param mixed $userid
     * @param mixed $courses
     * @param mixed $messages
     * @param mixed $ruleids
     *
     * @return void
     *
     */
    protected function set_db_assignments($userid, $courses, $messages, $ruleids): void {
        global $DB;
        $assignments = json_decode(file_get_contents(__DIR__ . '/../mock/assignments/assignments.json'));
        foreach ($assignments as $assignment) {
            $assignment->userid = $userid;
            $assignment->messages = json_encode(self::change_messageids($assignment->messages, $messages));
            $assignment->targets = json_encode(self::change_target_ids($assignment->targets, $courses));
            $randomkey = array_rand($ruleids);
            $assignment->ruleid = $ruleids[$randomkey];
            $DB->insert_record('local_taskflow_assignment', $assignment);
        }
    }

    /**
     * Setup the test environment.
     * @param int $userid
     * @param array $messages
     * @param array $ruleids
     */
    protected function set_sheduled_message($userid, $messages, $ruleids) {
        global $DB;
        $record = [
            'component' => 'local_taskflow',
            'classname' => '\local_taskflow\sheduled_tasks\send_taskflow_message',
            'nextruntime' => time(),
        ];
        $customdata = [
            'userid' => $userid,
            'messageid' => null,
            'ruleid' => null,
        ];
        foreach ($messages as $message) {
            $customdata['messageid'] = $message;
            foreach ($ruleids as $ruleid) {
                $customdata['ruleid'] = $ruleid;
                $record['customdata'] = json_encode($customdata);
                $DB->insert_record('task_adhoc', (object)$record);
            }
        }
    }

    /**
     * Setup the test environment.
     * @param array $messages
     * @param array $messageids
     */
    protected function change_messageids(&$messages, $messageids): array {
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
     * @covers \local_taskflow\local\actions\types\enroll
     * @covers \local_taskflow\local\actions\actions_factory
     * @covers \local_taskflow\local\adhoc_task_process\adhoc_task_controller
     * @covers \local_taskflow\local\assignments\types\standard_assignment
     * @covers \local_taskflow\local\messages\messages_factory
     * @covers \local_taskflow\local\messages\types\standard
     * @covers \local_taskflow\local\messages\types\standard
     * @covers \local_taskflow\local\assignment_operators\filter_operator
     * @covers \local_taskflow\local\assignment_operators\action_operator
     * @covers \local_taskflow\local\rules\rules
     * @covers \local_taskflow\local\assignment_operators\assignment_operator
     * @covers \local_taskflow\local\assignment_process\assignments\assignments_controller
     * @covers \local_taskflow\local\actions\actions_factory
     * @covers \local_taskflow\local\assignment_operators\filter_operator
     */
    public function test_external_data_is_loaded(): void {
        global $DB;
        $this->assertEquals($DB->count_records('user'), 3);
        $this->assertEquals($DB->count_records('local_taskflow_assignment'), 3);
        $this->assertEquals($DB->count_records('course'), 3);
        $this->assertEquals($DB->count_records('local_taskflow_messages'), 4);
        $cassignment = new adhoc_task_controller(
            new assignments_controller(),
            new filters_controller(),
            new messages_factory()
        );
        $cassignment->process_assignments();

        $this->allow_db_commit();
    }

    /**
     * Allow db commits to happen
     */
    private function allow_db_commit() {
        sleep(1);
    }
}
