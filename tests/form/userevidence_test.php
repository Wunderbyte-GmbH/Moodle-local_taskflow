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
final class userevidence_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\form\userevidence
     */
    public function test_process_dynamic_submission_creates_userevidence_and_linked_record(): void {
        global $DB;

        $this->setAdminUser();

        $user = $this->getDataGenerator()->create_user();
        $competencyid = 123456;

        $ajaxformdata = [
            'evidenceid' => 0,
            'userid' => $user->id,
            'competencyid' => $competencyid,
            'statusmode' => 'create',
            'assingmentcompetencyid' => 0,
            'name' => 'Test Evidence',
            'description' => [
                'text' => 'This is a test description.',
                'format' => FORMAT_PLAIN,
            ],
            'url' => 'https://example.com/evidence',
            'files' => 0,
        ];

        $form = new \local_taskflow\form\userevidence(
            null,
            ['fileareaoptions' => []],
            'post',
            '',
            [],
            true,
            $ajaxformdata,
            true
        );
        $form->set_data_for_dynamic_submission();
    }
}
