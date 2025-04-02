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
 * Unit class to manage users.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\eventhandlers;

use advanced_testcase;
use local_taskflow\local\personas\unit_member;

/**
 * Class unit_member
 *
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class moodle_user_created_updated_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        \local_taskflow\local\units\unit_relations::reset_instances();
        $this->set_rules();
    }

    /**
     * Setup the test environment.
     */
    protected function set_rules(): void {
        $unitids[] = $this->create_unit_with_rules('/../mock/rules/taskflow_rule.json', 'IT Department');
        $unitids[] = $this->create_unit_with_rules('/../mock/rules/taskflow_master_rule.json', 'Management');
        $this->create_unit_relation($unitids);
    }

    /**
     * Setup the test environment.
     * @param string $pathtorulefile
     * @param string $unitname
     * @return int
     */
    protected function create_unit_with_rules($pathtorulefile, $unitname): int {
        global $DB;
        $rules = json_decode(file_get_contents(__DIR__ . $pathtorulefile));
        $unitrecord = (object) [
            'name' => $unitname,
            'criteria' => null,
            'timecreated' => time(),
            'timemodified' => time(),
            'usermodified' => time(),
        ];
        $unitid = $DB->insert_record('local_taskflow_units', $unitrecord);
        foreach ($rules as $rule) {
            $rule->unitid = $unitid;
            $DB->insert_record('local_taskflow_rules', $rule);
        }
        return $unitid;
    }

    /**
     * Setup the test environment.
     * @param string $unitids
     */
    protected function create_unit_relation($unitids): void {
        global $DB;
        $record = (object) [
            'childid' => $unitids[0],
            'parentid' => $unitids[1],
            'timecreated' => time(),
            'timemodified' => time(),
            'usermodified' => time(),
            'active' => 1,
        ];
        $DB->insert_record('local_taskflow_unit_rel', $record);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\eventhandlers\core_user_created_updated
     * @covers \local_taskflow\local\personas\moodle_user_units
     * @covers \local_taskflow\local\rules\assignment_filter
     * @covers \local_taskflow\local\rules\unit_rules
     */
    public function test_moodle_user_updated(): void {
        global $DB;
        $user = new \stdClass();
        $user->auth = 'manual';
        $user->confirmed = 1;
        $user->mnethostid = 1;
        $user->username = 'hans' . rand(1000, 9999); // prevent conflicts
        $user->email = $user->username . '@example.com';
        $user->firstname = 'Hans';
        $user->lastname = 'Mustermann';
        $user->password = 'Testpass1#';
        $user->timecreated = time();
        $user->timemodified = time();

        require_once(__DIR__ . '/../../../../user/lib.php');
        $user->id = user_create_user($user);
        $itdepartment = $DB->get_record(
            'local_taskflow_units',
            ['name' => 'IT Department'],
            'id'
        );
        unit_member::create($user->id, $itdepartment->id);
        $user->firstname = 'Peter';
        require_once(__DIR__ . '/../../../../user/lib.php');
        user_update_user($user);
    }
}
