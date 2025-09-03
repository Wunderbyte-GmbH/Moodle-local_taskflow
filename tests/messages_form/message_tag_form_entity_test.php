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

use advanced_testcase;
use core_tag_tag;
use local_taskflow\local\messages_form\message_tag_form_entity;
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
final class message_tag_form_entity_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\messages_form\message_tag_form_entity
     */
    public function test_save_message_tags(): void {
        global $DB;

        $this->resetAfterTest(true);

        // Create dummy message record.
        $record = new stdClass();
        $record->class = 'standard';
        $record->message = json_encode(['heading' => 'Test', 'body' => ['text' => 'Message', 'fomrat' => 1]]);
        $record->priority = 1;
        $record->usermodified = 2;
        $record->timecreated = time();
        $record->timemodified = time();
        $record->sending_settings = json_encode([
            'senddirection' => 'after',
            'sendstart' => 'start',
            'senddays' => 2,
        ]);

        $recordid = $DB->insert_record('local_taskflow_messages', $record);

        // Define tags to assign.
        $tags = ['tag1', 'tag2'];

        $entity = new message_tag_form_entity();
        $entity->save_message_tags($recordid, $tags);

        // Check that tags are saved.
        $savedtags = core_tag_tag::get_item_tags('local_taskflow', 'local_taskflow_messages', $recordid);
        $savednames = array_map(fn($tag) => $tag->rawname, $savedtags);

        $this->assertCount(2, $savedtags);
        $this->assertEqualsCanonicalizing($tags, $savednames);
    }
}
