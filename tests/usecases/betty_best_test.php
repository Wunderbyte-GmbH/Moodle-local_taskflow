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

namespace local_taskflow\usecases;

use advanced_testcase;
use context_course;
use local_taskflow\event\rule_created_updated;

/**
 * Test unit class of local_taskflow.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
final class betty_best_test extends advanced_testcase {
    /** @var string|null Stores the external user data. */
    protected ?string $externaldata = null;

    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        \local_taskflow\local\units\unit_relations::reset_instances();
        \local_taskflow\local\rules\rules::reset_instances();
        $this->create_custom_profile_field();
    }

    /**
     * Setup the test environment.
     */
    private function create_custom_profile_field(): int {
        global $DB;
        $shortname = 'supervisor';
        $name = ucfirst($shortname);

        $field = (object)[
            'shortname' => $shortname,
            'name' => $name,
            'datatype' => 'text',
            'description' => '',
            'descriptionformat' => FORMAT_HTML,
            'categoryid' => 1,
            'sortorder' => 0,
            'required' => 0,
            'locked' => 0,
            'visible' => 1,
            'forceunique' => 0,
            'signup' => 0,
            'defaultdata' => '',
            'defaultdataformat' => FORMAT_HTML,
            'param1' => '',
            'param2' => '',
            'param3' => '',
            'param4' => '',
            'param5' => '',
        ];

        return $DB->insert_record('user_info_field', $field);
    }

    /**
     * Setup the test environment.
     */
    protected function set_db_user(): mixed {
        global $DB;
        // Create a user.
        $user = $this->getDataGenerator()->create_user([
            'firstname' => 'Betty',
            'lastname' => 'Best',
            'email' => 'betty@example.com',
        ]);

        $fieldid = $DB->get_field('user_info_field', 'id', ['shortname' => 'supervisor'], MUST_EXIST);
        $DB->insert_record('user_info_data', (object)[
            'userid' => $user->id,
            'fieldid' => $fieldid,
            'data' => '124', // This value will be matched against in the rule's filter.
            'dataformat' => FORMAT_HTML,
        ]);
        return $user;
    }

    /**
     * Setup the test environment.
     * @return object
     */
    protected function set_db_course(): mixed {
        // Create a user.
        $course = $this->getDataGenerator()->create_course([
            'fullname' => 'Test Course',
            'shortname' => 'TC101',
            'category' => 1,
            'enablecompletion' => 1,
        ]);
        return $course;
    }

    /**
     * Setup the test environment.
     * @param int $courseid
     * @param int $userid
     */
    protected function course_completed($courseid, $userid): void {
        $completion = new \completion_completion([
            'course' => $courseid,
            'userid' => $userid,
        ]);
        $completion->mark_complete();
    }

    /**
     * Setup the test environment.
     * @return object
     */
    protected function set_db_cohort(): mixed {
        // Create a user.
        $cohort = $this->getDataGenerator()->create_cohort([
            'name' => 'Test Cohort',
            'idnumber' => 'cohort123',
            'contextid' => \context_system::instance()->id,
        ]);
        return $cohort;
    }

    /**
     * Setup the test environment.
     * @param int $userid
     * @param int $courseid
     * @param array $messageids
     * @return array
     */
    public function get_rule($unitid, $courseid, $messageids): array {
        $rule = [
            "unitid" => $unitid,
            "rulename" => "test_rule",
            "rulejson" => json_encode((object)[
                "rulejson" => [
                    "rule" => [
                        "name" => "test_rule",
                        "description" => "test_rule_description",
                        "type" => "taskflow",
                        "enabled" => true,
                        "duedatetype" => "duration",
                        "fixeddate" => 23233232222,
                        "duration" => 23233232222,
                        "timemodified" => 23233232222,
                        "timecreated" => 23233232222,
                        "usermodified" => 1,
                        "filter" => [
                            [
                                "filtertype" => "user_profile_field",
                                "userprofilefield" => "supervisor",
                                "operator" => "equals",
                                "value" => "124",
                                "key" => "role",
                            ],
                        ],
                        "actions" => [
                            [
                                "targets" => [
                                    [
                                        "targetid" => $courseid,
                                        "targettype" => "moodlecourse",
                                        "targetname" => "mytargetname2",
                                        "sortorder" => 2,
                                        "actiontype" => "enroll",
                                        "completebeforenext" => false,
                                    ],
                                ],
                                "messages" => $messageids,
                            ],
                        ],
                    ],
                ],
            ]),
            "isactive" => "1",
            "userid" => "0",
        ];
        return $rule;
    }

    /**
     * Setup the test environment.
     */
    protected function set_messages_db(): array {
        global $DB;
        $messageids = [];
        $messages = json_decode(file_get_contents(__DIR__ . '/../mock/messages/messages.json'));
        foreach ($messages as $message) {
            $messageids[] = (object)['messageid' => $DB->insert_record('local_taskflow_messages', $message)];
        }
        return $messageids;
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\completion_process\completion_operator
     * @covers \local_taskflow\local\completion_process\types\bookingoption
     * @covers \local_taskflow\local\completion_process\types\competency
     * @covers \local_taskflow\local\completion_process\types\moodlecourse
     * @covers \local_taskflow\local\completion_process\types\types_base
     * @covers \local_taskflow\local\history\history
     * @covers \local_taskflow\event\assignment_completed
     * @covers \local_taskflow\observer
     * @covers \local_taskflow\sheduled_tasks\send_taskflow_message
     * @covers \local_taskflow\local\assignments\status\assignment_status
     */
    public function test_betty_best(): void {
        global $DB;
        $user = $this->set_db_user();
        $course = $this->set_db_course();
        $cohort = $this->set_db_cohort();
        $messageids = $this->set_messages_db();
        cohort_add_member($cohort->id, $user->id);
        $rule = $this->get_rule($cohort->id, $course->id, $messageids);
        $id = $DB->insert_record('local_taskflow_rules', $rule);
        $rule['id'] = $id;

        $event = rule_created_updated::create([
            'objectid' => $rule['id'],
            'context'  => \context_system::instance(),
            'other'    => [
                'ruledata' => $rule,
            ],
        ]);
        \local_taskflow\observer::call_event_handler($event);
        $assignment = $DB->get_records('local_taskflow_assignment');
        $this->assertNotEmpty($assignment);

        // Complete course.
        $coursecontext = context_course::instance($course->id);
        $this->assertTrue(is_enrolled($coursecontext, $user->id));
        $this->course_completed($course->id, $user->id);

        $taskadhocmessages = $DB->get_records('task_adhoc');
        $this->assertNotEmpty($taskadhocmessages);

        $assignmenthistory = $DB->get_records('local_taskflow_history');
        $this->assertNotEmpty($assignmenthistory);
        $this->assertCount(2, $assignmenthistory);

        foreach ($taskadhocmessages as $taskadhocmessage) {
            $task = \core\task\manager::adhoc_task_from_record($taskadhocmessage);

            // Acquire and assign the lock (required for ->release()).
            $lockfactory = \core\lock\lock_config::get_lock_factory('core_cron');
            $lock = $lockfactory->get_lock('adhoc_task_' . $task->get_id(), 120);
            $task->set_lock($lock);

            $task->execute();
            \core\task\manager::adhoc_task_complete($task);
        }
        $sendmessages = $DB->get_records('local_taskflow_messages');
        $this->assertNotEmpty($sendmessages);

        $oldassignment = array_shift($assignment);
        $newassignment = $DB->get_record('local_taskflow_assignment', ['id' => $oldassignment->id]);
        $this->assertNotEquals($oldassignment->status, $newassignment->status);
    }
}
