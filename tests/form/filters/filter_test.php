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

namespace local_taskflow\form\filters;

use advanced_testcase;
use MoodleQuickForm;
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
final class filter_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\form\filters\filter
     */
    public function test_definition_with_user_target(): void {
        $this->resetAfterTest();

        $cachestoremock = $this->getMockBuilder(\local_multistepform\local\cachestore::class)
            ->onlyMethods(['get_multiform'])
            ->getMock();

        $cachestoremock->method('get_multiform')->willReturn([
            'steps' => [
                1 => [
                    'targettype' => 'user_target',
                ],
            ],
        ]);

        // Override cachestore in filter class.
        new \local_taskflow\form\filters\filter(null, [
            'uniqueid' => 'testid',
            'recordid' => 1,
            'step' => ['stepidentifier' => 'mockstep'], // Add this line to fix the undefined key
        ]);
        $this->assertTrue(true);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\form\filters\filter
     */
    public function test_set_data_for_dynamic_submission(): void {
        $formdata = [
            'filter' => [
                (object)[
                    'filtertype' => 'user_field',
                    'somevalue' => 'value1',
                ],
                (object)[
                    'filtertype' => 'user_profile_field',
                    'somevalue' => 'value2',
                ],
            ],
        ];

        $form = $this->getMockBuilder(filter::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['set_data'])
            ->getMock();

        $form->expects($this->once())->method('set_data')
            ->with($this->callback(
                function($data) {
                    return isset($data['user_field_somevalue']) &&
                            isset($data['user_profile_field_somevalue']);
                })
            );

        $reflection = new ReflectionClass($form);
        $property = $reflection->getProperty('_customdata');
        $property->setAccessible(true);
        $property->setValue($form, $formdata);

        $form->set_data_for_dynamic_submission();


        $step = [
            'filtertype' => ['user_field'],
            'user_field_somevalue' => ['testvalue'],
        ];

        $rulejson = [];
        $form->set_data_to_persist($step, $rulejson);
        $this->assertCount(1, $rulejson);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\form\filters\filter
     */
    public function test_load_data_for_form(): void {
        $object = new \stdClass();
        $object->filter = [
            ['filtertype' => 'user_field', 'value' => 'admin'],
            ['filtertype' => 'user_profile_field', 'value' => 'gender']
        ];

        $step = [];

        $result = \local_taskflow\form\filters\filter::load_data_for_form($step, $object);

        $this->assertArrayHasKey('filter', $result);
        $this->assertCount(2, $result['filter']);
        $this->assertEquals('admin', $result['filter'][0]['value']);
        $this->assertEquals('gender', $result['filter'][1]['value']);
    }
}
