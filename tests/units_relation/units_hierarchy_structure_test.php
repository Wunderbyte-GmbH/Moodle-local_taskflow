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
use local_taskflow\local\external_adapter\external_api_base;
use local_taskflow\local\external_adapter\external_api_repository;
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
        \local_taskflow\local\units\unit_relations::reset_instances();
        $this->externaldata = file_get_contents(__DIR__ . '/../mock/mock_user_data_hierarchy_structure.json');
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');

        $plugingenerator->create_custom_profile_fields([
            'supervisor',
            'units',
        ]);
        $plugingenerator->set_config_values();
    }

    /**
     * Tear down the test environment.
     *
     * @return void
     *
     */
    protected function tearDown(): void {
        parent::tearDown();
        external_api_base::teardown();
        \local_taskflow\local\units\unit_relations::reset_instances();
    }

    /**
     * Setup the test environment.
     */
    protected function set_config_values(): void {
        global $DB;
        $settingvalues = [
            'translator_user_firstname' => "name->firstname",
            'translator_user_lastname' => "name->lastname",
            'translator_user_email' => "mail",
            'translator_user_units' => "ou",
            'testing' => "Testing",
        ];
        foreach ($settingvalues as $key => $value) {
            set_config($key, $value, 'local_taskflow');
        }
        cache_helper::invalidate_by_event('config', ['local_taskflow']);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\units\unit_hierarchy
     * @covers \local_taskflow\local\eventhandlers\unit_member_updated
     * @covers \local_taskflow\local\units\unit_relations
     * @covers \local_taskflow\local\external_adapter\external_api_repository
     * @covers \local_taskflow\local\personas\unit_members\moodle_unit_member_facade
     * @covers \local_taskflow\local\personas\moodle_users\moodle_user_factory
     * @covers \local_taskflow\local\users_profile\types\thour
     */
    public function test_external_data_is_loaded(): void {
        global $DB;
        $apidatamanager = external_api_repository::create($this->externaldata);
        $externaldata = $apidatamanager->get_external_data();
        $this->assertNotEmpty($externaldata, 'External user data should not be empty.');
        $apidatamanager->process_incoming_data();
        $hierarchymanager = new unit_hierarchy();
        $structure = $hierarchymanager->get();
        $this->assertNotEmpty($structure, 'Hierarchy should not be empty.');
        $this->assertCount(12, $structure);

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
