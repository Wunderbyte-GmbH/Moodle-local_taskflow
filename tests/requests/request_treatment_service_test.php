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
use local_taskflow\event\request_treated;
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\assignments\assignment;
use local_taskflow\local\history\history;
use local_taskflow\local\requests as requests_facade;
use local_taskflow\local\requests\request_treatment_service;
use local_taskflow\local\requests\request_types\types\allowselfextension;
use local_taskflow\local\requests\request_types\types\allowselfnotrelevant;
use local_taskflow\local\requests\request_types\types\allowuploadevidence;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/user/profile/lib.php');

/**
 * Test the request_treatment_service.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_taskflow\local\requests\request_treatment_service
 */
final class request_treatment_service_test extends advanced_testcase {
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
        assignment::destroy_instance(0);
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingenerator->teardown();
    }

    /**
     * Creates a user, an assignment and a request of the given type.
     *
     * @param int $requesttype
     * @param array $json
     * @return array [userid, assignmentid, requestid]
     */
    private function create_request(int $requesttype, array $json = []): array {
        global $DB;

        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingenerator->set_config_values('standard');

        $user = $this->getDataGenerator()->create_user();
        $this->setAdminUser();

        $ruleid = $plugingenerator->create_rule();
        $assignment = $plugingenerator->create_user_assignment($user->id, $ruleid);

        $requestid = $DB->insert_record('local_taskflow_requests', (object)[
            'request' => $requesttype,
            'status' => $requesttype,
            'assignmentid' => $assignment->id,
            'userid' => $user->id,
            'treated' => requests_facade::TREATED_STATUS_UNTREATED,
            'json' => empty($json) ? null : json_encode($json),
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        return [(int)$user->id, (int)$assignment->id, (int)$requestid];
    }

    /**
     * Given an open not-relevant request, when it is confirmed,
     * then the request is treated, the event fires and the assignment is notrelevant.
     */
    public function test_confirm_notrelevant_request(): void {
        global $DB, $USER;

        [$userid, $assignmentid, $requestid] = $this->create_request(allowselfnotrelevant::ID);

        $sink = $this->redirectEvents();
        $result = (new request_treatment_service())->confirm($requestid, $USER->id);
        $events = $sink->get_events();
        $sink->close();

        $this->assertTrue($result->success);
        $this->assertEquals(allowselfnotrelevant::ID, $result->requesttype);

        $record = $DB->get_record('local_taskflow_requests', ['id' => $requestid]);
        $this->assertEquals(requests_facade::TREATED_STATUS_CONFIRMED, (int)$record->treated);

        $treatedevents = array_filter($events, fn($event) => $event instanceof request_treated);
        $this->assertCount(1, $treatedevents);

        $assignmentrecord = $DB->get_record('local_taskflow_assignment', ['id' => $assignmentid]);
        $this->assertEquals(
            assignment_status_facade::get_status_identifier('notrelevant'),
            (int)$assignmentrecord->status
        );

        $entries = $DB->get_records('local_taskflow_history', [
            'assignmentid' => $assignmentid,
            'type' => history::TYPE_REQUEST_CONFIRMED,
        ]);
        $this->assertNotEmpty($entries);
        $this->assertEquals($userid, (int)reset($entries)->userid);
    }

    /**
     * Given an open not-relevant request, when it is declined,
     * then it is marked declined and the assignment status stays untouched.
     */
    public function test_decline_notrelevant_request(): void {
        global $DB, $USER;

        [, $assignmentid, $requestid] = $this->create_request(allowselfnotrelevant::ID);
        $before = $DB->get_field('local_taskflow_assignment', 'status', ['id' => $assignmentid]);

        $result = (new request_treatment_service())->decline($requestid, $USER->id);

        $this->assertTrue($result->success);
        $record = $DB->get_record('local_taskflow_requests', ['id' => $requestid]);
        $this->assertEquals(requests_facade::TREATED_STATUS_DECLINED, (int)$record->treated);
        $this->assertEquals(
            (int)$before,
            (int)$DB->get_field('local_taskflow_assignment', 'status', ['id' => $assignmentid])
        );

        $entries = $DB->get_records('local_taskflow_history', [
            'assignmentid' => $assignmentid,
            'type' => history::TYPE_REQUEST_DECLINED,
        ]);
        $this->assertNotEmpty($entries);
    }

    /**
     * Given an open extension request, when it is confirmed without a new due date,
     * then the request is treated and the assignment is untouched.
     */
    public function test_confirm_extension_request_without_duedate(): void {
        global $DB, $USER;

        [, $assignmentid, $requestid] = $this->create_request(allowselfextension::ID);
        $before = $DB->get_record('local_taskflow_assignment', ['id' => $assignmentid]);

        $result = (new request_treatment_service())->confirm($requestid, $USER->id);

        $this->assertTrue($result->success);
        $this->assertNull($result->assignment);

        $record = $DB->get_record('local_taskflow_requests', ['id' => $requestid]);
        $this->assertEquals(requests_facade::TREATED_STATUS_CONFIRMED, (int)$record->treated);

        $after = $DB->get_record('local_taskflow_assignment', ['id' => $assignmentid]);
        $this->assertEquals((int)$before->duedate, (int)$after->duedate);
        $this->assertEquals((int)$before->status, (int)$after->status);
    }

    /**
     * Given an open extension request, when it is confirmed with a new due date,
     * then the assignment is prolonged to that date.
     */
    public function test_confirm_extension_request_with_duedate(): void {
        global $DB, $USER;

        [, $assignmentid, $requestid] = $this->create_request(allowselfextension::ID);
        $newduedate = time() + (3 * WEEKSECS);

        $result = (new request_treatment_service())->confirm(
            $requestid,
            $USER->id,
            ['newduedate' => $newduedate, 'comment' => 'ok']
        );

        $this->assertTrue($result->success);
        $this->assertNotNull($result->assignment);

        $after = $DB->get_record('local_taskflow_assignment', ['id' => $assignmentid]);
        $this->assertEquals($newduedate, (int)$after->duedate);
        $this->assertEquals(
            assignment_status_facade::get_status_identifier('prolonged'),
            (int)$after->status
        );
    }

    /**
     * Given an open evidence request, when it is confirmed,
     * then the assignment competency is approved and the request is treated.
     */
    public function test_confirm_evidence_request(): void {
        global $DB, $USER;

        [$userid, $assignmentid, $requestid] = $this->create_request(allowuploadevidence::ID);

        $assigncompetencyid = $DB->insert_record('local_taskflow_assgin_comp', (object)[
            'competencyevidenceid' => 0,
            'assignmentid' => $assignmentid,
            'userid' => $userid,
            'competencyid' => 0,
            'status' => 'underreview',
            'validationondate' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $DB->set_field(
            'local_taskflow_requests',
            'json',
            json_encode(['assingmentcompetencyid' => $assigncompetencyid]),
            ['id' => $requestid]
        );

        $result = (new request_treatment_service())->confirm($requestid, $USER->id);

        $this->assertTrue($result->success);
        $this->assertEquals('approved', $result->evidencestatus);

        $this->assertEquals(
            'approved',
            $DB->get_field('local_taskflow_assgin_comp', 'status', ['id' => $assigncompetencyid])
        );
        $this->assertEquals(
            requests_facade::TREATED_STATUS_CONFIRMED,
            (int)$DB->get_field('local_taskflow_requests', 'treated', ['id' => $requestid])
        );
    }

    /**
     * Given an open evidence request, when it is declined,
     * then the assignment competency is rejected and the request is declined.
     */
    public function test_decline_evidence_request(): void {
        global $DB, $USER;

        [$userid, $assignmentid, $requestid] = $this->create_request(allowuploadevidence::ID);

        $assigncompetencyid = $DB->insert_record('local_taskflow_assgin_comp', (object)[
            'competencyevidenceid' => 0,
            'assignmentid' => $assignmentid,
            'userid' => $userid,
            'competencyid' => 0,
            'status' => 'underreview',
            'validationondate' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $DB->set_field(
            'local_taskflow_requests',
            'json',
            json_encode(['assingmentcompetencyid' => $assigncompetencyid]),
            ['id' => $requestid]
        );

        $result = (new request_treatment_service())->decline($requestid, $USER->id);

        $this->assertTrue($result->success);
        $this->assertEquals('rejected', $result->evidencestatus);

        $this->assertEquals(
            'rejected',
            $DB->get_field('local_taskflow_assgin_comp', 'status', ['id' => $assigncompetencyid])
        );
        $this->assertEquals(
            requests_facade::TREATED_STATUS_DECLINED,
            (int)$DB->get_field('local_taskflow_requests', 'treated', ['id' => $requestid])
        );
    }

    /**
     * Given an unknown request id, when it is confirmed, then an exception is thrown.
     */
    public function test_confirm_throws_for_unknown_request(): void {
        global $USER;

        $this->setAdminUser();
        $this->expectException(\moodle_exception::class);
        (new request_treatment_service())->confirm(999999, $USER->id);
    }
}
