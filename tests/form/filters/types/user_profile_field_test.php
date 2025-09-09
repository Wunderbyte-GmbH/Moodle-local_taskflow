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
final class user_profile_field_test extends advanced_testcase {
    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\form\filters\types\user_profile_field
     */
    public function test_get_options(): void {
        $options = user_profile_field::get_options();
        $this->assertArrayHasKey('user_profile_field_userprofilefield', $options);
        $this->assertArrayHasKey('user_profile_field_operator', $options);
        $this->assertArrayHasKey('user_profile_field_value', $options);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\form\filters\types\user_profile_field
     */
    public function test_definition_adds_elements(): void {
        $mform = $this->getMockBuilder(MoodleQuickForm::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['createElement', 'setType'])
            ->getMock();

        $mform->expects($this->exactly(4))
            ->method('createElement')
            ->willReturnCallback(fn($type, $name) => "element_$name");

        $mform->expects($this->once())
            ->method('setType')
            ->with('value', PARAM_TEXT);

        $repeatarray = [];
        user_profile_field::definition($repeatarray, $mform);

        $this->assertCount(4, $repeatarray);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\form\filters\types\user_profile_field
     */
    public function test_hide_and_disable_applies_correctly(): void {
        $mform = $this->getMockBuilder(MoodleQuickForm::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['hideIf', 'disabledIf'])
            ->getMock();

        $mform->expects($this->exactly(5))->method('hideIf');
        $mform->expects($this->exactly(5))->method('disabledIf');

        $typeinstance = new user_profile_field();
        $typeinstance->hide_and_disable($mform, 0);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\form\filters\types\user_profile_field
     */
    public function test_get_data_extracts_prefixed_values(): void {
        $step = [
            'filtertype' => ['user_profile_field'],
            'user_profile_field_userprofilefield' => ['field1'],
            'user_profile_field_operator' => ['eq'],
            'user_profile_field_value' => ['hello'],
            'user_profile_field_date' => ['1757311880'],
            'unrelated_key' => ['should_not_be_used'],
        ];

        $result = user_profile_field::get_data($step);

        $this->assertEquals('user_profile_field', $result['filtertype']);
        $this->assertEquals('field1', $result['userprofilefield']);
        $this->assertEquals('eq', $result['operator']);
        $this->assertEquals('hello', $result['value']);
        $this->assertArrayNotHasKey('unrelated_key', $result);
    }
}
