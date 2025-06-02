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

namespace local_taskflow\form\filters\types;

use advanced_testcase;
use MoodleQuickForm;

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
final class user_field_test extends advanced_testcase {
    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\form\filters\types\user_field
     */
    public function test_get_options_returns_expected_array(): void {
        $options = user_field::get_options();
        $this->assertArrayHasKey('user_field_userfield', $options);
        $this->assertArrayHasKey('user_field_operator', $options);
        $this->assertArrayHasKey('user_field_value', $options);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\form\filters\types\user_field
     */
    public function test_get_userfields_returns_expected_keys(): void {
        $fields = user_field::get_userfields();
        $this->assertArrayHasKey('firstaccess', $fields);
        $this->assertArrayHasKey('lastaccess', $fields);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\form\filters\types\user_field
     */
    public function test_get_data_returns_filtered_step_data(): void {
        $step = [
            'user_field_userfield' => 'firstaccess',
            'user_field_operator' => 'eq',
            'user_field_value' => '2024-01-01',
            'irrelevant_key' => 'should be ignored',
        ];
        $data = user_field::get_data($step);

        $this->assertCount(3, $data);
        $this->assertArrayHasKey('user_field_userfield', $data);
        $this->assertArrayNotHasKey('irrelevant_key', $data);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\form\filters\types\user_field
     */
    public function test_definition_appends_elements(): void {
        $mform = $this->getMockBuilder(MoodleQuickForm::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['createElement', 'setType'])
            ->getMock();

        $mform->expects($this->exactly(3))
            ->method('createElement')
            ->willReturnCallback(function ($type, $name) {
                return "mock_element_{$name}";
            });

        $mform->expects($this->once())
            ->method('setType')
            ->with('value', \PARAM_TEXT);

        $repeatarray = [];
        user_field::definition($repeatarray, $mform);

        $this->assertCount(3, $repeatarray);
        $this->assertSame('mock_element_user_field_userfield', $repeatarray[0]);
        $this->assertSame('mock_element_user_field_operator', $repeatarray[1]);
        $this->assertSame('mock_element_user_field_value', $repeatarray[2]);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\form\filters\types\user_field
     */
    public function test_hide_and_disable_calls_correct_methods(): void {
        $mform = $this->getMockBuilder(MoodleQuickForm::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['hideIf', 'disabledIf'])
            ->getMock();

        $mform->expects($this->exactly(3))->method('hideIf');
        $mform->expects($this->exactly(3))->method('disabledIf');

        user_field::hide_and_disable($mform, 0);
    }
}
