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

namespace local_taskflow\form;

use context_system;
use core_form\dynamic_form;
use local_taskflow\local\external_adapter\external_api_repository;
use stdClass;

/**
 * Upload user
 */
class uploaduser extends dynamic_form {
    /**
     * Definition.
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('textarea', 'userjson', get_string('jsoninput', 'local_taskflow'), 'wrap="virtual" rows="20" cols="80"');
        $mform->addRule('userjson', null, 'required', null, 'client');
        $mform->setType('userjson', PARAM_RAW); // Raw input, will validate as JSON later.
    }

    /**
     * Process the form submission.
     * @return stdClass
     */
    public function process_dynamic_submission(): stdClass {

        $data = $this->get_data();
        if (!$data || empty($data->userjson)) {
            throw new \moodle_exception('invaliddata', 'error');
        }

        $decoded = json_decode($data->userjson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \moodle_exception('invalidjson', 'local_taskflow', '', json_last_error_msg());
        }

        $apidatamanager = external_api_repository::create($data->userjson);
        $apidatamanager->process_incoming_data();
        return $data;
    }

    /**
     * Validate form fields before submission.
     *
     * @param array $data
     * @param array $files
     * @return array of validation errors (keyed by field name)
     */
    public function validation($data, $files): array {
        $errors = [];

        if (empty($data['userjson'])) {
            $errors['userjson'] = get_string('required');
            return $errors;
        }

        $decoded = json_decode($data['userjson'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $errors['userjson'] = get_string('invalidjson', 'local_taskflow', json_last_error_msg());
        } else if (!is_array($decoded)) {
            $errors['userjson'] = get_string('invalidjsonstructure', 'local_taskflow');
        }

        return $errors;
    }

    /**
     * Set data for the form.
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {
    }

    /**
     * Get the URL for the page.
     *
     * @return \moodle_url
     *
     */
    protected function get_page_url(): \moodle_url {
        return new \moodle_url('/local/taskflow/view.php');
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
        return context_system::instance();
    }

    /**
     * Check user has permission to submit the form.
     */
    protected function check_access_for_dynamic_submission(): void {
        require_capability('moodle/site:config', context_system::instance());
    }
}
