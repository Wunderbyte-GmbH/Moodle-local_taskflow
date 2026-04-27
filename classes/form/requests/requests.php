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

namespace local_taskflow\form\requests;

use local_taskflow\form\form_base;
use local_taskflow\local\requests\request_receivers\receiver_facade;
use local_taskflow\local\requests\request_types\requests_manager;
use stdClass;

/**
 * Request form page.
 */
class requests extends form_base {
    /**
     * Definition.
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;
        $formdata = $this->_ajaxformdata ?? $this->_customdata ?? [];
        $this->define_manager();

        // Get all active requests.
        $requestmanager = new requests_manager();
        $activerequests = $requestmanager->get_active_request_types();

        // Get all receivers.
        $receivers = receiver_facade::get_request_receivers();
        $receiverdescription['not_allowed'] = get_string('notallowed', 'local_taskflow');
        foreach ($receivers as $key => $receiver) {
            $receiverdescription[$key] = $receiver->get_description();
        }

        // Loop over request, generate receivers.
        foreach ($activerequests as $activerequestkey => $activerequestvalue) {
            $key = 'receiver_' . $activerequestkey;
            $mform->addElement('html', $activerequestvalue);
            $mform->addElement(
                'select',
                $key,
                get_string('requestsgoto', 'local_taskflow'),
                $receiverdescription
            );
            $mform->setDefault($key, 'not_allowed');
            $mform->addElement('html', '<hr>');
        }
    }

    /**
     * Set data for the form.
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {
        $data = $this->_ajaxformdata ?? $this->_customdata ?? [];
        if ($data['requests']) {
            $this->set_data($data['requests']);
        }
    }

    /**
     * Depending on the chosen class type, we pass on the extraction.
     * @param array $step
     * @param array $rulejson
     * @return void
     */
    public function set_data_to_persist(array &$step, &$rulejson): void {
        $requests = [];
        foreach ($step as $key => $receiver) {
            if (str_contains($key, 'receiver_')) {
                $requests[$key] = $receiver;
            }
        }
        $rulejson['actions'][0]['requests'] = $requests;
        return;
    }

    /**
     * With this, we transform the saved data to the right format.
     *
     * @param array $step
     * @param stdClass|array $object
     *
     * @return array
     *
     */
    public static function load_data_for_form(array $step, $object): array {
        $actions = $object->actions;
        foreach ($actions as $action) {
            if (isset($action->requests)) {
                foreach ($action->requests as $key => $request) {
                    $step['requests'][$key] = $request;
                }
            }
        }
        return $step;
    }
}
