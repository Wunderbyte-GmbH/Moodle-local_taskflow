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

namespace local_taskflow\usecases\filters;

use advanced_testcase;
use local_taskflow\local\rules\rules;
use tool_mocktesttime\time_mock;
use context_system;
use core_competency\api;
use core_competency\competency;
use local_taskflow\event\rule_created_updated;
use mod_booking\singleton_service;



/**
 * Tests for filters of taskflowrules.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class filters_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        $this->resetAfterTest();

        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingenerator->create_custom_profile_fields([
            'supervisor',
            'units',
            'customfieldtofilter',
            'entrydate',
        ]);
        $plugingenerator->set_config_values();
        $this->create_custom_profile_field();
        $this->preventResetByRollback();
    }

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
     * Setup the test environment.
     */
    private function create_custom_profile_field(): int {
        global $DB;
        $shortname = 'customfieldtofilter';
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
     * Test if the isin filter for arrays work.
     *
     * @throws \coding_exception
     * @covers \local_taskflow\classes\event\rule_created_updated
     * @return void
     *
     */
    public function test_isin_filter(): void {
        global $DB;
        $filter = $this->isin_filter();
        [$user1, $user2, $user3] = $this->testbase($filter);
        $assignments = $DB->get_records('local_taskflow_assignment');

        // We expect 2 users: user2 and user3 (values XY and YY).
        $this->assertSame(2, count($assignments));

        $assignment1 = reset($assignments);
        $this->assertSame((int)$user2->id, (int)$assignment1->userid);

        $assignment2 = end($assignments);
        $this->assertSame((int)$user3->id, (int)$assignment2->userid);
        $this->tearDown();
    }
    /**
     * Test if the isnotin filter for arrays work.
     *
     * @throws \coding_exception
     * @covers \local_taskflow\classes\event\rule_created_updated
     * @return void
     */
    public function test_isnotin_filter(): void {
        global $DB;
        $filter = $this->isnotin_filter();
        [$user1, $user2, $user3] = $this->testbase($filter);
        $assignments = $DB->get_records('local_taskflow_assignment');

        // We expect 1 user (user1).
        $this->assertSame(1, count($assignments));

        // We check if it is actually user1.
        $assignment1 = reset($assignments);
        $this->assertSame((int)$user1->id, (int)$assignment1->userid);
        $this->tearDown();
    }


    /**
     * Test if the equas works.
     * @throws \coding_exception
     * @covers \local_taskflow\classes\event\rule_created_updated
     * @return void
     */
    public function test_equals_filter(): void {
        global $DB;
        $filter = $this->equals_filter();
        [$user1, $user2, $user3] = $this->testbase($filter);
        $assignments = $DB->get_records('local_taskflow_assignment');
        // We expect User 2 where the customfield equals XY.
        $this->assertSame(1, count($assignments));
        $assignment1 = reset($assignments);
        $this->assertSame((int)$user2->id, (int)$assignment1->userid);
        $this->tearDown();
    }

    /**
     * Test if the not equals filter work.
     * @throws \coding_exception
     * @covers \local_taskflow\classes\event\rule_created_updated
     * @return void
     */
    public function test_notequals_filter(): void {
        global $DB;
        $filter = $this->notequals_filter();
        [$user1, $user2, $user3] = $this->testbase($filter);
        $assignments = $DB->get_records('local_taskflow_assignment');
        // We expect 2 users: user1 and user3 (values XY and YY).
        $this->assertSame(2, count($assignments));

        $assignment1 = reset($assignments);
        $this->assertSame((int)$user1->id, (int)$assignment1->userid);

        $assignment2 = end($assignments);
        $this->assertSame((int)$user2->id, (int)$assignment2->userid);
    }

    /**
     * Check if Contains filter works.
     * @throws \coding_exception
     * @covers \local_taskflow\classes\event\rule_created_updated
     * @return void
     */
    public function test_contains_filter(): void {
        global $DB;
        $filter = $this->contains_filter();
        [$user1, $user2, $user3] = $this->testbase($filter);
        $assignments = $DB->get_records('local_taskflow_assignment');
        // We expect 2 users: user2 and user3 both contain Y.
        $this->assertSame(2, count($assignments));

        $assignment1 = reset($assignments);
        $this->assertSame((int)$user2->id, (int)$assignment1->userid);

        $assignment2 = end($assignments);
        $this->assertSame((int)$user3->id, (int)$assignment2->userid);
    }

    /**
     * Check if notcontains filter works.
     * @throws \coding_exception
     * @covers \local_taskflow\classes\event\rule_created_updated
     * @return void
     */
    public function test_notcontains_filter(): void {
        global $DB;
        $filter = $this->notcontains_filter();
        [$user1, $user2, $user3] = $this->testbase($filter);
        $assignments = $DB->get_records('local_taskflow_assignment');
        // We expect user 1 who hasn't Y in his customfield.
        $this->assertSame(1, count($assignments));

        $assignment1 = reset($assignments);
        $this->assertSame((int)$user1->id, (int)$assignment1->userid);
    }

    /**
     * Check if since filter works.
     * @throws \coding_exception
     * @covers \local_taskflow\classes\event\rule_created_updated
     * @return void
     */
    public function test_since_filter(): void {
        global $DB;
        $filter = $this->since_filter();
        [$user1, $user2, $user3] = $this->testbase($filter);
        $assignments = $DB->get_records('local_taskflow_assignment');
        // We expect user1 and user2 because they have the right entrydate.
        $this->assertSame(2, count($assignments));

        $assignment1 = reset($assignments);
        $this->assertSame((int)$user1->id, (int)$assignment1->userid);

        $assignment2 = end($assignments);
        $this->assertSame((int)$user2->id, (int)$assignment2->userid);
    }

    /**
     * Test the before filter.
     * @throws \coding_exception
     * @covers \local_taskflow\classes\event\rule_created_updated
     * @return void
     */
    public function test_before_filter(): void {
        global $DB;
        $filter = $this->before_filter();
        [$user1, $user2, $user3] = $this->testbase($filter);
        $assignments = $DB->get_records('local_taskflow_assignment');
        // We expect user1 and user2 because they have the right entrydate.
        $this->assertSame(1, count($assignments));

        $assignment1 = reset($assignments);
        $this->assertSame((int)$user3->id, (int)$assignment1->userid);
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
            'contextid' => context_system::instance()->id,
        ]);
        return $cohort;
    }
    /**
     * Base scenario for all filtertests.
     * @param array $filter
     *
     * @return array
     *
     */
    protected function testbase(array $filter) {
        global $DB;

        $this->setAdminUser();
        // Allow option cancellation.
        $bdata['cancancelbook'] = 1;

        // Setup test data.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $user3 = $this->getDataGenerator()->create_user();

        $fieldid = $DB->get_field('user_info_field', 'id', ['shortname' => 'customfieldtofilter'], MUST_EXIST);
        $fieldid2 = $DB->get_field('user_info_field', 'id', ['shortname' => 'entrydate'], MUST_EXIST);

        $userdata = [
        $user1->id => [
            $fieldid => 'AB',
            $fieldid2 => 1735686000,
        ],
        $user2->id => [
            $fieldid => 'XY',
            $fieldid2 => 1704063600,
        ],
        $user3->id => [
            $fieldid => 'YY',
            $fieldid2 => 1672527600,
        ],
        ];

        foreach ($userdata as $userid => $fields) {
            foreach ($fields as $fid => $value) {
                $existingid = $DB->get_field('user_info_data', 'id', [
                'userid' => $userid,
                'fieldid' => $fid,
                ]);

                $record = (object)[
                    'userid' => $userid,
                    'fieldid' => $fid,
                    'data' => $value,
                    'dataformat' => FORMAT_HTML,
                ];

                if ($existingid) {
                    $record->id = $existingid;
                    $DB->update_record('user_info_data', $record);
                } else {
                    $DB->insert_record('user_info_data', $record);
                }
            }
        }

        $scale = $this->getDataGenerator()->create_scale([
        'scale' => 'Not proficient,Proficient',
        'name' => 'Test Competency Scale',
        ]);

        $cohort = $this->set_db_cohort();
        cohort_add_member($cohort->id, $user1->id);
        cohort_add_member($cohort->id, $user2->id);
        cohort_add_member($cohort->id, $user3->id);

        // Create a competency.
        $framework = api::create_framework((object)[
        'shortname' => 'testframework',
        'idnumber' => 'testframework',
        'contextid' => context_system::instance()->id,
        'scaleid' => $scale->id,
        'scaleconfiguration' => json_encode([
            ['scaleid' => $scale->id],
            ['id' => 1, 'scaledefault' => 1, 'proficient' => 0],
            ['id' => 2, 'scaledefault' => 0, 'proficient' => 1],
        ]),
        ]);

        // Create competencies. For Targets.
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
        $rule = $this->get_rule($cohort->id, $competency->get('id'), $filter);
        $id = $DB->insert_record('local_taskflow_rules', $rule);
        $rule['id'] = $id;

        $event = rule_created_updated::create([
        'objectid' => $rule['id'],
        'context'  => context_system::instance(),
        'other'    => [
            'ruledata' => $rule,
        ],
        ]);
        $event->trigger();
        $this->runAdhocTasks();
        return [$user1, $user2, $user3];
    }
    /**
     * Setup the test environment.
     * @param int $unitid
     * @param int $targetid
     * @param array $filter
     * @return array
     */
    public function get_rule(int $unitid, int $targetid, array $filter): array {
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
                        "cyclicvalidation" => "0",
                        "cyclicduration" => 38361600,
                        "fixeddate" => 23233232222,
                        "duration" => 23233232222,
                        "timemodified" => 23233232222,
                        "timecreated" => 23233232222,
                        "usermodified" => 1,
                        "filter" => [
                            $filter,
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
    /**
     * Returns the isin filter.
     *
     * @return array
     *
     */
    protected function isin_filter() {
        return [
                                "filtertype" => "user_profile_field",
                                "userprofilefield" => "customfieldtofilter",
                                "operator" => "isin",
                                "value" => "XY;YY",
        ];
    }

    /**
     * Returns isnotin filter.
     *
     * @return array
     *
     */
    protected function isnotin_filter() {
        return [
                                "filtertype" => "user_profile_field",
                                "userprofilefield" => "customfieldtofilter",
                                "operator" => "isnotin",
                                "value" => "XY;YY",
        ];
    }
    /**
     * Returns the equals Filter.
     *
     * @return array
     *
     */
    protected function equals_filter() {
        return [
                                "filtertype" => "user_profile_field",
                                "userprofilefield" => "customfieldtofilter",
                                "operator" => "equals",
                                "value" => "XY",
        ];
    }
    /**
     * Returns not_equals filter.
     *
     * @return array
     *
     */
    protected function notequals_filter() {
        return [
                                "filtertype" => "user_profile_field",
                                "userprofilefield" => "customfieldtofilter",
                                "operator" => "not_equals",
                                "value" => "YY",
        ];
    }

    /**
     * Returns contains filter.
     *
     * @return array
     *
     */
    protected function contains_filter() {
        return [
                                "filtertype" => "user_profile_field",
                                "userprofilefield" => "customfieldtofilter",
                                "operator" => "contains",
                                "value" => "Y",
        ];
    }

    /**
     * Returns contains filter.
     *
     * @return array
     *
     */
    protected function notcontains_filter() {
        return [
                                "filtertype" => "user_profile_field",
                                "userprofilefield" => "customfieldtofilter",
                                "operator" => "containsnot",
                                "value" => "Y",
        ];
    }

    /**
     * Returns since filter.
     *
     * @return array
     *
     */
    protected function since_filter() {
        return [
                                "filtertype" => "user_profile_field",
                                "userprofilefield" => "entrydate",
                                "operator" => "since",
                                "date" => "1704063600",
        ];
    }

    /**
     * Returns before filter.
     *
     * @return array
     *
     */
    protected function before_filter() {
        return [
                                "filtertype" => "user_profile_field",
                                "userprofilefield" => "entrydate",
                                "operator" => "before",
                                "date" => "1704063600",
        ];
    }
    /**
     * Data provider for condition_bookingpolicy_test
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    public static function booking_common_settings_provider(): array {
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
