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

namespace local_taskflow\form\messages;

use advanced_testcase;
use context_system;
use local_multistepform\local\cachestore;
use ReflectionMethod;
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
final class form_messages_test extends advanced_testcase {
    /**
     * Example test: Ensure external data is loaded.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\form\messages\form_messages
     */
    public function test_get_form_data_returns_headings_by_id(): void {
        global $DB;

        // Create dummy records in local_taskflow_messages table.
        $record1 = new stdClass();
        $record1->message = json_encode((object)['heading' => 'Hello world']);
        $record1->id = $DB->insert_record('local_taskflow_messages', $record1);

        $record2 = new stdClass();
        $record2->message = json_encode((object)['heading' => 'Another message']);
        $record2->id = $DB->insert_record('local_taskflow_messages', $record2);

        $form = new form_messages();
        $result = $form->get_form_data();

        $this->assertArrayHasKey($record1->id, $result);
        $this->assertEquals('Hello world', $result[$record1->id]);

        $this->assertArrayHasKey($record2->id, $result);
        $this->assertEquals('Another message', $result[$record2->id]);
    }

    /**
     * Combined test for all form methods.
     * @covers \local_taskflow\form\messages\form_messages
     */
    public function test_get_messages_from_package_returns_matching_ids(): void {
        global $DB;

        $context = context_system::instance();

        // Create a message.
        $record = new stdClass();
        $record->message = json_encode((object)['heading' => 'Test']);
        $messageid = $DB->insert_record('local_taskflow_messages', $record);

        // Simulate a tag instance.
        $tagid = 42; // This would usually be created via core_tag API.
        $taginstance = new stdClass();
        $taginstance->itemid = $messageid;
        $taginstance->tagid = $tagid;
        $taginstance->itemtype = 'messages';
        $taginstance->component = 'local_taskflow';
        $taginstance->contextid = $context->id;
        $DB->insert_record('tag_instance', $taginstance);

        $form = new form_messages();
        $result = $form->get_messages_from_package($tagid);

        $this->assertEquals($messageid, $result[0]);
        $this->assertCount(1, $result);
    }
}
