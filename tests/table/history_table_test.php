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

/**
 * Rules table.
 *
 * @package     local_taskflow
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\table;

use advanced_testcase;
use core_user;
use local_taskflow\local\assignments\status\assignment_status;
use local_taskflow\local\history\history;
use stdClass;

/**
 * Rules table
 *
 * @package     local_taskflow
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class history_table_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        \local_taskflow\local\units\unit_relations::reset_instances();
        set_config(
            'organisational_unit_option',
            'cohort',
            'local_taskflow'
        );
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\table\history_table
     */
    public function test_col_createdby(): void {
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Max', 'lastname' => 'Mustermann']);
        $table = new history_table('dummy');

        $values = new stdClass();
        $values->createdby = $user->id;

        $fullname = fullname(core_user::get_user($user->id));
        $this->assertEquals($fullname, $table->col_createdby($values));
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\table\history_table
     */
    public function test_col_timecreated(): void {
        $table = new history_table('dummy');

        $values = new stdClass();
        $values->timecreated = 1721000000;

        $expected = userdate($values->timecreated, get_string('strftimedatetime', 'langconfig'));
        $this->assertEquals($expected, $table->col_timecreated($values));
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\table\history_table
     * @covers \local_taskflow\local\history\history
     */
    public function test_col_type(): void {
        $table = new history_table('dummy');
        $values = new stdClass();

        $map = [
            history::TYPE_MESSAGE => 'status:messagesent',
            history::TYPE_MANUAL_CHANGE => 'status:manualchange',
            history::TYPE_LIMIT_REACHED => 'status:limitreached',
            history::TYPE_USER_ACTION => 'status:useraction',
        ];

        foreach ($map as $constant => $langkey) {
            $values->type = $constant;
            $this->assertEquals(get_string($langkey, 'local_taskflow'), $table->col_type($values));
        }

        $values->type = 'UNKNOWN_TYPE';
        $this->assertEquals('UNKNOWN_TYPE', $table->col_type($values));
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\table\history_table
     * @covers \local_taskflow\local\history\history
     * @covers \local_taskflow\local\assignments\status\assignment_status
     * @covers \local_taskflow\local\history\types\base
     * @covers \local_taskflow\local\history\types\typesfactory
     */
    public function test_col_data(): void {
        $table = new history_table('dummy');

        // Prepare faked changereasons and statuses for the static methods.
        $changereasons = assignment_status::get_all_changereasons();
        $statuses = assignment_status::get_all();

        $firstchangereasonkey = array_key_first($changereasons);
        $firstchangereason = $changereasons[$firstchangereasonkey];
        $firststatuskey = array_key_first($statuses);
        $firststatus = $statuses[$firststatuskey];

        $data = [
            'data' => [
                'change_reason' => $firstchangereasonkey,
                'comment' => 'Testkommentar',
                'status' => $firststatuskey,
            ],
        ];

        $values = new stdClass();
        $values->data = json_encode($data);
        $values->type = 'manual_change';

        $output = $table->col_data($values);

        $this->assertStringContainsString($firstchangereason, $output);
        $this->assertStringContainsString('Testkommentar', $output);
        $this->assertStringContainsString($firststatus, $output);
    }
}
