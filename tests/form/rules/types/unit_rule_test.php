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

namespace local_taskflow\form\rules\types;

use advanced_testcase;
use MoodleQuickForm;
use stdClass;

/**
 * Rules table
 *
 * @package     local_taskflow
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class unit_rule_test extends advanced_testcase {
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
        $USER = (object)['id' => 42];
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\form\rules\types\unit_rule
     */
    public function test_get_data_builds_correct_rule_data(): void {
        $class = new class {
            /**
             * Example test: Ensure external data is loaded.
             * @param array $step
             * @return array
             */
            public function set_data_to_persist(array $step) {
                return ['foo' => 'bar'];
            }
        };

        $fqcn = get_class($class);

        $steps = [
            1 => [
                'name' => 'Example Rule',
                'description' => 'Some description',
                'ruletype' => 'unit',
                'enabled' => 1,
                'unitid' => 99,
                'recordid' => null,
                'timecreated' => 0,
            ],
            2 => [
                'formclass' => $fqcn,
                'stepidentifier' => 'customstep',
            ],
        ];

        $result = unit_rule::get_data($steps);

        $this->assertEquals('Example Rule', $result['rulename']);
        $this->assertEquals(1, $result['isactive']);
        $this->assertEquals(99, $result['unitid']);
        $this->assertArrayHasKey('rulejson', $result);

        $decoded = json_decode($result['rulejson'], true);
        $this->assertEquals('Example Rule', $decoded['rulejson']['rule']['name']);
        $this->assertEquals(0, $decoded['rulejson']['rule']['usermodified']);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\form\rules\types\unit_rule
     */
    public function test_definition_after_data_adds_expected_elements(): void {
        global $CFG;

        require_once($CFG->libdir . '/formslib.php');
        require_once($CFG->dirroot . '/cohort/lib.php');

        // Create mock for MoodleQuickForm.
        $form = $this->getMockBuilder(MoodleQuickForm::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addElement', 'setType'])
            ->getMock();

        $form->expects($this->exactly(2))
            ->method('addElement')
            ->withConsecutive(
                [$this->equalTo('autocomplete'), $this->equalTo('userid'), $this->anything()],
                [$this->equalTo('autocomplete'), $this->equalTo('unitid'), $this->anything(), $this->isType('array')]
            );

        $form->expects($this->exactly(2))
            ->method('setType')
            ->withConsecutive(
                ['userid', PARAM_INT],
                ['unitid', PARAM_INT]
            );

        $data = new stdClass();

        unit_rule::definition_after_data($form, $data);
    }
}
