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

namespace local_taskflow\form\messages;

use local_taskflow\form\form_base;
use stdClass;

/**
 * Demo step 1 form.
 */
class messages extends form_base {
    /**
     * Definition.
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;
        $formdata = $this->_ajaxformdata ?? $this->_customdata ?? [];
        $this->define_manager();

        $messagepackagesinstance = new form_packages();
        $messagesinstance = new form_messages();

        $mform->addElement(
            'autocomplete',
            'packageid',
            get_string('message_packages', 'local_taskflow'),
            $messagepackagesinstance->get_form_data(),
            [
                'noselectionstring' => get_string('choosepackage', 'local_taskflow'),
                'multiple' => false,
                 'id' => 'id_message_package',
            ],
            'id_message_package'
        );

        $mform->addElement('hidden', 'packageid');
        $mform->setType('packageid', PARAM_INT);

        $mform->addElement('html', '<hr>');
        $mform->addElement(
            'autocomplete',
            'messageids',
            get_string('messages', 'local_taskflow'),
            $messagesinstance->get_form_data(),
            [
                'noselectionstring' => get_string('choosemessages', 'local_taskflow'),
                'multiple' => true,
            ],
        );
    }

    /**
     * Set data for the form.
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {
        $data = $this->_ajaxformdata ?? $this->_customdata ?? [];
        if ($data) {
            if (
                isset($data['packageid']) &&
                is_number($data['packageid'])
            ) {
                $messagesinstance = new form_messages();
                $data['messageids'] = $messagesinstance->get_messages_from_package($data['packageid']);
            } else {
                foreach ($data['messages'] as $message) {
                    $data['messageids'][] = $message->messageid;
                }
            }
            $this->set_data($data);
        }
    }

    /**
     * Depending on the chosen class type, we pass on the extraction.
     * @param array $step
     * @return array
     *
     */
    /**
     * Depending on the chosen class type, we pass on the extraction.
     * @param array $step
     * @param array $rulejson
     */
    public function set_data_to_persist(array &$step, &$rulejson) {
        $messages = [];
        foreach ($step['messageids'] as &$messageid) {
            $messages[] = [
                'messageid' => $messageid,
            ];
        }
        if (!isset($rulejson['actions'])) {
            $rulejson['actions'] = [];
        }
        $rulejson['actions'][0]['messages'] = $messages;
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
            foreach ($action->messages as $message) {
                $step['messages'][] = $message;
            }
        }
        return $step;
    }
}
