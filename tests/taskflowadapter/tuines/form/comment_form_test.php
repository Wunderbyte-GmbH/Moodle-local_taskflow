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
use context_system;
use taskflowadapter_tuines\form\comment_form;

/**
 * Test unit class of local_taskflow.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class comment_form_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
        global $PAGE;
        $PAGE->set_url('/'); // Keep renderer happy in dynamic form.
        $PAGE->set_context(context_system::instance());
    }

    /**
     * Test getting all members of a unit.
     * @covers \taskflowadapter_tuines\form\comment_form
     */
    public function test_definition_contains_expected_elements(): void {
        $form = new comment_form(
            null,
            null,
            'post',
            '',
            [],
            true,
            []
        );
        $mform = $this->get_mform($form);

        // Textarea.
        $this->assertNotNull($mform->getElement('comment'));
        $hiddenlabels = [
            'id', 'userid', 'overduecounter', 'prolongedcounter',
            'status', 'change_reason', 'duedate', 'keepchanges',
        ];
        foreach ($hiddenlabels as $hidden) {
            $this->assertNotNull($mform->getElement($hidden), "Missing hidden field: {$hidden}");
        }

        // Static commenthistory and submit button.
        $this->assertNotNull($mform->getElement('commenthistory'));
        $this->assertNotNull($mform->getElement('submitcomment'));
    }

    /**
     * Test getting all members of a unit.
     * @covers \taskflowadapter_tuines\form\comment_form
     */
    public function test_getters_return_expected_values(): void {
        $form = new comment_form(null, null, 'post', '', [], true, []);
        $this->assertSame(
            '/local/taskflow/editassignment.php',
            $form->get_page_url_for_dynamic_submission()->out_as_local_url(false)
        );
        $ref = new \ReflectionClass($form);
        $method = $ref->getMethod('get_context_for_dynamic_submission');
        $method->setAccessible(true);
        $context = $method->invoke($form);

        $this->assertEquals(context_system::instance(), $context);

        $this->assertSame(
            $form->get_page_url_for_dynamic_submission()->out_as_local_url(false),
            $form->get_page_url_for_dynamic_submission()->out_as_local_url(false)
        );
    }

    /**
     * Helper to access the protected _form (HTML_QuickForm) instance.
     * @param comment_form $form
     */
    private function get_mform(comment_form $form): \HTML_QuickForm {
        $ref = new \ReflectionClass($form);
        $prop = $ref->getProperty('_form');
        $prop->setAccessible(true);
        /** @var \HTML_QuickForm $mform */
        $mform = $prop->getValue($form);
        return $mform;
    }
}
