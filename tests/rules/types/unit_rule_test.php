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

namespace local_taskflow\rules\types;

use advanced_testcase;
use local_taskflow\local\rules\rules;
use local_taskflow\local\rules\types\unit_rule;
use stdClass;

/**
 * Class unit_member
 *
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class unit_rule_test extends advanced_testcase {
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
     * @covers \local_taskflow\local\rules\types\unit_rule
     */
    public function test_create_rule_inserts_when_not_existing(): void {
        global $DB;

        $this->setAdminUser();

        // Create the table schema.
        $this->create_temp_table();

        $rule = (object)[
            'unitid' => 42,
            'rulejson' => '{"type":"role"}',
            'isactive' => 1,
        ];

        // Should create new rule because none exist yet.
        $result = unit_rule::create_rule($rule);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals(42, $result[0]->get_unitid());
        $this->assertEquals('{"type":"role"}', $result[0]->get_rulesjson());
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\rules\types\unit_rule
     */
    public function test_instance_returns_cached_rules(): void {
        global $DB;

        $this->setAdminUser();
        $this->create_temp_table();

        $rule = (object)[
            'unitid' => 7,
            'rulejson' => '{"x":"y"}',
            'isactive' => 0,
        ];

        // Manually insert a rule.
        $rule->id = $DB->insert_record('local_taskflow_rules', $rule);

        $instances = unit_rule::instance(7);

        $this->assertIsArray($instances);
        $this->assertCount(1, $instances);
        $this->assertEquals(7, $instances[0]->get_unitid());
        $this->assertEquals('{"x":"y"}', $instances[0]->get_rulesjson());
    }

    /**
     * Create the table schema for local_taskflow_rules.
     */
    private function create_temp_table(): void {
        global $DB;
        $dbman = $DB->get_manager();

        $table = new \xmldb_table('local_taskflow_rules');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('unitid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('rulejson', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('isactive', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, 1);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
    }

}
