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
use cache_helper;
use local_taskflow\event\rule_created_updated;
use local_taskflow\local\external_adapter\external_api_repository;

/**
 * Test unit class of local_taskflow.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
final class patrik_partial_test extends advanced_testcase {
    /** @var string|null Stores the external user data. */
    protected ?string $externaldata = null;

    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        \local_taskflow\local\units\unit_relations::reset_instances();
        $this->externaldata = file_get_contents(__DIR__ . '/external_json/sara_sick.json');
        $this->create_custom_profile_field();
        $this->set_config_values();
    }

    /**
     * Setup the test environment.
     */
    private function create_custom_profile_field(): int {
        global $DB;
        $shortname = 'supervisor';
        $name = ucfirst($shortname);
        if ($DB->record_exists('user_info_field', ['shortname' => $shortname])) {
            return 0;
        }

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
    protected function set_config_values(): void {
        global $DB;
        $settingvalues = [
            'translator_user_firstname' => "firstName",
            'translator_user_lastname' => "lastName",
            'translator_user_email' => "eMailAddress",
            'translator_user_tissid' => "tissId",
            'translator_user_supervisor' => "directSupervisor",
            'translator_user_long_leave' => "currentlyOnLongLeave",
            'translator_user_orgunit' => "orgUnit",
            'translator_user_units' => "targetGroup",
            'translator_user_end' => "contractEnd",
            'external_api_option' => 'ines_api',
            'translator_target_group_name' => 'displayNameDE',
            'translator_target_group_description' => 'descriptionDE',
            'translator_target_group_unitid' => 'number',
            'organisational_unit_option' => 'cohort',
            'user_profile_option' => 'ines',
            'supervisor_field' => 'supervisor',
        ];
        foreach ($settingvalues as $key => $value) {
            set_config($key, $value, 'local_taskflow');
        }
        cache_helper::invalidate_by_event('config', ['local_taskflow']);
    }

    /**
     * Setup the test environment.
     * @return array
     */
    protected function set_db_course(): mixed {
        // Create a user.
        $course = $this->getDataGenerator()->create_course([
            'fullname' => 'Test Course',
            'shortname' => 'TC101',
            'category' => 1,
            'enablecompletion' => 1,
        ]);

        $secondcourse = $this->getDataGenerator()->create_course([
            'fullname' => 'Test second Course',
            'shortname' => 'TC102',
            'category' => 1,
            'enablecompletion' => 1,
        ]);
        return [$course->id, $secondcourse->id];
    }

    /**
     * Setup the test environment.
     * @param int $unitid
     * @param array $courseids
     * @param array $messageids
     * @return array
     */
    public function get_rule($unitid, $courseids, $messageids): array {
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
                                "operator" => "not_equals",
                                "value" => "124",
                                "key" => "role",
                            ],
                        ],
                        "actions" => [
                            [
                                "targets" => [
                                    [
                                        "targetid" => array_shift($courseids),
                                        "targettype" => "moodlecourse",
                                        "targetname" => "mytargetname2",
                                        "sortorder" => 2,
                                        "actiontype" => "enroll",
                                        "completebeforenext" => false,
                                    ],
                                    [
                                        "targetid" => array_shift($courseids),
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
     * @covers \local_taskflow\event\assignment_status_changed
     * @covers \local_taskflow\observer
     * @covers \local_taskflow\sheduled_tasks\send_taskflow_message
     * @covers \local_taskflow\local\assignments\status\assignment_status
     * @covers \local_taskflow\local\rules\unit_rules
     * @covers \local_taskflow\local\messages\placeholders\types\due_date
     * @covers \local_taskflow\local\messages\placeholders\types\targets
     * @covers \local_taskflow\local\messages\placeholders\types\firstname
     * @covers \local_taskflow\local\messages\placeholders\types\lastname
     * @covers \local_taskflow\local\messages\placeholders\types\status
     * @covers \local_taskflow\local\messages\placeholders\types\supervisor_firstname
     * @covers \local_taskflow\local\messages\placeholders\types\supervisor_lastname
     * @covers \local_taskflow\local\messages\message_sending_time
     * @covers \local_taskflow\local\messages\message_recipient
     * @covers \local_taskflow\local\messages\placeholders\placeholders_factory
     * @covers \local_taskflow\local\eventhandlers\assignment_completed
     * @covers \local_taskflow\local\eventhandlers\assignment_status_changed
     * @covers \local_taskflow\local\completion_process\scheduling_event_messages
     */
    public function test_patrik_partial(): void {
        global $DB;

        $apidatamanager = external_api_repository::create($this->externaldata);
        $externaldata = $apidatamanager->get_external_data();
        $this->assertNotEmpty($externaldata, 'External user data should not be empty.');
        $apidatamanager->process_incoming_data();

        $cohorts = $DB->get_records('cohort');
        $cohort = array_shift($cohorts);

        $courseids = $this->set_db_course();
        $messageids = $this->set_messages_db();

        $rule = $this->get_rule($cohort->id, $courseids, $messageids);
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
        $assignments = $DB->get_records('local_taskflow_assignment');
        $this->assertNotEmpty($assignments);

        $sara = $DB->get_record('user', ['firstname' => 'Sara']);
        $this->course_completed($courseids[0], userid: $sara->id);

        $assignments = $DB->get_records('local_taskflow_assignment', ['userid' => $sara->id]);
        foreach ($assignments as $assignment) {
            $this->assertNotEquals('0', $assignment->status);
        }
        $this->course_completed($courseids[1], $sara->id);
        $assignments = $DB->get_records('local_taskflow_assignment', ['userid' => $sara->id]);
        foreach ($assignments as $assignment) {
            $this->assertEquals('10', $assignment->status);
        }
        $taskadhocmessages = $DB->get_records('task_adhoc');
        $this->assertNotEmpty($taskadhocmessages);
        $this->assertTrue(count($taskadhocmessages) > 4);
        foreach ($taskadhocmessages as $taskadhocmessage) {
            $task = \core\task\manager::adhoc_task_from_record($taskadhocmessage);
            $lockfactory = \core\lock\lock_config::get_lock_factory('core_cron');
            $lock = $lockfactory->get_lock('adhoc_task_' . $task->get_id(), 120);
            $task->set_lock($lock);
            $task->execute();
            \core\task\manager::adhoc_task_complete($task);
        }
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
}
