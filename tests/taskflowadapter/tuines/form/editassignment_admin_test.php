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
use taskflowadapter_tuines\form\editassignment_admin;

/**
 * Test unit class of local_taskflow.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \taskflowadapter_tuines\form\editassignment_admin
 */
final class editassignment_admin_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Test getting all members of a unit.
     */
    public function test_definition_contains_expected_fields(): void {
        $form = new editassignment_admin(null, []);

        $mform = $this->get_mform($form);
        $elements = $mform->_elements;

        $names = array_map(fn($el) => $el->getName(), $elements);

        $this->assertContains('id', $names);
        $this->assertContains('userid', $names);
        $this->assertContains('overduecounter', $names);
        $this->assertContains('prolongedcounter', $names);
        $this->assertContains('status', $names);
        $this->assertContains('change_reason', $names);
        $this->assertContains('comment', $names);
        $this->assertContains('duedate', $names);
        $this->assertContains('keepchanges', $names);

        $this->assertNotEmpty($form->get_page_url_for_dynamic_submission());
        $form->set_data_for_dynamic_submission();
    }

    /**
     * Test getting all members of a unit.
     */
    public function test_set_data_for_dynamic_submission_with_no_id(): void {
        $form = new editassignment_admin(null, []);
        $form->set_data_for_dynamic_submission();

        $data = $form->get_data();
        $this->assertNull($data);
    }

    /**
     * Helper to access the protected _form (HTML_QuickForm) instance.
     * @param editassignment_admin $form
     */
    private function get_mform(editassignment_admin $form): \HTML_QuickForm {
        $ref = new \ReflectionClass($form);
        $prop = $ref->getProperty('_form');
        $prop->setAccessible(true);
        /** @var \HTML_QuickForm $mform */
        $mform = $prop->getValue($form);
        return $mform;
    }
}
