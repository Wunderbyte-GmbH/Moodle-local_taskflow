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
use local_taskflow\local\rules\rules;
use tool_mocktesttime\time_mock;
use context_system;
use core_competency\api;
use core_competency\competency;
use local_taskflow\event\rule_created_updated;
use mod_booking\singleton_service;
use local_taskflow\local\requests;
use stdClass;

/**
 * Tests for request messages.
 *
 * @package   local_taskflow
 * @category  test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class requests_messages_test extends advanced_testcase
{
    /**
     * Tests set up.
     */
    public function setUp(): void
    {
        parent::setUp();
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        rules::reset_instances();
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingenerator->create_custom_profile_fields(
            [
            'supervisor',
            'units',
            ]
        );
        $plugingenerator->set_config_values();
        $this->create_custom_profile_field();
        $this->preventResetByRollback();
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void
    {
        global $DB;

        parent::tearDown();
        // Mandatory clean-up.
        singleton_service::destroy_instance();
    }

    /**
     * Setup the test environment.
     */
    private function create_custom_profile_field(): int
    {
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
     * Test rulestemplate on option being completed for user.
     *
     *
     * @throws \coding_exception
     *
     */
    public function test_request_created(): void{
        global $DB, $USER;
        singleton_service::destroy_instance();
        $sink = $this->redirectEmails();
        $this->setAdminUser();
        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

        // Allow optioncacellation.
        $bdata['cancancelbook'] = 1;

        // Setup test data.
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $user3 = $this->getDataGenerator()->create_user();

        $testingsupervisor = $this->getDataGenerator()->create_user(
            [
            'firstname' => 'Super',
            'lastname' => 'Visor',
            'email' => 'auper@visor.com',
            ]
        );

        $fieldid = $DB->get_field('user_info_field', 'id', ['shortname' => 'supervisor'], MUST_EXIST);
        $exsistinginfodata = $DB->get_record(
            'user_info_data',
            [
                    'userid' => $user2->id,
                    'fieldid' => $fieldid,
                ]
        );
        if ($exsistinginfodata) {
            $exsistinginfodata->data = $testingsupervisor->id;
            $DB->update_record(
                'user_info_data',
                $exsistinginfodata
            );
        } else {
            $DB->insert_record(
                'user_info_data', (object)[
                'userid' => $user2->id,
                'fieldid' => $fieldid,
                'data' => $testingsupervisor->id,
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

        $cohort = $this->set_db_cohort();
        cohort_add_member($cohort->id, $user2->id);
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

        $messageids = $this->set_messages_db();
        $rule = $this->get_rule($cohort->id, $competency->get('id'), $messageids);
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
        $assignment = reset($assignments);
        // We check if an assignment is created.
        $this->assertCount(1, $assignments);
        // Now we create a request for not relevant.
        $requestid = requests::create(
            requests::REQUEST_NOTRELEVANT,
            $user1->id,
            (int) $assignment->id,
            0,
            $USER->id
        );
        $this->assertNotEmpty($requestid);
        $this->runAdhocTasks();
        $sentmessages = $DB->get_records('local_taskflow_sent_messages');

        // We check if it is in sentmessages. It should not be.
        $this->assertCount(0, $sentmessages);

        $dbmsg = array_values($DB->get_records('local_taskflow_messages'));
        foreach ($dbmsg as $index => $msg) {
            $data = json_decode($msg->message);
            $dbmsg[$index]->subject = $data->heading;
        }

        $messagesink = array_filter(
            $sink->get_messages(), function ($message) {
                return strpos($message->subject, "onrequestcreated") === 0;
            }
        );

        // We check if it is the correct message.
        $this->assertCount(1, $messagesink);
        foreach ($messagesink as $msg) {
            $this->assertTrue(
                $msg->to === $user2->email
            );
            $this->assertSame(
                $dbmsg[0]->subject,
                $msg->subject,
            );
        }

        $requests = $DB->get_records('local_taskflow_requests');
        $request = reset($requests);
        $requestid = $request->id;
        $manager = new requests();
        // Run code under test: decline the request.
        $result = $manager->treat_request(
            $requestid,
            (int) $assignment->id,
            $USER->id,
            requests::TREATED_STATUS_DECLINED
        );
        $this->runAdhocTasks();
        $sentmessages = $DB->get_records('local_taskflow_sent_messages');

        // We check if it is in sentmessages. It should not be.
        $this->assertCount(0, $sentmessages);

        $dbmsg = array_values($DB->get_records('local_taskflow_messages'));
        foreach ($dbmsg as $index => $msg) {
            $data = json_decode($msg->message);
            $dbmsg[$index]->subject = $data->heading;
        }

        $messagesink = array_filter(
            $sink->get_messages(), function ($message) {
                return strpos($message->subject, 'onrequestclosed') === 0;
            }
        );
        // We check if the second message is sent and really the second message.
        $this->assertCount(1, $messagesink);
        foreach ($messagesink as $msg) {
            $this->assertTrue(
                $msg->to === $user2->email
            );
            $this->assertSame(
                $dbmsg[1]->subject,
                $msg->subject,
            );
        }
    }

    /**
     * Setup the test environment.
     */
    protected function set_messages_db(): array
    {
        global $DB;
        $messageids = [];
        $messages = json_decode(file_get_contents(__DIR__ . '/../mock/messages/requestmessages.json'));
        foreach ($messages as $message) {
            $messageids[] = (object)['messageid' => $DB->insert_record('local_taskflow_messages', $message)];
        }
        return $messageids;
    }

    /**
     * Setup the test environment.
     *
     * @return object
     */
    protected function set_db_cohort(): mixed
    {
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
    public function get_rule($unitid, $targetid, $messageids): array
    {
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
                                        "targetid" => $targetid,
                                        "targettype" => "competency",
                                        "targetname" => "mycompetency",
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
                ]
            ),
            "isactive" => "1",
            "userid" => "0",
        ];
        return $rule;
    }

    /**
     * Data provider for condition_bookingpolicy_test
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    public static function booking_common_settings_provider(): array
    {
        $bdata = [
            'name' => 'Rule Booking Test',
            'eventtype' => 'Test rules',
            'enablecompletion' => 1,
            'bookedtext' => ['text' => 'text'],
            'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'],
            'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'],
            'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'],
            'notificationtext' => ['text' => 'text'],
            'userleave' => ['text' => 'text'],
            'tags' => '',
            'completion' => 2,
            'showviews' => ['mybooking,myoptions,optionsiamresponsiblefor,showall,showactive,myinstitution'],
        ];
        return ['bdata' => [$bdata]];
    }
}
