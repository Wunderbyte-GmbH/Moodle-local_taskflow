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
final class competency_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\form\targets\types\competency
     */
    public function test_get_options_returns_expected_array(): void {
        $target = new competency();
        $options = $target->get_options();

        $this->assertIsArray($options);
        $this->assertArrayHasKey('competency_targetid', $options);
        $this->assertEquals(['type' => PARAM_INT], $options['competency_targetid']);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\form\targets\types\competency
     */
    public function test_definition_adds_autocomplete_element(): void {
        global $DB;
        $this->resetAfterTest();

        $frameworkid = $DB->insert_record('competency_framework', (object)[
            'shortname' => 'Test Framework',
            'idnumber' => 'TF001',
            'contextid' => \context_system::instance()->id,
            'scaleconfiguration' => json_encode([
                'scales' => [],
                'defaultscale' => null,
            ]),
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        // Now insert a competency linked to that framework.
        $DB->insert_record('competency', (object)[
            'shortname' => 'Test Competency',
            'description' => 'Sample',
            'competencyframeworkid' => $frameworkid,
            'sortorder' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $repeatarray = [];

        $mform = $this->createMock(MoodleQuickForm::class);
        $mform->expects($this->once())
            ->method('createElement')
            ->with(
                $this->equalTo('autocomplete'),
                $this->equalTo('competency_targetid'),
                $this->isType('string'),
                $this->isType('array'),
                []
            )
            ->willReturn('fakeelement');

        $target = new competency();
        $target->definition($repeatarray, $mform);

        $this->assertCount(1, $repeatarray);
        $this->assertEquals('fakeelement', $repeatarray[0]);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\form\targets\types\competency
     */
    public function test_hide_and_disable_applies_conditions(): void {
        $mform = $this->getMockBuilder(MoodleQuickForm::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['hideIf', 'disabledIf'])
            ->getMock();

        $mform->expects($this->once())
            ->method('hideIf')
            ->with(
                'competency_targetid[0]',
                'targettype[0]',
                'neq',
                'competency'
            );

        $mform->expects($this->once())
            ->method('disabledIf')
            ->with(
                'competency_targetid[0]',
                'targettype[0]',
                'neq',
                'competency'
            );

        $target = new competency();
        $target->hide_and_disable($mform, 0);
    }
}
