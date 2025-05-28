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

namespace local_taskflow\form\targets\types;

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
final class moodlecourse_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\form\targets\types\moodlecourse
     */
    public function test_get_options_returns_expected_array(): void {
        $target = new moodlecourse();
        $options = $target->get_options();

        $this->assertIsArray($options);
        $this->assertArrayHasKey('moodlecourse_targetid', $options);
        $this->assertEquals(['type' => PARAM_INT], $options['moodlecourse_targetid']);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\form\targets\types\moodlecourse
     */
    public function test_definition_adds_autocomplete_element(): void {
        global $DB;

        $this->resetAfterTest();

        // Insert dummy courses.
        $DB->insert_record('course', (object)[
            'fullname' => 'Test Course',
            'shortname' => 'TC1',
            'category' => 1,
        ]);

        $repeatarray = [];

        // Mock the MoodleQuickForm
        $mform = $this->createMock(MoodleQuickForm::class);
        $mform->expects($this->once())
            ->method('createElement')
            ->with(
                $this->equalTo('autocomplete'),
                $this->equalTo('moodlecourse_targetid'),
                $this->isType('string'),
                $this->isType('array'),
                []
            )
            ->willReturn('fakeelement');

        $target = new moodlecourse();
        $target->definition($repeatarray, $mform);

        $this->assertCount(1, $repeatarray);
        $this->assertEquals('fakeelement', $repeatarray[0]);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\form\targets\types\moodlecourse
     */
    public function test_hide_and_disable_applies_conditions(): void {
        $mform = $this->getMockBuilder(MoodleQuickForm::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['hideIf', 'disabledIf'])
            ->getMock();

        $mform->expects($this->once())
            ->method('hideIf')
            ->with(
                'moodlecourse_targetid[0]',
                'targettype[0]',
                'neq',
                'moodlecourse'
            );

        $mform->expects($this->once())
            ->method('disabledIf')
            ->with(
                'moodlecourse_targetid[0]',
                'targettype[0]',
                'neq',
                'moodlecourse'
            );

        $target = new moodlecourse();
        $target->hide_and_disable($mform, 0);
    }
}
