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

namespace local_taskflow\form\targets;

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
final class target_test extends advanced_testcase {
    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\form\targets\target
     */
    public function test_set_data_for_dynamic_submission(): void {
        $formdata = [
            'targets' => [
                (object)[
                    'targettype' => 'bookingoption',
                    'targetid' => '123',
                    'duedatetype' => (object)['fixeddate' => 1720000000],
                ],
            ],
        ];

        $form = $this->getMockBuilder(\local_taskflow\form\targets\target::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['set_data'])
            ->getMock();

        $form->expects($this->once())
            ->method('set_data')
            ->with($this->callback(function($data) {
                return isset($data['bookingoption_targetid']) &&
                    isset($data['fixeddate']) &&
                    isset($data['duedatetype']);
            }));

        $reflection = new ReflectionClass($form);
        $property = $reflection->getProperty('_customdata');
        $property->setAccessible(true);
        $property->setValue($form, $formdata);

        $form->set_data_for_dynamic_submission();
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\form\targets\target
     */
    public function test_set_data_to_persist(): void {
        $step = [
            'targettype' => ['bookingoption'],
            'bookingoption_targetid' => ['123'],
            'duedatetype' => ['fixeddate'],
            'fixeddate' => [1720000000],
            'duration' => [0],
        ];

        $rulejson = [];

        $form = new \local_taskflow\form\targets\target(null, [
            'uniqueid' => 'testid',
            'recordid' => 1,
            'step' => ['stepidentifier' => 'mockstep'],
        ]);

        $form->set_data_to_persist($step, $rulejson);

        $this->assertArrayHasKey('actions', $rulejson);
        $this->assertCount(1, $rulejson['actions']);
        $this->assertEquals('bookingoption', $rulejson['actions'][0]['targets'][0]['targettype']);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\form\targets\target
     */
    public function test_load_data_for_form(): void {
        $object = new \stdClass();
        $object->actions = [
            (object)[
                'targets' => [
                    ['targettype' => 'bookingoption', 'targetid' => 123],
                    ['targettype' => 'competency', 'targetid' => 456],
                ],
            ],
        ];

        $step = [];

        $result = \local_taskflow\form\targets\target::load_data_for_form($step, $object);

        $this->assertArrayHasKey('targets', $result);
        $this->assertCount(2, $result['targets']);
        $this->assertEquals('bookingoption', $result['targets'][0]['targettype']);
    }
}
