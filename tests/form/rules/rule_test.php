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

namespace local_taskflow\form\rules;

use advanced_testcase;
use local_taskflow\form\rules\types\unit_rule;
use MoodleQuickForm;

/**
 * Rules table
 *
 * @package     local_taskflow
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rule_test extends advanced_testcase {
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
     * @covers \local_taskflow\form\rules\rule
     */
    public function test_set_data_sets_targettype_to_user_if_no_unitid(): void {
        $form = $this->getMockBuilder(rule::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['set_data'])
            ->getMock();

        $formdata = ['userid' => 123];

        $form->expects($this->once())
            ->method('set_data')
            ->with($this->callback(function ($data) {
                return $data['targettype'] === 'user_target';
            }));

        $reflection = new \ReflectionClass($form);
        $property = $reflection->getProperty('_ajaxformdata');
        $property->setAccessible(true);
        $property->setValue($form, $formdata);

        $form->set_data_for_dynamic_submission();
        $form->get_page_url_for_dynamic_submission();
        $form->definition_after_data();
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\form\rules\rule
     */
    public function test_set_data_sets_targettype_to_unit_if_unitid_is_set(): void {
        $form = $this->getMockBuilder(rule::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['set_data'])
            ->getMock();

        $formdata = ['userid' => 123, 'unitid' => 5];

        $form->expects($this->once())
            ->method('set_data')
            ->with($this->callback(function ($data) {
                return $data['targettype'] === 'unit_target';
            }));

        $reflection = new \ReflectionClass($form);
        $property = $reflection->getProperty('_ajaxformdata');
        $property->setAccessible(true);
        $property->setValue($form, $formdata);

        $form->set_data_for_dynamic_submission();
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\form\rules\rule
     */
    public function test_load_data_for_form_populates_step(): void {
        $step = ['stepidentifier' => 'teststep'];
        $object = (object)[
            'name' => 'Test Rule',
            'unitid' => 42,
            'enabled' => 1,
        ];

        $result = rule::load_data_for_form($step, $object);

        $this->assertEquals('Test Rule', $result['name']);
        $this->assertEquals(42, $result['unitid']);
        $this->assertEquals(1, $result['enabled']);
        $this->assertEquals('teststep', $result['stepidentifier']);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\form\rules\rule
     */
    public function test_definition_adds_expected_form_elements(): void {
        global $CFG;
        require_once($CFG->libdir . '/formslib.php');

        // Step 1: Create a mock MoodleQuickForm object.
        $formmock = $this->getMockBuilder(MoodleQuickForm::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addElement', 'setType', 'addRule', 'setDefault', 'hideIf', 'disabledIf'])
            ->getMock();

        $formmock->expects($this->once())->method('addRule')
            ->with('name', null, 'required', null, 'client');

        $formmock->expects($this->atLeastOnce())->method('setDefault');
        $formmock->expects($this->atLeastOnce())->method('hideIf');
        $formmock->expects($this->atLeastOnce())->method('disabledIf');

        // Step 3: Create a real instance of the rule form (requires dummy params).
        $form = $this->getMockBuilder(\local_taskflow\form\rules\rule::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        // Step 4: Inject the mocked form into the protected $_form property.
        $reflection = new \ReflectionClass($form);
        $property = $reflection->getProperty('_form');
        $property->setAccessible(true);
        $property->setValue($form, $formmock);

        $formdata = [
            'uniqueid' => 'testid',
            'step' => '1',
            'recordid' => 0,
        ];
        $customdataprop = $reflection->getProperty('_customdata');
        $customdataprop->setAccessible(true);
        $customdataprop->setValue($form, $formdata);

        // Step 5: Call the method under test.
        $reflectionmethod = $reflection->getMethod('definition');
        $reflectionmethod->setAccessible(true);
        $reflectionmethod->invoke($form);
    }
}
