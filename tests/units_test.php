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
use local_taskflow\local\units\organisational_unit_factory;
use moodle_exception;

/**
 * Test unit class of local_taskflow.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class units_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingenerator->create_custom_profile_fields([
            'supervisor',
            'units',
        ]);
        $plugingenerator->set_config_values(
            'standard',
            [
                'organisational_unit_option' => 'unit',
            ]
        );
        \local_taskflow\local\units\unit_relations::reset_instances();
    }

    /**
     * Data provider for creating, updating, and deleting units.
     *
     * @return array
     */
    public static function unit_data_provider(): array {
        return [
            ['Unit 1', json_encode(['field' => 'value1'])],
            ['Unit 2', json_encode(['field' => 'value2'])],
            ['Unit 3', json_encode(['field' => 'value3'])],
            ['Unit 4', json_encode(['field' => 'value4'])],
            ['Unit 5', json_encode(['field' => 'value5'])],
        ];
    }

    /**
     * Test creating, updating, and deleting multiple units using a data provider.
     * @covers \local_taskflow\local\units\organisational_units\unit::create
     * @dataProvider unit_data_provider
     * @param string $name The name of the unit.
     * @return void
     */
    public function test_create_update_delete_unit($name): void {
        global $DB, $USER;

        // Step 1: Create a unit.
        $record = (object) [
            'name' => $name,
        ];
        $unit = organisational_unit_factory::create_unit($record);

        // Verify the unit exists in the database.
        $record = $DB->get_record('local_taskflow_units', ['id' => $unit->get_id()], '*', MUST_EXIST);
        $this->assertEquals($name, $record->name);
        $this->assertEquals($USER->id, $record->usermodified);

        // Verify the unit instance properties.
        $this->assertEquals($name, $unit->get_name());

        // Step 2: Update the unit.
        $newname = $name . ' Updated';
        $unit->update($newname);

        // Verify the unit is updated in the database.
        $updatedname = $unit::instance($unit->get_id())->get_name();
        $this->assertEquals($newname, $updatedname);

        // Step 3: Delete the unit.
        $unit->delete();

        // Verify the unit no longer exists in the database.
        $this->assertFalse($DB->record_exists('local_taskflow_units', ['id' => $unit->get_id()]));
    }

    /**
     * Test retrieving an invalid unit instance.
     * @covers \local_taskflow\local\units\organisational_units\unit
     */
    public function test_instance_invalid_id(): void {
        $this->expectException(moodle_exception::class);
        set_config('organisational_unit_option', 'testing_unit_invalid', 'local_taskflow');
        organisational_unit_factory::instance(999999);
    }
}
