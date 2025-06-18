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

namespace local_taskflow\form\messages;

use advanced_testcase;
use local_multistepform\local\cachestore;
use ReflectionMethod;

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
final class messages_test extends advanced_testcase {

    /**
     * Example test: Ensure external data is loaded.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\form\messages\messages
     */
    public function test_definition_with_user_target(): void {
        // Mock form customdata with user_target.
        $customdata = [
            'uniqueid' => 'testid',
            'recordid' => 1,
            'step' => [
                'stepidentifier' => 'mockstep',
            ],
        ];

        // Inject fake data into the cache store.
        $cache = new cachestore();
        $cache->set_multiform('testid', 1, [
            'steps' => [
                1 => [
                    'targettype' => 'user_target',
                ],
            ],
        ]);

        // Instantiate the form using actual class (this works because moodleform doesn't execute `definition()` in constructor).
        $form = new messages(null, $customdata);

        // Call `definition()` using reflection because it's protected.
        $ref = new \ReflectionClass($form);
        $method = $ref->getMethod('definition');
        $method->setAccessible(true);
        $method->invoke($form);

        $this->assertInstanceOf(messages::class, $form);
    }

    /**
     * Combined test for all form methods.
     * @covers \local_taskflow\form\messages\messages
     * @covers \local_taskflow\form\messages\form_packages
     * @covers \local_taskflow\form\messages\form_messages
     * @covers \local_taskflow\form\messages\messages
     */
    public function test_all_form_methods(): void {
        // Create test form with valid customdata.
        $customdata = [
            'uniqueid' => 'unittestform',
            'recordid' => 0,
            'step' => [
                'stepidentifier' => 'msgstep',
            ],
        ];
        $form = new messages(null, $customdata);

        // Call protected method set_data_for_dynamic_submission using reflection.
        $setdatamethod = new ReflectionMethod($form, 'set_data_for_dynamic_submission');
        $setdatamethod->setAccessible(true);
        $setdatamethod->invoke($form);

        // Prepare step mock data.
        $stepdata = [
            'messageids' => [12, 23],
            'type' => 'standard',
            'heading' => 'Test Heading',
            'body' => 'Test body text',
        ];

        $rulejson = [];

        $form->set_data_to_persist($stepdata, $rulejson);

        // Load_data_for_form: prepare dummy rule object and call.
        $dummy = (object)[
            'actions' => [
                (object)[
                    'messages' => [
                        [
                            'type' => 'standard',
                            'heading' => 'Restored heading',
                            'body' => 'Restored body',
                        ],
                    ],
                ],
            ],
        ];
        $formdata = messages::load_data_for_form([], $dummy);
        $this->assertArrayHasKey('messages', $formdata);
        $this->assertEquals('Restored heading', $formdata['messages'][0]['heading']);
    }
}
