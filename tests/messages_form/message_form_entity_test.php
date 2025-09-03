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

namespace local_taskflow\messages_form;

use local_taskflow\local\messages_form\message_form_entity;
use advanced_testcase;
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
final class message_form_entity_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\messages_form\message_form_entity
     */
    public function test_prepare_message_from_form_creates_new_record(): void {
        global $DB, $USER;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $formdata = new stdClass();
        $formdata->messagename = 'Test Name';
        $formdata->type = 'standard';
        $formdata->heading = 'Test Heading';
        $formdata->body = [
            'text' => 'This is a test message.',
            'fomrat' => 1,
        ];
        $formdata->priority = 2;
        $formdata->senddirection = 'before';
        $formdata->sendstart = 'start';
        $formdata->senddays = 5;
        $formdata->timeunit = 'days';

        $entity = new message_form_entity();
        $messageid = $entity->prepare_message_from_form($formdata);

        $this->assertIsInt($messageid);
        $this->assertGreaterThan(0, $messageid);

        $record = $DB->get_record('local_taskflow_messages', ['id' => $messageid]);
        $this->assertNotEmpty($record);

        $message = json_decode($record->message);
        $this->assertEquals($formdata->messagename, $record->name);
        $this->assertEquals($formdata->heading, $message->heading);
        $this->assertEquals($formdata->body['text'], $message->body);

        $sending = json_decode($record->sending_settings);
        $this->assertEquals($formdata->senddirection, $sending->senddirection);
        $this->assertEquals($formdata->senddays, $sending->senddays);

        $this->assertEquals($USER->id, $record->usermodified);
        $this->assertEquals($formdata->priority, $record->priority);
        $this->assertNotEmpty($record->timecreated);
        $this->assertNotEmpty($record->timemodified);

        $formdata->id = $messageid;
        $reloadmessageid = $entity->prepare_message_from_form($formdata);
        $messages = $DB->get_records('local_taskflow_messages');
        $this->assertCount(1, $messages);
        $this->assertEquals($messageid, $reloadmessageid);

        $formrecord = $entity->prepare_record_for_form($reloadmessageid);
        $this->assertNotEmpty($formrecord);

        $this->assertEmpty($entity->prepare_record_for_form(0));
    }
}
