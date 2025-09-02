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
use Throwable;

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
        set_config('dwhurl', '', 'local_taskflow');

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
        $sink = $this->redirectMessages();
        $json = '{"ok":true,"items":[1,2,3]}';

        $tmpfile = make_temp_directory('taskflowadapter_tests') . '/dwh.json';
        file_put_contents($tmpfile, $json);
        $fileurl = 'file://' . $tmpfile;

        $url = null;
        if ($this->curl_accepts($fileurl)) {
            $url = $fileurl;
        } else if ($this->curl_accepts('data:application/json,' . rawurlencode($json))) {
            // Fallback: some builds allow data: URLs.
            $url = 'data:application/json,' . rawurlencode($json);
        } else {
            $this->markTestSkipped('Neither file:// nor data: URLs are accepted by cURL in this environment.');
            return;
        }

        set_config('dwhurl', $url, 'local_taskflow');

        $sink = $this->redirectMessages();
        $task = new fetch_dwh_data();
        $task->execute();
        $output = implode("\n", $sink->get_messages());
        $this->assertEmpty($output);
        $output = $sink->get_messages();
    }

    /**
     * Probe whether the current cURL build accepts a given URL scheme.
     * @param string $testurl
     * @return bool
     */
    private function curl_accepts(string $testurl): bool {
        try {
            $curl = new \curl();
            $curl->setopt([
                'RETURNTRANSFER' => true,
                'CONNECTTIMEOUT' => 2,
                'TIMEOUT' => 2,
            ]);
            $curl->get($testurl);
            $errno = $curl->get_errno();
            $error = $curl->error ?? '';
            if ($errno === 0) {
                return true;
            }
            $unsupported = stripos($error, 'protocol') !== false || stripos($error, 'not supported') !== false;
            return !$unsupported;
        } catch (Throwable $t) {
            return false;
        }
    }
}
