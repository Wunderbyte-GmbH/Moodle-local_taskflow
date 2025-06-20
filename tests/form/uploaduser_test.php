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
final class uploaduser_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\form\uploaduser
     */
    public function test_definition_contains_expected_elements(): void {
        $form = new uploaduser(null, []);
        $errors = $form->validation(['userjson' => ''], []);
        $this->assertArrayHasKey('userjson', $errors);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\form\uploaduser
     */
    public function test_validation_invalid_json(): void {
        $form = new uploaduser(null, []);
        $errors = $form->validation(['userjson' => '{invalid json}'], []);
        $this->assertArrayHasKey('userjson', $errors);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\form\uploaduser
     */
    public function test_validation_non_array_json(): void {
        $form = new uploaduser(null, []);
        $errors = $form->validation(['userjson' => json_encode("string instead of array")], []);
        $this->assertArrayHasKey('userjson', $errors);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\form\uploaduser
     */
    public function test_validation_valid_json(): void {
        $form = new uploaduser(null, []);
        $validjson = json_encode(['user' => 'valid']);
        $errors = $form->validation(['userjson' => $validjson], []);
        $this->assertEmpty($errors);
    }
}
