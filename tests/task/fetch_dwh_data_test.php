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

namespace local_taskflow\task;

use advanced_testcase;
use taskflowadapter_tuines\task\fetch_dwh_data;

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
final class fetch_dwh_data_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        \local_taskflow\local\units\unit_relations::reset_instances();
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \taskflowadapter_tuines\task\fetch_dwh_data
     */
    public function test_execute_without_url_prints_message_and_returns(): void {
        set_config('dwhurl', '', 'taskflowadapter_tuines');

        $sink = $this->redirectMessages();
        $task = new fetch_dwh_data();
        $task->execute();
        $output = $sink->get_messages();

        $this->assertEmpty($output);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \taskflowadapter_tuines\task\fetch_dwh_data
     */
    public function test_execute_success_path_with_simulated_response(): void {
        $url = 'http://example.com';
        set_config('dwhurl', $url, 'taskflowadapter_tuines');

        $sink = $this->redirectMessages();

        $task = new fetch_dwh_data();
        $task->execute();
        $output = implode("\n", $sink->get_messages());
        $this->assertEmpty($output);
        $output = $sink->get_messages();
        $this->assertNotEmpty($task->get_name());
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \taskflowadapter_tuines\task\fetch_dwh_data
     */
    public function test_execute_success_with_fake_empty_response(): void {
        $url = 'http://example.com';
        set_config('dwhurl', $url, 'taskflowadapter_tuines');

        $fakecurl = $this->createMock(\curl::class);
        $fakecurl->method('get')->willReturn(json_encode(['testing' => ['a' => 1]]));
        $fakecurl->method('get_errno')->willReturn(0);

        $task = $this->getMockBuilder(fetch_dwh_data::class)
            ->onlyMethods(['make_curl'])
            ->getMock();

        $task->method('make_curl')->willReturn($fakecurl);

        $result = $task->execute();

        $this->assertStringContainsString('The DWH response was empty or invalid', $result);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \taskflowadapter_tuines\task\fetch_dwh_data
     */
    public function test_execute_success_with_fake_response(): void {
        $url = 'http://example.com';
        set_config('dwhurl', $url, 'taskflowadapter_tuines');

        $fakecurl = $this->createMock(\curl::class);
        $fakecurl->method('get')->willReturn(json_encode(['persons' => ['a']]));
        $fakecurl->method('get_errno')->willReturn(0);

        $task = $this->getMockBuilder(fetch_dwh_data::class)
            ->onlyMethods(['make_curl'])
            ->getMock();

        $task->method('make_curl')->willReturn($fakecurl);

        $result = $task->execute();

        $this->assertStringContainsString('Fetched and processed', $result);
    }
}
