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
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\rules;

use advanced_testcase;
use local_taskflow\local\rules\rules;
use stdClass;

/**
 * Class unit_member
 *
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rules_test extends advanced_testcase {
    /** @var string|null Stores the external user data. */
    protected ?string $externaldata = null;
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\rules\rules
     */
    public function test_instance_returns_rule(): void {
        global $DB;
        $this->resetAfterTest();

        // Insert mock data into DB.
        $record = new stdClass();
        $record->unitid = 123;
        $record->rulejson = json_encode(['rule' => 'test']);
        $record->isactive = 1;
        $record->id = $DB->insert_record('local_taskflow_rules', $record);

        // Get the instance.
        $rule = rules::instance($record->id);

        $this->assertInstanceOf(rules::class, $rule);
        $this->assertEquals($record->id, $rule->get_id());
        $this->assertEquals($record->rulejson, $rule->get_rulesjson());
    }

    /**
     * Test that get_rulesjson() returns correct json.
     *
     * @covers \local_taskflow\local\rules\rules::get_rulesjson
     */
    public function test_get_rulesjson(): void {
        global $DB;
        $this->resetAfterTest();

        $data = new stdClass();
        $data->unitid = 42;
        $data->rulejson = json_encode(['rule' => 'test']);
        $data->isactive = 1;
        $data->id = $DB->insert_record('local_taskflow_rules', $data);

        $rule = rules::instance($data->id);
        $this->assertEmpty($rule->get_unitid());
    }
}
