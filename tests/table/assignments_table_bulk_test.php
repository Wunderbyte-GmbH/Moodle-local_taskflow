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

namespace local_taskflow\table;

use advanced_testcase;
use local_taskflow\local\assignment_status\assignment_status_facade;

/**
 * Bulk actions of the dashboard page: rights are checked per row, the existing update service does the work.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_taskflow\table\assignments_table
 */
final class assignments_table_bulk_test extends advanced_testcase {
    /**
     * Setup.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('external_api_option', 'standard', 'local_taskflow');
        set_config('organisational_unit_option', 'cohort', 'local_taskflow');
    }

    /**
     * Creates an active assignment for a new user.
     *
     * @param int $ruleid
     * @return int assignment id
     */
    private function create_assignment(int $ruleid): int {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_taskflow');
        $generator->create_user_assignment((int)$user->id, $ruleid);
        return (int)$DB->get_field('local_taskflow_assignment', 'id', ['userid' => $user->id, 'ruleid' => $ruleid], MUST_EXIST);
    }

    /**
     * An editor pauses every selected row; a user without rights changes nothing.
     */
    public function test_pause_respects_rights(): void {
        global $DB;
        $generator = $this->getDataGenerator()->get_plugin_generator('local_taskflow');
        $ruleid = $generator->create_rule(['name' => 'Bulk rule']);
        $first = $this->create_assignment($ruleid);
        $second = $this->create_assignment($ruleid);
        $payload = json_encode(['checkedids' => [$first, $second]]);
        $paused = assignment_status_facade::get_status_identifier('paused');

        // Without rights: both rows are skipped.
        $this->setUser($this->getDataGenerator()->create_user());
        $table = new assignments_table('bulktest');
        $result = $table->action_pauseassignments(-1, $payload);
        $this->assertSame(0, $result['success']);
        $this->assertNotEquals($paused, (int)$DB->get_field('local_taskflow_assignment', 'status', ['id' => $first]));

        // As admin (editassignment): both rows are paused.
        $this->setAdminUser();
        $result = $table->action_pauseassignments(-1, $payload);
        $this->assertSame(1, $result['success']);
        $this->assertEquals($paused, (int)$DB->get_field('local_taskflow_assignment', 'status', ['id' => $first]));
        $this->assertEquals($paused, (int)$DB->get_field('local_taskflow_assignment', 'status', ['id' => $second]));
    }

    /**
     * Extending skips assignments whose rule has no extension period and extends the others.
     */
    public function test_extend_uses_rule_extension_period(): void {
        global $DB;
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_taskflow');
        $withperiod = $generator->create_rule(['name' => 'With period', 'extensionperiod' => 7 * DAYSECS]);
        $noperiod = $generator->create_rule(['name' => 'No period', 'extensionperiod' => 0]);
        $extendable = $this->create_assignment($withperiod);
        $fixed = $this->create_assignment($noperiod);
        $before = (int)$DB->get_field('local_taskflow_assignment', 'duedate', ['id' => $fixed]);

        $table = new assignments_table('bulktest');
        $result = $table->action_extendduedate(-1, json_encode(['checkedids' => [$extendable, $fixed]]));

        $this->assertSame(1, $result['success']);
        $this->assertGreaterThanOrEqual(
            time() + 7 * DAYSECS - 5,
            (int)$DB->get_field('local_taskflow_assignment', 'duedate', ['id' => $extendable])
        );
        $this->assertSame($before, (int)$DB->get_field('local_taskflow_assignment', 'duedate', ['id' => $fixed]));
    }
}
