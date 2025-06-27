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

namespace local_taskflow\form;

use advanced_testcase;
use core_competency\user_evidence;

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
final class delete_userevidence_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\form\delete_userevidence
     */
    public function test_process_dynamic_submission_minimal(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $user = $this->getDataGenerator()->create_user();

        $evidenceid = $DB->insert_record('competency_userevidence', (object)[
            'userid' => $user->id,
            'usermodified' => $user->id,
            'name' => 'Fake',
            'description' => '',
            'descriptionformat' => FORMAT_PLAIN,
            'url' => 'https://example.com',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $DB->insert_record('local_taskflow_assignment_competency', (object)[
            'userid' => $user->id,
            'assignmentid' => 0, // We need the key, but in this case not the value.
            'competencyevidenceid' => $evidenceid,
            'competencyid' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $form = $this->getMockBuilder(delete_userevidence::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_data'])
            ->getMock();

        $form->method('get_data')->willReturn((object)[
            'evidenceid' => $evidenceid,
            'userid' => $user->id,
        ]);

        $result = $form->process_dynamic_submission();

        $this->assertTrue($result->success);
        $this->assertFalse($DB->record_exists('competency_userevidence', ['id' => $evidenceid]));
    }
}
