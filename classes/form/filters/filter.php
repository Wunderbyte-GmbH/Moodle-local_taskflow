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

namespace local_taskflow\form\filters;

use core_form\dynamic_form;
use local_multistepform\manager;

/**
 * Demo step 1 form.
 */
class filter extends dynamic_form {
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

        $mform->addElement('select', 'filtertype', get_string('filtertype', 'local_taskflow'), [
            'user_profile_field' => get_string('filteruserprofilefield', 'local_taskflow'),
        ]);
        $mform->setDefault('filtertype', 'user_profile_field');

        $mform = $this->_form;
        $data = $this->get_data() ?? $data = $this->_ajaxformdata ?? $this->_customdata ?? [];
        $data = (object)$data;
        // Set default values for the form.
        if ($data) {
            $classname = !empty($data->filtertype)
                ? "local_taskflow\\local\\filters\\types\\" . $data->filtertype
                : "local_taskflow\\local\\filters\\types\\user_profile_field";
            $classname::definition($this, $mform, $data);
        }
    }

    /**
     * Here, we will render the form from the chosen rule type.
     *
     * @return void
     *
     */
    public function definition_after_data(): void {
    }

    /**
     * Process the form submission.
     * @return void
     */
    public function process_dynamic_submission(): void {
        $data = $this->get_data();
        $mform = $this->_form;
        manager::process_dynamic_submission($data, $mform);
    }

    /**
     * Set data for the form.
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {
        // This is needed so data is set correctly.
        $data = $this->_ajaxformdata ?? $this->_customdata ?? [];

        // You can add more data to be set here.
        if ($data) {
            $data['filtertype'] = 'unit_rule'; // Default rule type.
            $this->set_data($data);
        }
    }

    /**
     * Get the URL for the page.
     * @return \moodle_url
     */
    protected function get_page_url(): \moodle_url {
        return new \moodle_url('/local/taskflow/editrules.php');
    }

    /**
     * Get the URL for the page.
     * @return \moodle_url
     */
    public function get_page_url_for_dynamic_submission(): \moodle_url {
        return $this->get_page_url();
    }

    /**
     * Get the context for the page.
     * @return \context
     */
    protected function get_context_for_dynamic_submission(): \context {
        return \context_system::instance();
    }

    /**
     * Check access for the page.
     * @return void
     */
    protected function check_access_for_dynamic_submission(): void {
        require_login();
    }
}
