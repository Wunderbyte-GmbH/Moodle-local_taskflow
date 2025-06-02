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
use context_system;
use local_multistepform\manager;
use moodle_url;
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
final class form_base_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\form\form_base
     */
    public function test_get_page_url(): void {
        $form = new class extends form_base {
            /**
             * Mock function.
             */
            protected function definition(): void {
            }
        };
        $reflection = new ReflectionClass($form);
        $method = $reflection->getMethod('get_page_url');
        $method->setAccessible(true);

        $url = $method->invoke($form);
        $this->assertInstanceOf(moodle_url::class, $url);
        $this->assertStringContainsString('/local/taskflow/editrules.php', $url->out(false));
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\form\form_base
     */
    public function test_get_context_for_dynamic_submission(): void {
        $form = new class extends \local_taskflow\form\form_base {
            /**
             * Mock function.
             */
            protected function definition(): void {
            }
        };

        $reflection = new ReflectionClass($form);
        $method = $reflection->getMethod('get_context_for_dynamic_submission');
        $method->setAccessible(true);

        $context = $method->invoke($form);
        $this->assertInstanceOf(context_system::class, $context);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\form\form_base
     */
    public function test_get_page_url_for_dynamic_submission(): void {
        global $USER;

        $USER = $this->getDataGenerator()->create_user();
        $this->setUser($USER);
        $form = $this->getMockBuilder(form_base::class)
            ->onlyMethods(['get_data'])
            ->disableOriginalConstructor()
            ->getMock();

        $formdata = (object)[
            'uniqueid' => 'testid123',
            'recordid' => 42,
            'step' => 1,
        ];

        $url = $form->get_page_url_for_dynamic_submission();
        $form->set_data_for_dynamic_submission();
        $this->assertInstanceOf(moodle_url::class, $url);
        $this->assertStringContainsString('/local/taskflow/editrules.php', $url->out(false));
    }
}
