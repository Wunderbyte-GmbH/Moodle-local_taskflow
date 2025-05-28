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
final class bookingoption_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\form\targets\types\bookingoption
     */
    public function test_get_options_returns_expected_array(): void {
        $form = new bookingoption();
        $options = $form->get_options();
        $this->assertIsArray($options);
        $this->assertArrayHasKey('bookingoption_targetid', $options);
        $this->assertEquals(PARAM_INT, $options['bookingoption_targetid']['type']);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\form\targets\types\bookingoption
     */
    public function test_definition_adds_autocomplete_element(): void {
        global $DB;

        // Insert a fake booking option into the DB.
        $bookingoption = (object)[
            'text' => 'Test Booking Option',
            'description' => 'This is a test booking option.',
            'bookingid' => 1, // assuming bookingid is also NOT NULL
            'courseid' => 2,  // possibly required
            'teachers' => '',
            'location' => '',
            'institution' => '',
            'address' => '',
            'pollurl' => '',
            'howlong' => '',
            'maxanswers' => 0,
            'maxoverbooking' => 0,
            'bookingclosingtime' => 0,
            'optiondate' => 0,
            'timemodified' => time(),
            'timecreated' => time(),
            'status' => 0,
        ];
        $bookingoption->id = $DB->insert_record('booking_options', $bookingoption);

        // Prepare a fake MoodleQuickForm.
        $mockform = $this->createMock(MoodleQuickForm::class);
        $mockform->expects($this->once())
            ->method('createElement')
            ->with(
                'autocomplete',
                'bookingoption_targetid',
                get_string('targettype:bookingoption', 'local_taskflow'),
                $this->callback(function ($options) use ($bookingoption) {
                    return isset($options[$bookingoption->id]) &&
                        str_contains($options[$bookingoption->id], 'Test Booking Option');
                }),
                []
            );

        $repeatarray = [];
        $form = new bookingoption();
        $form->definition($repeatarray, $mockform);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\form\targets\types\bookingoption
     */
    public function test_hide_and_disable_applies_conditions(): void {
        $elementcounter = 3;
        $expectedfieldname = "bookingoption_targetid[{$elementcounter}]";
        $conditionfield = "targettype[{$elementcounter}]";

        // Create a mock MoodleQuickForm.
        $mockform = $this->getMockBuilder(MoodleQuickForm::class)
            ->onlyMethods(['hideIf', 'disabledIf'])
            ->disableOriginalConstructor()
            ->getMock();

        // Expectations: hideIf and disabledIf should each be called once with specific parameters.
        $mockform->expects($this->once())
            ->method('hideIf')
            ->with(
                $expectedfieldname,
                $conditionfield,
                'neq',
                'bookingoption'
            );

        $mockform->expects($this->once())
            ->method('disabledIf')
            ->with(
                $expectedfieldname,
                $conditionfield,
                'neq',
                'bookingoption'
            );

        $form = new bookingoption();
        $form->hide_and_disable($mockform, $elementcounter);
    }
}
