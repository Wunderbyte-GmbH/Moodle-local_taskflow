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

namespace local_taskflow\form;

use context_system;
use core_form\dynamic_form;
use local_taskflow\local\personnotes\person_notes_service;
use local_taskflow\taskflow_stringmanager;
use moodle_url;
use stdClass;

/**
 * Modal form on the person page: add a note about the person.
 *
 * @package local_taskflow
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class add_person_note extends dynamic_form {
    /**
     * Form definition.
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;
        $userid = (int)($this->_ajaxformdata['userid'] ?? 0);

        $mform->addElement('hidden', 'userid');
        $mform->setType('userid', PARAM_INT);
        $mform->setConstant('userid', $userid);

        $mform->addElement(
            'textarea',
            'note',
            taskflow_stringmanager::get_string('personnote'),
            'wrap="virtual" rows="6" cols="60"'
        );
        $mform->setType('note', PARAM_TEXT);
        $mform->addRule('note', get_string('required'), 'required', null, 'client');
    }

    /**
     * Validation: an empty note is not stored.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files): array {
        $errors = [];
        if (trim((string)($data['note'] ?? '')) === '') {
            $errors['note'] = get_string('required');
        }
        return $errors;
    }

    /**
     * Stores the note.
     * @return stdClass
     */
    public function process_dynamic_submission(): stdClass {
        global $USER;
        $data = $this->get_data();
        $id = (new person_notes_service())->add((int)$data->userid, (string)$data->note, (int)$USER->id);
        return (object)['id' => $id];
    }

    /**
     * Nothing to preload.
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {
        $this->set_data(['userid' => (int)($this->_ajaxformdata['userid'] ?? 0)]);
    }

    /**
     * Page URL.
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        return new moodle_url('/local/taskflow/person.php', ['id' => (int)($this->_ajaxformdata['userid'] ?? 0)]);
    }

    /**
     * Context.
     * @return \context
     */
    protected function get_context_for_dynamic_submission(): \context {
        return context_system::instance();
    }

    /**
     * Access check: capability plus team scope.
     * @return void
     */
    protected function check_access_for_dynamic_submission(): void {
        global $USER;
        require_login();
        $userid = (int)($this->_ajaxformdata['userid'] ?? 0);
        if (!person_notes_service::can_create((int)$USER->id, $userid)) {
            throw new \required_capability_exception(
                context_system::instance(),
                'local/taskflow:createpersonnotes',
                'nopermissions',
                ''
            );
        }
    }
}
