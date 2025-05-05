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
 * Form to create rules.
 *
 * @package   local_taskflow
 * @copyright 2025
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\form\rules;

use core_form\dynamic_form;
use local_multistepform\manager;

/**
 * Demo step 1 form.
 */
class rule extends dynamic_form {
    /**
     * Definition.
     *
     * @return void
     *
     */
    protected function definition(): void {
        $mform = $this->_form;
        $formdata = $this->_ajaxformdata ?? $this->_customdata ?? [];
        manager::definition($mform, $formdata);

        // Enabled.
        $mform->addElement('advcheckbox', 'enabled', get_string('enabled', 'local_taskflow'));
        $mform->setDefault('enabled', 1);

        // Name.
        $mform->addElement('text', 'name', get_string('name'));
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        // Description.
        $mform->addElement('textarea', 'description', get_string('description'), 'wrap="virtual" rows="5" cols="50"');
        $mform->setType('description', PARAM_TEXT);

        // Type.
        $mform->addElement('select', 'ruletype', get_string('type', 'local_taskflow'), [
            'unit_rule' => get_string('unitrule', 'local_taskflow'),
        ]);
        $mform->setDefault('ruletype', 'unit_rule');
    }

    /**
     * Here, we will render the form from the chosen rule type.
     *
     * @return void
     *
     */
    public function definition_after_data(): void {
        $mform = $this->_form;
        $data = $this->get_data() ?? $this->_ajaxformdata ?? $this->_customdata ?? [];
        $data = (object)$data;
        // Set default values for 5the form.
        $classname = !empty($formdata['ruletype'])
            ? "local_taskflow\\local\\rules\\types\\" . $data['ruletype'] : "local_taskflow\\local\\rules\\types\\unit_rule";
        $classname::definition_after_data($mform, $data);
    }

    /**
     * Process the form submission.
     *
     * @return void
     *
     */
    public function process_dynamic_submission(): void {
        $data = $this->get_data();
        $mform = $this->_form;
        manager::process_dynamic_submission($data, $mform);

        // You should not add anything here.
        // Do the saving of your data in the persist function of the manager class.
    }

    /**
     * Set data for the form.
     *
     * @return void
     *
     */
    public function set_data_for_dynamic_submission(): void {
        // This is needed so data is set correctly.
        $data = $this->_ajaxformdata ?? $this->_customdata ?? [];

        // You can add more data to be set here.
        if ($data) {
            $data['ruletype'] = 'unit_rule'; // Default rule type.
            $this->set_data($data);
        }
    }

    /**
     * Get the URL for the page.
     *
     * @return \moodle_url
     *
     */
    protected function get_page_url(): \moodle_url {
        return new \moodle_url('/local/taskflow/editrules.php');
    }

    /**
     * Get the URL for the page.
     *
     * @return \moodle_url
     *
     */
    public function get_page_url_for_dynamic_submission(): \moodle_url {
        return $this->get_page_url();
    }

    /**
     * Get the context for the page.
     *
     * @return \context
     *
     */
    protected function get_context_for_dynamic_submission(): \context {
        return \context_system::instance();
    }

    /**
     * Check access for the page.
     *
     * @return void
     *
     */
    protected function check_access_for_dynamic_submission(): void {
        require_login();
    }
}
