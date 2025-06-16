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
use MoodleQuickForm;
use core_form\dynamic_form;
use local_multistepform\manager;

/**
 * Demo step 1 form.
 */
class form_base extends dynamic_form {
    /**
     * Definition.
     * @return void
     */
    protected function definition(): void {
    }

    /**
     * Definition.
     * @return void
     */
    protected function define_manager(): void {
        $mform = $this->_form;
        $formdata = $this->_ajaxformdata ?? $this->_customdata ?? [];

        $uniqueid = $formdata['uniqueid'] ?? 0;
        $recordid = $formdata['recordid'] ?? 0;

        $manager = manager::return_class_by_uniqueid($uniqueid, $recordid);
        $manager->definition($mform, $formdata);
    }

    /**
     * Process the form submission.
     * @return void
     */
    public function process_dynamic_submission(): void {
        $data = $this->get_data();
        $mform = $this->_form;

        $uniqueid = $data->uniqueid ?? 0;
        $recordid = $data->recordid ?? 0;

        $manager = manager::return_class_by_uniqueid($uniqueid, $recordid);
        $manager->process_dynamic_submission($data, $mform);

        // You should not add anything here.
        // Do the saving of your data in the persist function of the manager class.
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
        return context_system::instance();
    }

    /**
     * Check access for the page.
     * @return void
     */
    protected function check_access_for_dynamic_submission(): void {
        require_login();
    }

    /**
     * Check access for the page.
     * @param array $repeatarray
     * @param MoodleQuickForm $mform
     * @return void
     */
    protected function add_remove_element_button(&$repeatarray, $mform): void {
        $repeatarray[] = $mform->createElement('html', '<div class="d-flex justify-content-end align-items-start mb-1">');
        $repeatarray[] = $mform->createElement(
            'submit',
            'deleteelement',
            get_string('deleteelement', 'local_taskflow'),
            [
                'class' => 'btn btn-danger btn-sm',
            ]
        );
        $repeatarray[] = $mform->createElement('html', '</div><hr>');
    }

    /**
     * Check access for the page.
     * @return array
     */
    protected function get_element_ids($elements, $key): array {
        $ids = [];
        foreach ($elements as $element) {
            $name = $element->getName();
            if (
                $name &&
                preg_match('/^' . preg_quote($key, '/') . '\[(\d+)\]$/', $name, $matches)
            ) {
                $ids[] = (int)$matches[1];
            }
        }

        $ids = array_unique($ids);
        sort($ids);

        return $ids;
    }
}
