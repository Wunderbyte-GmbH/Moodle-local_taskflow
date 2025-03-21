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

namespace local_taskflow\units_relation;

use advanced_testcase;
use cache_helper;
use local_taskflow\local\external_adapter\external_api_user_data;
use local_taskflow\local\units\unit_hierarchy;

/**
 * Test unit class of local_taskflow.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class units_hierarchy_structure_test extends advanced_testcase {
    /** @var string|null Stores the external user data. */
    protected ?string $externaldata = null;

    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->externaldata = file_get_contents(__DIR__ . '/../mock/mock_user_data_hierarchy_structure.json');
        $this->set_config_values();
    }

    /**
     * Setup the test environment.
     */
    protected function set_config_values(): void {
        global $DB;
        $settingvalues = [
            'translator_first_name' => "name->firstname",
            'translator_second_name' => "name->secondname",
            'translator_email' => "mail",
            'translator_units' => "ou",
            'testing' => "Testing",
        ];
        foreach ($settingvalues as $key => $value) {
            set_config($key, $value, 'local_taskflow');
        }
        cache_helper::invalidate_by_event('config', ['local_taskflow']);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\units\unit_hierarchy::__construct
     * @covers \local_taskflow\local\units\unit_hierarchy::get_hierarchy
     * @covers \local_taskflow\local\units\unit_hierarchy::build_hierarchy
     * @covers \local_taskflow\local\units\unit_hierarchy::check_and_set_master
     * @covers \local_taskflow\local\units\unit_relations::get_all_active_unit_relations
     * @covers \local_taskflow\local\units\unit_hierarchy::get
     * @covers \local_taskflow\local\units\unit_hierarchy::get_organisational_unit
     * @covers \local_taskflow\local\units\unit_hierarchy::invalidate_cache
     * @covers \local_taskflow\local\eventhandlers\user_externally_updated::handle
     */
    public function test_external_data_is_loaded(): void {
        global $DB;
        $apidatamanager = new external_api_user_data($this->externaldata);
        $externaldata = $apidatamanager->get_external_data();
        $this->assertNotEmpty($externaldata, 'External user data should not be empty.');
        $apidatamanager->process_incoming_data();
        $hierarchymanager = new unit_hierarchy();
        $structure = $hierarchymanager->get();
        $this->assertNotEmpty($structure, 'Hierarchy should not be empty.');
        $this->assertCount(10, $structure);

        $ou = $hierarchymanager->get_organisational_unit(array_key_first($structure));
        $this->assertArrayHasKey('depth', $ou);
        $this->assertArrayHasKey('pathtoou', $ou);

        $hierarchymanager = new unit_hierarchy();
        $cachedstructure = $hierarchymanager->get();
        $this->assertEquals($structure, $cachedstructure, 'Cached hierarchy should match the generated hierarchy.');

        $hierarchymanager->invalidate_cache();
        $cache = \cache::make('local_taskflow', 'unit_hierarchy');
        $this->assertFalse($cache->get('full_hierarchy'), 'Cache should be empty after invalidation.');
    }
}
