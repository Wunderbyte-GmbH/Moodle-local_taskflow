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
final class dynamic_select_users_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\form\dynamic_select_users
     */
    public function test_process_dynamic_submission_minimal(): void {
        $this->resetAfterTest();

        $form = new dynamic_select_users();
        $form->definition();

        $ref = new \ReflectionClass($form);
        $prop = $ref->getProperty('_form');
        $prop->setAccessible(true);
        $mform = $prop->getValue($form);

        $this->assertTrue($mform->elementExists('userid'));

        $form->set_data_for_dynamic_submission();
        $this->assertEmpty($form->validation(new stdClass(), []));
        $this->assertEmpty($form->get_data());
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\form\dynamic_select_users
     */
    public function test_process_dynamic_submission_returns_userid(): void {
        $this->resetAfterTest();

        // Create a fake form instance with mocked get_data().
        $form = $this->getMockBuilder(dynamic_select_users::class)
            ->onlyMethods(['get_data'])
            ->getMock();

        $data = new stdClass();
        $data->userid = 42;

        $form->method('get_data')->willReturn($data);

        $result = $form->process_dynamic_submission();

        $this->assertInstanceOf(stdClass::class, $result);
        $this->assertEquals(42, $result->userid);
    }
}
