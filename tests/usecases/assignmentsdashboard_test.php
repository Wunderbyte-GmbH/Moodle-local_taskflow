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
use local_taskflow\local\rules\rules;
use local_taskflow\output\assignmentsdashboard;
use local_taskflow\output\assignmentsdashboard\myassignmentsprovider;
use local_taskflow\output\assignmentsdashboard\supervisorassignmentsprovider;
use tool_mocktesttime\time_mock;
use context_system;
use core_competency\api;
use core_competency\competency;
use local_taskflow\event\rule_created_updated;
use mod_booking\singleton_service;
use stdClass;

/**
 * Tests for request messages.
 *
 * @package   local_taskflow
 * @category  test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class assignmentsdashboard_test extends advanced_testcase {
    /** @var stdClass Generated user. */
    private $user1;
    /** @var stdClass Generated user. */
    private $user2;
    /** @var stdClass Generated user. */
    private $user3;
    /** @var stdClass Generated user. */
    private $user4;
    /** @var stdClass Generated user. */
    private $testingsupervisor1;
    /** @var stdClass Generated user. */
     private $testingsupervisor2;
    /** @var stdClass Generated user. */
    private $testingdeputy;

    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        $this->preventResetByRollback();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        rules::reset_instances();
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingenerator->create_custom_profile_fields(
            [
                'supervisor',
                'units',
                'deputy',
            ]
        );
        $plugingenerator->set_config_values();
        $this->create_custom_profile_field();
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        global $DB;

        parent::tearDown();
        // Mandatory clean-up.
        singleton_service::destroy_instance();
    }

     /**
      * Test if the supervisor sees his teams assignments.
      *
      * @covers \local_taskflow\output\assignmentsdashboard\supervisorassignmentsprovider
      * @covers \local_taskflow\output\assignmentsdashboard
      *
      */
    public function test_supervisor_sees_team_in_assignmentsdashboard(): void {
        global $PAGE;
        $this->build_testcase();
        $PAGE->set_url(new \moodle_url('/local/taskflow/tests/assignmentsdashboardtest.php'));

        $this->setAdminUser();
        $provider = new supervisorassignmentsprovider($this->testingsupervisor1->id, []);
        $assignmentsdashboard = new assignmentsdashboard($provider, $this->testingsupervisor1->id, []);
        $assignmentsdashboard->get_supervisordashboard();
        $tabledata = $assignmentsdashboard->table->rawdata;

        // We look at the shortcode with a random user (to test caching) before looking with real user.
        $this->setUser($this->testingsupervisor2);
        $provider = new supervisorassignmentsprovider($this->testingsupervisor2->id, []);
        $assignmentsdashboard = new assignmentsdashboard($provider, $this->testingsupervisor2->id, []);
        $assignmentsdashboard->get_supervisordashboard();
        $this->setUser($this->testingsupervisor1);

        $provider = new supervisorassignmentsprovider($this->testingsupervisor1->id, []);
        $assignmentsdashboard = new assignmentsdashboard($provider, $this->testingsupervisor1->id, []);
        $assignmentsdashboard->get_supervisordashboard();
        $tabledata = $assignmentsdashboard->table->rawdata;
        // The supervisortable should have 2 entries for his team.
        $this->assertCount(2, $tabledata);
    }
    /**
     * Test if Admin User sees every assignment.
     *
     * @covers \local_taskflow\output\assignmentsdashboard
     *
     */
    public function test_hruser_sees_all_in_assignmentsdashboard(): void {
        global $PAGE;
        $this->build_testcase();
        $PAGE->set_url(new \moodle_url('/local/taskflow/tests/assignmentsdashboardtest.php'));
        $provider = new myassignmentsprovider(0, ['active' => 2]);
        $assignmentsdashboard = new assignmentsdashboard($provider, $this->testingsupervisor1->id, ['active' => 2]);
        $assignmentsdashboard->get_assignmentsdashboard();
        $table = $assignmentsdashboard->table;
        $tabledata = $table->rawdata;
        // Should see everything.
        $this->assertCount(3, $tabledata);
    }

    /**
     * Test if the deputy sees the assignments of the team of the supervisor.
     *
     * @covers \local_taskflow\output\assignmentsdashboard\supervisorassignmentsprovider
     * @covers \local_taskflow\output\assignmentsdashboard
     *
     */
    public function test_deputy_sees_team_in_assignmentsdashboard(): void {
        global $PAGE;
        $this->build_testcase();
        $PAGE->set_url(new \moodle_url('/local/taskflow/tests/assignmentsdashboardtest.php'));
        $provider = new supervisorassignmentsprovider($this->testingdeputy->id, []);
        $assignmentsdashboard = new assignmentsdashboard($provider, $this->testingdeputy->id, []);
        $assignmentsdashboard->get_supervisordashboard();
        $tabledata = $assignmentsdashboard->table->rawdata;
        // The supervisortable should have 2 entries for his team.
        $this->assertCount(2, $tabledata);
    }

    /**
     * Test if the deputy who is also supervisor of another team sees the assignments of the team of the supervisor and his team.
     *
     * @covers \local_taskflow\output\assignmentsdashboard\supervisorassignmentsprovider
     * @covers \local_taskflow\output\assignmentsdashboard
     *
     */
    public function test_deputy_is_also_supervisor_of_another_team(): void {
        global $PAGE;
        $this->build_testcase();
        $PAGE->set_url(new \moodle_url('/local/taskflow/tests/assignmentsdashboardtest.php'));
        $provider = new supervisorassignmentsprovider($this->testingsupervisor2->id, []);
        $assignmentsdashboard = new assignmentsdashboard($provider, $this->testingsupervisor2->id, []);
        $assignmentsdashboard->get_supervisordashboard();
        $tabledata = $assignmentsdashboard->table->rawdata;
        // The supervisortable should have 2 entries for his team.
        $this->assertCount(3, $tabledata);
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
     *
     * @return object
     */
    protected function set_db_cohort(): mixed {
        // Create a user.
        $cohort = $this->getDataGenerator()->create_cohort(
            [
            'name' => 'Test Cohort',
            'idnumber' => 'cohort123',
            'contextid' => context_system::instance()->id,
            ]
        );
        return $cohort;
    }

    /**
     * Setup the test environment.
     *
     * @param  int $unitid
     * @param  int $targetid
     * @return array
     */
    public function get_rule(int $unitid, int $targetid): array {
        $rule = [
            "unitid" => $unitid,
            "rulename" => "test_rule",
            "rulejson" => json_encode(
                (object)[
                "rulejson" => [
                    "rule" => [
                        "name" => "test_rule",
                        "description" => "test_rule_description",
                        "type" => "taskflow",
                        "enabled" => true,
                        "duedatetype" => "duration",
                        "cyclicvalidation" => "0",
                        "cyclicduration" => 38361600,
                        "fixeddate" => 23233232222,
                        "duration" => 2592000,
                        "timemodified" => 23233232222,
                        "timecreated" => 23233232222,
                        "usermodified" => 1,
                        "filter" => [],
                        "actions" => [
                            [
                                "targets" => [
                                    [
                                        "targetid" => $targetid,
                                        "targettype" => "competency",
                                        "targetname" => "mycompetency",
                                        "sortorder" => 2,
                                        "actiontype" => "enroll",
                                        "completebeforenext" => false,
                                    ],
                                ],
                                "messages" => [],
                                "requests" => [],
                            ],
                        ],
                    ],
                ],
                ]
            ),
            "isactive" => "1",
            "userid" => "0",
        ];
        return $rule;
    }

    /**
     * Builder function for the basic testcase.
     *
     * @return void
     *
     */
    private function build_testcase() {
        global $DB;
        singleton_service::destroy_instance();
        $this->setAdminUser();
        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

        // Allow optioncacellation.
        $bdata['cancancelbook'] = 1;

        // Setup test data.
        $this->user1 = $this->getDataGenerator()->create_user();
        $this->user2 = $this->getDataGenerator()->create_user();
        $this->user3 = $this->getDataGenerator()->create_user();
        $this->user4 = $this->getDataGenerator()->create_user();

        $this->testingsupervisor1 = $this->getDataGenerator()->create_user(
            [
            'firstname' => 'Supervisor',
            'lastname' => 'One',
            'email' => 'super@visor1.com',
            ]
        );
        $this->testingsupervisor2 = $this->getDataGenerator()->create_user(
            [
            'firstname' => 'Supervisor',
            'lastname' => 'Two',
            'email' => 'super@visor2.com',
            ]
        );
        $this->testingdeputy = $this->getDataGenerator()->create_user(
            [
            'firstname' => 'Deputy',
            'lastname' => 'Deputizer',
            'email' => 'depu@ty.com',
            ]
        );
        $fieldid = $DB->get_field('user_info_field', 'id', ['shortname' => 'supervisor'], MUST_EXIST);
        // User 1 and 2 are mapped to supervisor1 and user3 is mapped to supervisor 2.
        $supervisormap = [
        1 => 1,
        2 => 1,
        3 => 2,
        ];

        foreach ($supervisormap as $userindex => $supervisorindex) {
            $user = $this->{"user{$userindex}"};
            $supervisor = $this->{"testingsupervisor{$supervisorindex}"};

            $existinginfodata = $DB->get_record(
                'user_info_data',
                [
                'userid' => $user->id,
                'fieldid' => $fieldid,
                ]
            );

            if ($existinginfodata) {
                $existinginfodata->data = $supervisor->id;
                $DB->update_record('user_info_data', $existinginfodata);
            } else {
                $DB->insert_record(
                    'user_info_data',
                    (object)[
                    'userid' => $user->id,
                    'fieldid' => $fieldid,
                    'data' => $supervisor->id,
                    'dataformat' => FORMAT_HTML,
                    ]
                );
            }
        }
        // Deputy and supervisor2 are deputies of supervisor1.
        $fieldid = $DB->get_field('user_info_field', 'id', ['shortname' => 'deputy'], MUST_EXIST);
        $exsistinginfodata = $DB->get_record(
            'user_info_data',
            [
                    'userid' => $this->testingsupervisor1->id,
                    'fieldid' => $fieldid,
                ]
        );
        if ($exsistinginfodata) {
            $exsistinginfodata->data = $this->testingdeputy->id . "," . $this->testingsupervisor2->id;
            $DB->update_record(
                'user_info_data',
                $exsistinginfodata
            );
        } else {
            $DB->insert_record(
                'user_info_data',
                (object)[
                'userid' => $this->testingsupervisor1->id,
                'fieldid' => $fieldid,
                'data' => $this->testingdeputy->id . "," . $this->testingsupervisor2->id,
                'dataformat' => FORMAT_HTML,
                ]
            );
        }
        $scale = $this->getDataGenerator()->create_scale(
            [
            'scale' => 'Not proficient,Proficient',
            'name' => 'Test Competency Scale',
            ]
        );
        // Enroll 3 Users into the cohort.
        $cohort = $this->set_db_cohort();
        for ($i = 1; $i < 4; $i++) {
            $user = $this->{"user{$i}"};
            cohort_add_member($cohort->id, $user->id);
        }
        // Create a competency.
        $framework = api::create_framework(
            (object)[
            'shortname' => 'testframework',
            'idnumber' => 'testframework',
            'contextid' => context_system::instance()->id,
            'scaleid' => $scale->id,
            'scaleconfiguration' => json_encode(
                [
                ['scaleid' => $scale->id],
                ['id' => 1, 'scaledefault' => 1, 'proficient' => 0],
                ['id' => 2, 'scaledefault' => 0, 'proficient' => 1],
                ]
            ),
            ]
        );
        // Create compentencies.
        $record = (object)[
            'shortname' => 'testcompetency',
            'idnumber' => 'testcompetency',
            'competencyframeworkid' => $framework->get('id'),
            'scaleid' => null,
            'description' => 'A test competency',
            'id' => 0,
            'scaleconfiguration' => null,
            'parentid' => 0,
        ];
        $competency = new competency(0, $record);
        $competency->set('sortorder', 0);
        $competency->create();

        $rule = $this->get_rule($cohort->id, $competency->get('id'));
        $id = $DB->insert_record('local_taskflow_rules', $rule);
        $rule['id'] = $id;
        $event = rule_created_updated::create(
            [
            'objectid' => $rule['id'],
            'context'  => context_system::instance(),
            'other'    => [
                'ruledata' => $rule,
            ],

            ]
        );
        $event->trigger();
        $this->runAdhocTasks();
        $assignments = $DB->get_records('local_taskflow_assignment');
        // We check if all assignments are created.
        $this->assertCount(3, $assignments);
    }
}
