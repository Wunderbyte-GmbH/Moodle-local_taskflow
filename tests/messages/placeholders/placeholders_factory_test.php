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

namespace local_taskflow\messages\placeholders;

use advanced_testcase;
use local_taskflow\local\messages\placeholders\placeholders_factory;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/user/profile/lib.php');

/**
 * Test unit class of local_taskflow.
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class placeholders_factory_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\messages\placeholders\placeholders_factory
     */
    public function test_has_placeholders_false_if_class_does_not_exist(): void {
        $msg = ['heading' => 'Hello {idontexist}', 'body' => 'Nothing {unknown} here'];
        $this->assertFalse(placeholders_factory::has_placeholders($msg));
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\messages\placeholders\placeholders_factory
     */
    public function test_has_placeholders_true_for_real_placeholder(): void {
        $msg = ['heading' => 'Hi {targets}', 'body' => 'See {targets}'];
        $this->assertTrue(placeholders_factory::has_placeholders($msg));
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\messages\placeholders\placeholders_factory
     */
    public function test_render_placeholders_with_no_known_placeholders_returns_input(): void {
        $message = new stdClass();
        $message->id = 123;
        $message->message = [
            'subject' => 'This has {idontexist}',
            'body' => 'Still {wrong}',
        ];

        $result = placeholders_factory::render_placeholders($message, 99, 12345);
        $this->assertEquals($message->message, $result->message);
    }
}
