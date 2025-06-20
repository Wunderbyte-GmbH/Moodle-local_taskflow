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
use context_system;
use moodle_url;
use ReflectionClass;

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
final class editassignment_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\form\editassignment
     */
    public function test_definition_contains_expected_elements(): void {
        $form = new \local_taskflow\form\editassignment(null, ['testing' => 'test']);

        $reflection = new ReflectionClass($form);
        $property = $reflection->getProperty('_form');
        $property->setAccessible(true);
        $mform = $property->getValue($form);

        $this->assertTrue($mform->elementExists('id'));
        $this->assertTrue($mform->elementExists('userid'));
        $this->assertTrue($mform->elementExists('status'));
        $this->assertTrue($mform->elementExists('change_reason'));
        $this->assertTrue($mform->elementExists('comment'));
        $this->assertTrue($mform->elementExists('duedate'));
    }
}
