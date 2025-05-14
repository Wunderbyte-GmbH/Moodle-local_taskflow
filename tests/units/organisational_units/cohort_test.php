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

namespace local_taskflow;

use advanced_testcase;
use cache_helper;
use local_taskflow\local\units\organisational_unit_factory;

/**
 * Test unit class of local_taskflow.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class cohort_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        \local_taskflow\local\units\unit_relations::reset_instances();
        \local_taskflow\local\units\organisational_units\unit::reset_instances();
        \local_taskflow\local\units\organisational_units\cohort::reset_instances();
        $this->set_config_values();
    }

    /**
     * Setup the test environment.
     */
    protected function set_config_values(): void {
        global $DB;
        $settingvalues = [
            'translator_user_first_name' => "name->firstname",
            'translator_user_last_name' => "name->lastname",
            'translator_user_email' => "mail",
            'translator_user_units' => "ou",
            'organisational_unit_option' => "cohort",
        ];
        foreach ($settingvalues as $key => $value) {
            set_config($key, $value, 'local_taskflow');
        }
        cache_helper::invalidate_by_event('config', ['local_taskflow']);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\units\organisational_units\cohort
     * @covers \local_taskflow\local\units\organisational_unit_factory
     */
    public function test_construct(): void {
        global $DB;
        $record = (object) [
            'name' => 'Testing HR',
        ];

        $dbcohortid = $this->set_db_data();
        $dbunitinstance = organisational_unit_factory::instance($dbcohortid);

        $dbrecord = $DB->get_record('cohort', ['id' => $dbcohortid]);
        $samedbunitinstance = organisational_unit_factory::create_unit((object)$dbrecord);

        $this->assertEquals('Testing', $dbunitinstance->get_name());
        $this->assertEquals($dbunitinstance, $samedbunitinstance);

        $unitinstance = organisational_unit_factory::create_unit($record);
        $sameunitinstance = organisational_unit_factory::instance($unitinstance->get_id());

        $this->assertEquals($unitinstance, $sameunitinstance);
        $this->assertEquals('Testing HR', $unitinstance->get_name());

        $unitinstance->update(null);
        $this->assertEquals('Testing HR', $unitinstance->get_name());

        $unitinstance->update('Testing new HR');
        $this->assertNotEquals('Testing HR', $unitinstance->get_name());

        $this->assertEquals(1, $unitinstance->get_contextid());

        $this->assertEquals(1, $unitinstance->get_component());

        $unitinstance->add_member(1);
        $this->assertTrue($unitinstance->is_member(1));

        $members = $unitinstance->get_members();
        $this->assertCount(1, $members);

        $this->assertEquals($unitinstance->count_members(), 1);

        $unitinstance->delete_member(1);
        $this->assertFalse($unitinstance->is_member(1));

        $unitinstance->delete();
        $this->assertFalse(
            $DB->record_exists('cohort', ['id' => $unitinstance->get_id()])
        );
    }

    /**
     * Setup the test environment.
     * @return int
     */
    protected function set_db_data(): int {
        global $DB;
        $record = (object) [
            'contextid' => 33,
            'name' => "Testing",
            'idnumber' => 32,
            'description' => "ououou",
            'descriptionformat' => 1,
            'visible' => 1,
            'component' => 'testing',
            'timecreated' => time(),
            'timemodified' => time(),
        ];

        return $DB->insert_record('cohort', $record);
    }
}
