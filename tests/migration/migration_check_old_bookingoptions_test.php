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

namespace local_taskflow\migration;

use advanced_testcase;
use local_taskflow\event\rule_created_updated;
use local_taskflow\local\external_adapter\external_api_repository;
use mod_booking\bo_availability\bo_info;
use mod_booking\booking_option;
use mod_booking\singleton_service;
use mod_booking_generator;
use stdClass;

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
final class migration_check_old_bookingoptions_test extends advanced_testcase {
    /** @var string|null Stores the external user data. */
    protected ?string $externaldata = null;

    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        \local_taskflow\local\units\unit_relations::reset_instances();
        $this->externaldata = file_get_contents(__DIR__ . '/../mock/anonymized_data/user_data_thour_migration.json');
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingenerator->create_custom_profile_fields([
            'supervisor',
            'orgunit',
            'externalid',
            'contractend',
            'Org1',
            'Org2',
            'Org3',
            'Org4',
            'Org5',
            'Org6',
            'Org7',
        ]);
        $plugingenerator->set_config_values('ksw');
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\external_adapter\external_api_base
     * @covers \local_taskflow\local\units\organisational_units\unit
     * @covers \local_taskflow\local\personas\moodle_users\types\moodle_user
     * @covers \local_taskflow\local\personas\unit_members\types\unit_member
     * @covers \local_taskflow\local\personas\unit_members\moodle_unit_member_facade
     * @covers \local_taskflow\local\personas\moodle_users\moodle_user_factory
     * @covers \local_taskflow\local\users_profile\types\thour
     * @covers \local_taskflow\local\assignments\assignments_facade
     * @covers \local_taskflow\local\assignments\types\standard_assignment
     * @covers \local_taskflow\local\assignment_process\assignment_controller
     * @covers \local_taskflow\local\assignment_process\assignments\assignments_controller
     * @covers \local_taskflow\local\assignment_process\filters\filters_controller
     * @covers \local_taskflow\local\units\unit_hierarchy
     * @covers \local_taskflow\local\supervisor\supervisor
     * @runInSeparateProcess
     */
    public function test_external_data_is_loaded(): void {
        global $DB;
        $apidatamanager = external_api_repository::create($this->externaldata);
        $externaldata = $apidatamanager->get_external_data();
        $this->assertNotEmpty($externaldata, 'External user data should not be empty.');
        $apidatamanager->process_incoming_data();
        $moodleusers = $DB->get_records('user');
        $bookingoption = $this->setup_booking_options_and_answers($moodleusers);
        $cohort = $this->setup_cohort();
        foreach ($moodleusers as $user) {
            cohort_add_member($cohort->id, $user->id);
        }
        $messageids = $this->set_messages_db();
        $this->setup_rule($cohort->id, $bookingoption->id, $messageids);
        $this->runAdhocTasks();

        $assignements = $DB->get_records('local_taskflow_assignment');
        foreach ($assignements as $assignement) {
            $this->assertEquals($assignement->status, '0');
            $this->assertEquals($assignement->active, '1');
        }

        $logs = $DB->get_records('local_taskflow_history');
        $this->assertTrue(15 < count($logs));
        $tasks = $DB->get_records('task_adhoc');
        foreach ($tasks as $task) {
            $this->assertEquals($task->classname, '\local_taskflow\task\check_assignment_status');
        }
    }

    /**
     * Example test: Ensure external data is loaded.
     * @param object $bookingoption
     * @param array $users
     * @covers \local_taskflow\local\messages_form\editmessagesmanager
     */
    public function adjust_external_data($bookingoption, $users): void {
        $externaldata = json_decode($this->externaldata);
        foreach ($externaldata as $key => $externaluser) {
            $externaluser->DefaultEmailAddress = $users[$key]->email;
            $externaluser->Firstname = $users[$key]->firstname;
            $externaluser->LastName = $users[$key]->lastname;
        }
    }

    /**
     * Setup the test environment.
     * @param int $unitid
     * @param int $bookingoptionid
     * @param array $messageids
     * @return void
     */
    public function setup_rule($unitid, $bookingoptionid, $messageids): void {
        global $DB;
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
                        "cyclicvalidation" => "1",
                        "cyclicduration" => 18361600,
                        "fixeddate" => 23233232222,
                        "duration" => 23233232222,
                        "timemodified" => 13233232222,
                        "timecreated" => 13233232222,
                        "extensionperiod" => 2419200,
                        "usermodified" => 1,
                        "filter" => [],
                        "actions" => [
                            [
                                "targets" => [
                                    [
                                        "targetid" => $bookingoptionid,
                                        "targettype" => "bookingoption",
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
        $id = $DB->insert_record('local_taskflow_rules', $rule);
        $rule['id'] = $id;

        $event = rule_created_updated::create([
            'objectid' => $rule['id'],
            'context'  => \context_system::instance(),
            'other'    => [
                'ruledata' => $rule,
            ],
        ]);
        $event->trigger();
        return;
    }

    /**
     * Setup the test environment.
     * @return array
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
     * @param array $users
     * @return stdClass
     */
    public function setup_booking_options_and_answers($users): stdClass {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        foreach ($users as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $course->id);
        }
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1';
        $record->courseid = $course->id;
        $record->maxanswers = 2;

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($record);
        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        // To avoid retrieving the singleton with the wrong settings, we destroy it.
        singleton_service::destroy_booking_singleton_by_cmid($settings->cmid);

        // Book the first user without any problem.
        $boinfo = new bo_info($settings);
        $finished = strtotime('-1 year');
        foreach ($users as $user) {
            $this->save_booking_answers_for_user($option1, $user, $finished);
        }
        return $option1;
    }

    /**
     * Example test: Ensure external data is loaded.
     * @param stdClass $option
     * @param stdClass $student
     * @param string $finished
     */
    public function save_booking_answers_for_user($option, $student, $finished): void {
        global $DB;

        $record = [
            'bookingid' => $option->bookingid,
            'userid' => $student->id,
            'optionid' => $option->id,
            'timemodified' => $finished,
            'completed' => 1,
            'waitinglist' => 0,
            'timecreated' => $finished,
            'status' => 0,
        ];

        $DB->insert_record(
            'booking_answers',
            (object) $record
        );
        booking_option::purge_cache_for_option($option->id);
    }

    /**
     * Setup the test environment.
     * @return object
     */
    protected function setup_cohort(): object {
        return $this->getDataGenerator()->create_cohort([
            'name' => 'Test Cohort',
            'idnumber' => 'cohort123',
            'contextid' => \context_system::instance()->id,
        ]);
    }
}
