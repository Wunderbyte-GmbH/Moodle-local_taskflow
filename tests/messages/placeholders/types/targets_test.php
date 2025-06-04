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

namespace local_taskflow\messages\placeholders\types;

use advanced_testcase;
use local_taskflow\local\messages\placeholders\types\targets;
use stdClass;

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
final class targets_test extends advanced_testcase {
    /**
     * Setup the test environment.
     * @covers \local_taskflow\local\rules\rules
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        \local_taskflow\local\rules\rules::reset_instances();
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\messages\placeholders\types\targets
     */
    public function test_render_replaces_placeholder(): void {
        global $USER, $DB;

        // Fake user setup.
        $this->setAdminUser();
        $userid = $USER->id;

        // Register a fake rule instance.
        $rule = new stdClass();
        $rule->name = 'Testing';
        $rule->unitid = 4;
        $rule->rulejson = json_encode((object)[
            'rulejson' => (object)[
                'rule' => (object)[
                    'actions' => [
                        (object)[
                            'messages' => [(object)['messageid' => 123]],
                            'targets' => [
                                (object)[
                                    'targettype' => 'mocktarget',
                                    'targetid' => 99,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $rule->id = $DB->insert_record('local_taskflow_rules', $rule);

        // Patch rules::instance().
        $rulesmock = $this->getMockBuilder(\local_taskflow\local\rules\rules::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_rulesjson'])
            ->getMock();
        $rulesmock->method('get_rulesjson')->willReturn(json_encode($rule->rulejson));

        // Construct message object.
        $message = new stdClass();
        $message->id = 123;
        $message->message = [
            'heading' => 'Title with {targets}',
            'body' => 'Hello {targets}, welcome!',
        ];

        $placeholder = new targets($rule->id, $userid);
        $placeholder->render($message);

        // Assert replacements.
        $this->assertEquals('Title with ', $message->message['heading']);
        $this->assertEquals('Hello , welcome!', $message->message['body']);
    }
}
