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

namespace local_taskflow;

use advanced_testcase;
use local_taskflow\local\assignment_status\assignment_status_facade;
use taskflowadapter_tuines\form\editassignment_admin;
use taskflowadapter_tuines\form\editassignment_supervisor;

/**
 * Test unit class of local_taskflow.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \taskflowadapter_tuines\form\editassignment_supervisor
 */
final class editassignment_supervisor_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Test getting all members of a unit.
     */
    public function test_definition_contains_expected_fields(): void {
        $form = new editassignment_supervisor(null, []);

        $mform = $this->get_mform($form);
        $elements = $mform->_elements;

        $names = array_map(fn($el) => $el->getName(), $elements);

        $this->assertContains('id', $names);
        $this->assertContains('userid', $names);
        $this->assertContains('overduecounter', $names);
        $this->assertContains('prolongedcounter', $names);
        $this->assertContains('actionbutton', $names);
        $this->assertContains('change_reason', $names);
        $this->assertContains('comment_approved', $names);
        $this->assertContains('comment_denied', $names);
        $this->assertContains('duedate', $names);
        $this->assertContains('keepchanges', $names);
        $this->assertContains('extension', $names);
        $this->assertContains('declined', $names);

        $this->assertNotEmpty($form->get_page_url_for_dynamic_submission());
        $form->set_data_for_dynamic_submission();
    }

    /**
     * Test getting all members of a unit.
     */
    public function test_validation_for_extension_requires_change_reason_and_forbids_comment_denied(): void {
        $form = new editassignment_supervisor(null, []);

        $data = [
            'actionbutton' => 'extension',
            'change_reason' => '',
            'comment_denied' => 'nope',
        ];
        $errors = $form->validation($data, []);
        $this->assertArrayHasKey('change_reason', $errors);
        $this->assertArrayHasKey('comment_denied', $errors);
    }

    /**
     * Test getting all members of a unit.
     */
    public function test_validation_for_declined_requires_comment_denied_and_forbids_change_reason(): void {
        $form = new editassignment_supervisor(null, []);

        $data = [
            'actionbutton' => 'declined',
            'change_reason' => 'some reason',
            'comment_denied' => '',
        ];
        $errors = $form->validation($data, []);
        $this->assertNotEmpty($errors);
    }

    /**
     * Test getting all members of a unit.
     */
    public function test_process_dynamic_submission_extension_sets_status_and_increments_counter(): void {
        global $DB, $USER;

        // Insert fake assignment record.
        $assignmentid = $DB->insert_record('local_taskflow_assignment', [
            'rulejson' => '{}',
            'duedate' => time(),
            'overduecounter' => 0,
            'prolongedcounter' => 0,
        ]);

        $form = $this->getMockBuilder(editassignment_supervisor::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_data'])
            ->getMock();

        $form->method('get_data')->willReturn((object)[
            'id' => $assignmentid,
            'userid' => $assignmentid,
            'actionbutton' => 'extension',
            'change_reason' => 'testing',
            'comment_approved' => 'okay',
            'comment_denied' => '',
            'overduecounter' => 0,
            'prolongedcounter' => 0,
        ]);

        $form->process_dynamic_submission();

        $record = $DB->get_record('local_taskflow_assignment', ['id' => $assignmentid]);
        $this->assertEquals(1, $record->prolongedcounter);
        $this->assertEquals(
            assignment_status_facade::get_status_identifier('prolonged'),
            $record->status
        );
    }

    /**
     * Test getting all members of a unit.
     */
    public function test_set_data_for_dynamic_submission_with_no_id(): void {
        $form = new editassignment_admin(null, []);
        $form->set_data_for_dynamic_submission();

        $data = $form->get_data();
        $this->assertNull($data);
    }

    /**
     * Helper to access the protected _form (HTML_QuickForm) instance.
     * @param editassignment_supervisor $form
     */
    private function get_mform(editassignment_supervisor $form): \HTML_QuickForm {
        $ref = new \ReflectionClass($form);
        $prop = $ref->getProperty('_form');
        $prop->setAccessible(true);
        /** @var \HTML_QuickForm $mform */
        $mform = $prop->getValue($form);
        return $mform;
    }
}
