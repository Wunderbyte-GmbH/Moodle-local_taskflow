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

namespace local_taskflow\external_data;

use advanced_testcase;
use tool_mocktesttime\time_mock;
use DateTime;
use local_taskflow\event\rule_created_updated;
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\external_adapter\external_api_repository;

/**
 * Test unit class of local_taskflow.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class receive_external_data_inactive_user_ines_test_tdb extends advanced_testcase {
    /** @var string|null Stores the external user data. */
    protected ?string $externaldata = null;

    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        $this->resetAfterTest(true);
        \local_taskflow\local\units\unit_relations::reset_instances();
        $this->externaldata = file_get_contents(__DIR__ . '/../mock/anonymized_data/user_data_ines_inactive.json');

        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $profilefields = $plugingenerator->create_custom_profile_fields([
            'supervisor',
            'externalid',
            'units',
            'organisation',
            'targetgroup',
            'longleave',
            'contractend',
            'contractstart',
        ]);

        $plugingenerator->set_config_values('tuines');
        set_config("tissid_info", 'tissid_info', 'taskflowadapter_tuines');
    }


    /**
     * Example test: Ensure external data is loaded.
     * @covers \taskflowadapter_tuines\taskflowadapter_tuines
     * @covers \taskflowadapter_tuines\adapter
     * @covers \local_taskflow\local\external_adapter\external_api_base
     * @covers \local_taskflow\local\units\organisational_units\unit
     * @covers \local_taskflow\local\personas\moodle_users\types\moodle_user
     * @covers \local_taskflow\local\personas\unit_members\types\unit_member
     * @covers \local_taskflow\local\personas\unit_members\moodle_unit_member_facade
     * @covers \local_taskflow\local\personas\moodle_users\moodle_user_factory
     * @covers \local_taskflow\local\assignments\assignments_facade
     * @covers \local_taskflow\local\assignments\types\standard_assignment
     * @covers \local_taskflow\local\assignment_process\assignment_controller
     * @covers \local_taskflow\local\assignment_process\assignments\assignments_controller
     * @covers \local_taskflow\local\assignment_process\filters\filters_controller
     * @covers \local_taskflow\local\supervisor\supervisor
     * @covers \local_taskflow\local\eventhandlers\unit_member_updated
     * @covers \taskflowadapter_tuines\adapter
     * @covers \taskflowadapter_tuines\security_check
     * @covers \local_taskflow\local\assignment_process\booking_migration
     * @covers \local_taskflow\local\actions\actions_factory
     * @covers \local_taskflow\local\assignments\assignment_query_builder
     * @runInSeparateProcess
     */
    public function test_external_data_is_loaded(): void {
        global $DB;
        $apidatamanager = external_api_repository::create($this->externaldata);
        $externaldata = $apidatamanager->get_external_data();

        $date = new DateTime();
        $date->modify('+1 year');
        $formatted = $date->format('Y-m-d');
        foreach ($externaldata->persons as &$person) {
            if ($person->firstName != 'Berta') {
                $person->contractEnd = $formatted;
            }
        }

        $this->assertNotEmpty($externaldata, 'External user data should not be empty.');
        $apidatamanager->process_incoming_data();

        $cohorts = $DB->get_records('cohort');
        $cohort = array_shift($cohorts);
        $this->assertNotEmpty($cohort);
        $users = $DB->get_records('user');
        $this->assertCount(4, $users);

        // Create first rule.
        $course = $this->set_db_course();
        $rule = $this->get_rule($cohort->id, $course->id);
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
        $this->runAdhocTasks();
        $assignment = $DB->get_records('local_taskflow_assignment');
        $this->assertCount(2, $assignment);

        // Berta is missing inside json.
        $this->externaldata = file_get_contents(__DIR__ . '/../mock/anonymized_data/missing_user_data_ines_inactive.json');
        $apidatamanager = external_api_repository::create($this->externaldata);
        $externaldata = $apidatamanager->get_external_data();
        $apidatamanager->process_incoming_data();

        $berta = \core_user::get_user_by_email('sabine.subordinate@tuwien.ac.at');
        $assignments = $DB->get_records('local_taskflow_assignment', ['userid' => $berta->id]);
        foreach ($assignments as $assignment) {
            $this->assertEquals($assignment->status, assignment_status_facade::get_status_identifier('droppedout'));
        }

        // Create second rule.
        $rule = $this->get_rule($cohort->id, $course->id);
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
        $this->runAdhocTasks();
        $assignments = $DB->get_records('local_taskflow_assignment');
        $this->assertCount(3, $assignments);

        // Set user active.
        $this->externaldata = file_get_contents(__DIR__ . '/../mock/anonymized_data/user_data_ines_inactive.json');
        $apidatamanager = external_api_repository::create($this->externaldata);
        $externaldata = $apidatamanager->get_external_data();
        $apidatamanager->process_incoming_data();
        $this->runAdhocTasks();

        $assignments = $DB->get_records('local_taskflow_assignment');
        $this->assertCount(4, $assignments);
        foreach ($assignments as $assignment) {
            $this->assertEquals($assignment->status, assignment_status_facade::get_status_identifier('assigned'));
        }

        // Remove user from cohort via json.
        $externaldata = json_decode($this->externaldata);
        foreach ($externaldata->persons as &$person) {
            $person->targetGroup = [];
        }
        $this->externaldata = json_encode($externaldata);
        $apidatamanager = external_api_repository::create($this->externaldata);
        $externaldata = $apidatamanager->get_external_data();
        $apidatamanager->process_incoming_data();
        $this->runAdhocTasks();
        $assignments = $DB->get_records('local_taskflow_assignment');
        $this->assertCount(4, $assignments);
        foreach ($assignments as $assignment) {
            $this->assertEquals($assignment->status, assignment_status_facade::get_status_identifier('droppedout'));
        }
    }

    /**
     * Setup the test environment.
     * @return object
     */
    protected function set_db_course(): mixed {
        // Create a user.
        $course = $this->getDataGenerator()->create_course([
            'fullname' => 'Test Course',
            'shortname' => 'TC1010',
            'category' => 1,
            'enablecompletion' => 1,
        ]);
        return $course;
    }

    /**
     * Setup the test environment.
     * @param int $unitid
     * @param array $courseid
     * @return array
     */
    public function get_rule($unitid, $courseid): array {
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
                        "cyclicduration" => 38361600,
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
                                        "targetid" => $courseid,
                                        "targettype" => "moodlecourse",
                                        "targetname" => "mytargetname2",
                                        "sortorder" => 2,
                                        "actiontype" => "enroll",
                                        "completebeforenext" => false,
                                    ],
                                ],
                                "messages" => [],
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
}
