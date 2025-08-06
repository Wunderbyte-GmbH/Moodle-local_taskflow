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

/**
 * Rules table.
 *
 * @package     local_taskflow
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\output;

use advanced_testcase;
use stdClass;

/**
 * Rules table
 *
 * @package     local_taskflow
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class singleassignment_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);

        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');

        $plugingenerator->create_custom_profile_fields([
            'supervisor',
            'units',
        ]);
        $plugingenerator->set_config_values();
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\output\singleassignment
     * @covers \local_taskflow\local\assignments\assignment
     * @covers \local_taskflow\local\supervisor\supervisor
     * @covers \local_taskflow\output\assignmentsdashboard
     */
    public function test_constructor_and_export_for_template(): void {
        global $DB, $OUTPUT;

        $this->setAdminUser();

        // Create fake user.
        $user = $this->getDataGenerator()->create_user([
            'firstname' => 'Test',
            'lastname' => 'User',
        ]);

        $rule = new stdClass();
        $rule->rulename = 'Test Rule';
        $rule->rulejson = '{}';
        $rule->timecreated = time();
        $rule->timemodified = time();
        $ruleid = $DB->insert_record('local_taskflow_rules', $rule);

        // Fake assignment table and entry.
        $assignment = new stdClass();
        $assignment->userid = $user->id;
        $assignment->messages = '{}';
        $assignment->ruleid = $ruleid;
        $assignment->unitid = 0;
        $assignment->assigneddate = time();
        $assignment->duedate = time() + 3600;
        $assignment->active = 1;
        $assignment->status = 0;
        $assignment->targets = '[{"targettype":"bookingoption","targetid":"2","sortorder":2,"targetname":"pf2346 -
            KONDITIONSTRAINING UND SPIELE","actiontype":"enroll","completionstatus":1, "completebeforenext":false}]';
        $assignment->usermodified = $user->id;
        $assignment->timecreated = time();
        $assignment->timemodified = time();
        $assignmentid = $DB->insert_record('local_taskflow_assignment', $assignment);

        // Instantiate and test.
        $renderable = new singleassignment(['id' => $assignmentid]);
        $data = $renderable->export_for_template($OUTPUT);

        $this->assertIsArray($data);
        $this->assertEquals($user->id, $data['userid']);
        $this->assertEquals('Test User', $data['fullname']);
        $this->assertArrayHasKey('assignmentdata', $data);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\output\singleassignment
     * @covers \local_taskflow\local\assignments\assignment
     */
    public function test_constructor_works_without_set_bookforuser(): void {
        global $DB, $OUTPUT;

        $this->setAdminUser();

        // Ensure class is NOT already loaded.
        if (class_exists(\mod_booking\price::class, false)) {
            $this->markTestSkipped('mod_booking\\price already loaded; cannot simulate missing class.');
        }

        // Set up user.
        $user = $this->getDataGenerator()->create_user([
            'firstname' => 'Test',
            'lastname' => 'User',
        ]);

        // Set up rule.
        $rule = new stdClass();
        $rule->rulename = 'Test Rule';
        $rule->rulejson = '{}';
        $rule->timecreated = time();
        $rule->timemodified = time();
        $ruleid = $DB->insert_record('local_taskflow_rules', $rule);

        // Set up assignment.
        $assignment = new stdClass();
        $assignment->userid = $user->id;
        $assignment->messages = '{}';
        $assignment->ruleid = $ruleid;
        $assignment->unitid = 0;
        $assignment->assigneddate = time();
        $assignment->duedate = time() + 3600;
        $assignment->active = 1;
        $assignment->status = 0;
        $assignment->targets = json_encode([]);
        $assignment->usermodified = $user->id;
        $assignment->timecreated = time();
        $assignment->timemodified = time();
        $assignmentid = $DB->insert_record('local_taskflow_assignment', $assignment);

        // Test the singleassignment class (this triggers constructor and export).
        $renderable = new \local_taskflow\output\singleassignment(['id' => $assignmentid]);
        $data = $renderable->export_for_template($OUTPUT);

        // Assertions.
        $this->assertIsArray($data);
        $this->assertEquals($user->id, $data['userid']);
        $this->assertEquals('Test User', $data['fullname']);
        $this->assertArrayHasKey('assignmentdata', $data);
    }
}
