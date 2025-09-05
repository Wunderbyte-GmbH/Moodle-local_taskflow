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

namespace local_taskflow\operators;

use advanced_testcase;
use local_taskflow\local\operators\string_compare_operators;

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
final class string_compare_operators_test extends advanced_testcase {
    /**  @var string_compare_operators */
    private string_compare_operators $operator;
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->operator = new string_compare_operators();
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\operators\string_compare_operators
     */
    public function test_get_operator_keys_returns_expected(): void {
        $expected = ['equals', 'not_equals', 'contains', 'containsnot'];
        $this->assertSame($expected, $this->operator->get_operator_keys());
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\operators\string_compare_operators
     */
    public function test_validate_equals(): void {
        $this->assertTrue($this->operator->validate('abc', 'abc', 'equals'));
        $this->assertFalse($this->operator->validate('abc', 'def', 'equals'));
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\operators\string_compare_operators
     */
    public function test_get_operator_keys_and_values_returns_strings(): void {
        $values = $this->operator->get_operator_keys_and_values();
        $this->assertIsArray($values);
        foreach ($values as $key => $value) {
            $this->assertContains($key, ['equals', 'not_equals', 'contains', 'containsnot', 'since']);
            $this->assertIsString($value);
        }
    }
}
