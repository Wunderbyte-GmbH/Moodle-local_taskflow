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

namespace local_taskflow\requests;

use advanced_testcase;
use tool_mocktesttime\time_mock;
use local_taskflow\event\request_treated;
use local_taskflow\form\notrelevantforme;
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\history\history;
use local_taskflow\local\requests;
use local_taskflow\output\requestsdashboard;
use local_taskflow\local\assignments\assignment;

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
final class requests_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        $this->preventResetByRollback();
        $this->resetAfterTest(true);
        \local_taskflow\local\units\unit_relations::reset_instances();
    }

    /**
     * Tear down the test environment.
     *
     * @return void
     *
     */
    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        global $DB;
        parent::tearDown();
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingenerator->teardown();
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\history\types\base
     */
    public function test_create_request_create_event_log_history(): void {
        global $DB, $USER;

        // Create assignment for user.
        $user1 = $this->getDataGenerator()->create_user();
        $this->setUser($user1);
        $rule = $DB->insert_record('local_taskflow_rules', (object)[
            'rulename' => 'Test Rule',
            'rulejson' => '{}',
        ]);
        $data = [
            'userid' => $user1->id,
            'ruleid' => $rule,
            'unitid' => 1,
            'assigneddate' => time(),
            'duedate' => time() + 3600,
        ];
        $assignment = new assignment();
        $result = $assignment->add_or_update_assignment($data);

        // Create and test request.
        $sink = $this->redirectEvents();
        $requestid = requests::create(
            requests::REQUEST_NOTRELEVANT,
            $user1->id,
            $result->id,
            0,
            $USER->id
        );
        $this->assertNotEmpty($requestid);

        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertSame('local_taskflow\event\request_created', get_class($event));
        $this->assertEquals($user1->id, $event->userid);
    }
    /**
     * Description for test_treat_request_declined_triggers_event_and_updates_db.
     * @covers \local_taskflow\local\history\types\base
     * @return void
     *
     */
    public function test_treat_request_declined_triggers_event_and_updates_db(): void {
        global $DB, $USER;

        $this->resetAfterTest(true);

        // Prepare test data.
        $userid = $this->getDataGenerator()->create_user()->id;
        $assignmentid = 123; // Stub assignment ID.
        $requestid = $DB->insert_record('local_taskflow_requests', (object)[
            'assignmentid' => $assignmentid,
            'userid'       => $userid,
            'treated'      => 0,
        ]);

        // Catch events.
        $sink = $this->redirectEvents();

        $manager = new requests();
        // Run code under test: decline the request.
        $result = $manager->treat_request(
            $requestid,
            $assignmentid,
            $userid,
            requests::TREATED_STATUS_DECLINED
        );

        $events = $sink->get_events();
        $sink->close();

        // Assert returned true.
        $this->assertTrue($result);

        // DB state updated.
        $record = $DB->get_record('local_taskflow_requests', ['id' => $requestid]);
        $this->assertEquals(requests::TREATED_STATUS_DECLINED, $record->treated);

        // Event fired correctly.
        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertInstanceOf(request_treated::class, $event);
        $this->assertEquals($requestid, $event->objectid);
        $this->assertEquals($userid, $event->userid);
        $this->assertEquals($assignmentid, $event->other['assignmentid']);
        $this->assertEquals(requests::TREATED_STATUS_DECLINED, $event->other['status']);

        // Check history entry exists.
        $history = $DB->get_records('local_taskflow_history', ['assignmentid' => $assignmentid]);
        $this->assertNotEmpty($history);
        $entry = reset($history);
        $this->assertEquals(history::TYPE_REQUEST_DECLINED, $entry->type);
    }

    /**
     * [Description for test_treat_request_confirmed_triggers_assignment_actions]
     * @covers \local_taskflow\local\history\types\base
     * @return void
     */
    public function test_treat_request_confirmed_triggers_assignment_actions(): void {
        global $DB;

        $this->resetAfterTest(true);

        // Create a user and set as active.
        $user1 = $this->getDataGenerator()->create_user();
        $userid = $user1->id;
        $this->setUser($user1);

        // Create a rule (assignment requires a ruleid).
        $ruleid = $DB->insert_record('local_taskflow_rules', (object)[
            'rulename' => 'Test Rule',
            'rulejson' => '{}',
        ]);

        // Create an assignment.
        $data = [
            'userid' => $user1->id,
            'ruleid' => $ruleid,
            'unitid' => 1,
            'assigneddate' => time(),
            'duedate' => time() + 3600,
        ];
        $assignment = new assignment();
        $a = $assignment->add_or_update_assignment($data);
        $assignmentid = $a->id;

        $requestid = $DB->insert_record('local_taskflow_requests', (object)[
            'assignmentid' => $assignmentid,
            'userid'       => $userid,
            'treated'      => 0,
        ]);

        $manager = new requests();

        $sink = $this->redirectEvents();

        $result = $manager->treat_request(
            $requestid,
            $assignmentid,
            $userid,
            requests::TREATED_STATUS_CONFIRMED
        );

        $events = $sink->get_events();
        $sink->close();

        $this->assertTrue($result);

        // Event checks.
        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertInstanceOf(request_treated::class, $event);
        $this->assertEquals(requests::TREATED_STATUS_CONFIRMED, $event->other['status']);

        $history = $DB->get_records('local_taskflow_history', ['assignmentid' => $assignmentid]);
        $this->assertNotEmpty($history);
        $confirmedentry = array_filter($history, fn($entry) => $entry->type === 'request_confirmed');
        $this->assertNotEmpty($confirmedentry, 'Request confirmed entry in history');

        // Assignment status updated to "notrelevant".
        $assignmentrec = $DB->get_record('local_taskflow_assignment', ['id' => $assignmentid], '*', MUST_EXIST);
        $this->assertSame(assignment_status_facade::get_status_identifier('notrelevant'), (int) $assignmentrec->status);
    }

    /**
     * [Description for test_requests_dashboard_table_records]
     * @covers \local_taskflow\local\history\types\base
     * @param array $data
     * @param array $expected
     *
     * @return void
     * @dataProvider request_dashboard_provider
     */
    public function test_requests_dashboard_table_records(array $data, array $expected): void {
        global $PAGE, $DB;

        set_config('supervisor', 'translator_user_supervisor', 'taskflowadapter_standard');
        set_config('deputy', 'translator_user_deputy', 'taskflowadapter_standard');

        // Create user profile custom fields.
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'supervisor',
            'name' => 'supervisor',
        ]);

        // Create user profile custom fields.
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'deputy',
            'name' => 'deputy',
        ]);

        // Manager has Capability "viewallrequests".
        $syscontext = \context_system::instance();
        $requestmanager = create_role('Handleallrequests', 'viewallrequests', 'Can see and handle all requests');
        assign_capability('local/taskflow:viewallrequests', CAP_ALLOW, $requestmanager, $syscontext->id, true);
        $manager = $this->getDataGenerator()->create_user();
        role_assign($requestmanager, $manager->id, $syscontext->id);
        $this->setUser($manager);
        $this->assertTrue(has_capability('local/taskflow:viewallrequests', $syscontext));

        $deputy1 = $this->getDataGenerator()->create_user();
        $deputy2 = $this->getDataGenerator()->create_user();
        $supervisor1 = $this->getDataGenerator()->create_user(['profile_field_deputy' => "$deputy1->id,$deputy2->id"]);
        $supervisor2 = $this->getDataGenerator()->create_user(['profile_field_deputy' => "$deputy1->id"]);
        $user1 = $this->getDataGenerator()
            ->create_user(['profile_field_supervisor' => $supervisor1->id, 'profile_field_deputy' => "$deputy1->id"]);
        $user2 = $this->getDataGenerator()->create_user(['profile_field_supervisor' => $supervisor2->id]);
        $user4 = $this->getDataGenerator()->create_user(['profile_field_supervisor' => "$user1->id"]);
        $user3 = $this->getDataGenerator()->create_user();
        $users = [
            'manager' => $manager,
            'deputy1' => $deputy1,
            'deputy2' => $deputy2,
            'supervisor1' => $supervisor1,
            'supervisor2' => $supervisor2,
            'user1' => $user1,
            'user2' => $user2,
            'user3' => $user3,
            'user4' => $user4,
        ];

        $assignmentid = 1; // Stub assignment ID.

        // Create requests for these users.
        foreach ($users as $username => $user) {
            if (!in_array($username, $data['createrequestsfor'])) {
                continue;
            }
            $requestid = $DB->insert_record('local_taskflow_requests', (object)[
                'assignmentid' => $assignmentid,
                'userid'       => $user->id,
                'treated'      => 0,
            ]);
        }

        $requests = $DB->get_records('local_taskflow_requests');
        $this->assertCount($expected['numberofrequests'], $requests);

        // Set user.
        $this->setUser($users[$data['setuserfortableview']]);
        if ($data['setuserfortableview'] === 'mananger') {
            $this->assertTrue(has_capability('local/taskflow:viewallrequests', $syscontext));
        }

        $dashboard = new requestsdashboard([$data['sqldata'] ?? []]);
        [$fields, $from, $where, $params] = $dashboard->get_sql_for_records([$data['sqldata'] ?? []]);

        $renderer = $PAGE->get_renderer('local_taskflow');
        $output = $renderer->render($dashboard);
        $this->assertStringContainsString($expected['renderedtablecontains'], $output);
    }

    /**
     * Data provider test_requests_dashboard_table_records.
     *
     *
     * @return array
     */
    public static function request_dashboard_provider(): array {
        return [
            'Supervisor1 sees 1 request of user1' => [
                'data' => [
                    'createrequestsfor' => [
                        'user1', // Supervisor is supervisor1, so I expect to see this record.
                        'user2',
                        'user3',
                    ],
                    'setuserfortableview' => 'supervisor1',
                    'sqldata' => [], // Add param "all => 1" here for testing this configuration.
                ],
                'expected' => [
                    'numberofrequests' => 3,
                    'renderedtablecontains' => 'requeststable',
                    'renderedtablecontainsnot' => 'No records found.',
                    'recordscount' => 1,
                ],
            ],
            'User4 is no supervisor and should not see anything' => [
                'data' => [
                    'createrequestsfor' => [
                        'user1', // Supervisor is supervisor1, so I expect to see this record.
                        'user2',
                        'user3',
                        'user4',
                    ],
                    'setuserfortableview' => 'user4',
                    'sqldata' => [], // Add param "all => 1" here for testing this configuration.
                ],
                'expected' => [
                    'numberofrequests' => 4,
                    'renderedtablecontains' => 'No records found.',
                    'renderedtablecontainsnot' => 'sdsd',
                    'recordscount' => 0,
                ],
            ],
            'Supervisor2 sees 1 request of user2' => [
                'data' => [
                    'createrequestsfor' => [
                        'user1',
                        'user2', // Supervisor is supervisor2, so I expect to see this record.
                        'user3',
                    ],
                    'setuserfortableview' => 'supervisor2',
                    'sqldata' => [], // Add param "all => 1" here for testing this configuration.
                ],
                'expected' => [
                    'numberofrequests' => 3,
                    'renderedtablecontains' => 'requeststable',
                    'renderedtablecontainsnot' => 'No records found.',
                    'recordscount' => 1,
                ],
            ],
            'Deputy1 sees 2 requests of both supervisors of user1 & user2' => [
                'data' => [
                    'createrequestsfor' => [
                        'user1', // Supervisor is supervisor1, - deputy1 is deputy - so I expect to see this record.
                        'user2', // Supervisor is supervisor2, - deputy1 is deputy - so I expect to see this record.
                        'user3',
                    ],
                    'setuserfortableview' => 'deputy1',
                    'sqldata' => [], // Add param "all => 1" here for testing this configuration.
                ],
                'expected' => [
                    'numberofrequests' => 3,
                    'renderedtablecontains' => 'requeststable',
                    'renderedtablecontainsnot' => 'No records found.',
                    'recordscount' => 2,
                ],
            ],
            'User1 is supervisor of user4 and should see only this' => [
                'data' => [
                    'createrequestsfor' => [
                        'user1',
                        'user2',
                        'user3',
                        'user4', // Supervisor is user1, - deputy1 is deputy - so I expect to see this record.
                    ],
                    'setuserfortableview' => 'user1',
                    'sqldata' => [], // Add param "all => 1" here for testing this configuration.
                ],
                'expected' => [
                    'numberofrequests' => 4,
                    'renderedtablecontains' => 'requeststable',
                    'renderedtablecontainsnot' => 'No records found.',
                    'recordscount' => 1,
                ],
            ],
            'Deputy2 sees 1 request of user2' => [
                'data' => [
                    'createrequestsfor' => [
                        'user1', // Supervisor is supervisor1 - deputy2 is deputy -, so I expect to see this record.
                        'user2', // Supervisor is supervisor2 - deputy2 is NOT deputy -, so I expect NOT to see this record.
                        'user3',
                    ],
                    'setuserfortableview' => 'deputy2',
                    'sqldata' => [], // Add param "all => 1" here for testing this configuration.
                ],
                'expected' => [
                    'numberofrequests' => 3,
                    'renderedtablecontains' => 'requeststable',
                    'renderedtablecontainsnot' => 'No records found.',
                    'recordscount' => 1,
                ],
            ],
            'User3 sees no requests' => [
                'data' => [
                    'createrequestsfor' => [
                        'user1',
                        'user2',
                        'user3',
                    ],
                    'setuserfortableview' => 'user3',
                    'sqldata' => [], // Add param "all => 1" here for testing this configuration.
                ],
                'expected' => [
                    'numberofrequests' => 3,
                    'renderedtablecontains' => 'No records found.',
                    'renderedtablecontainsnot' => 'kankansk',
                    'recordscount' => 0,
                ],
            ],
            'Manager with capability but without argument sees no requests' => [
                'data' => [
                    'createrequestsfor' => [
                        'user1',
                        'user2',
                        'user3',
                    ],
                    'setuserfortableview' => 'manager',
                    'sqldata' => [], // Add param "all => 1" here for testing this configuration.
                ],
                'expected' => [
                    'numberofrequests' => 3,
                    'renderedtablecontains' => 'No records found.',
                    'renderedtablecontainsnot' => 'kankansk',
                    'recordscount' => 0,
                ],
            ],
            'Manager with capability and argument "all" sees all requests' => [
                'data' => [
                    'createrequestsfor' => [
                        'user1',
                        'user2',
                        'user3',
                    ],
                    'setuserfortableview' => 'manager',
                    'sqldata' => ["all" => 1], // Add param "all => 1" here for testing this configuration.
                ],
                'expected' => [
                    'numberofrequests' => 3,
                    'renderedtablecontains' => 'requeststable',
                    'renderedtablecontainsnot' => 'No records found.',
                    'recordscount' => 3,
                ],
            ],
            'No requests, no table' => [
                'data' => [
                    'createrequestsfor' => [
                    ],
                    'setuserfortableview' => 'supervisor1',
                ],
                'expected' => [
                    'numberofrequests' => 0,
                    'renderedtablecontains' => 'No records found.',
                    'renderedtablecontainsnot' => 'kankansk',
                    'recordscount' => 0,
                ],
            ],
        ];
    }
}
