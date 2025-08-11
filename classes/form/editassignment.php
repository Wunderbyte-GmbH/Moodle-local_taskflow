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
use local_taskflow\local\assignments\assignment;
use local_taskflow\local\assignments\status\assignment_status;
use local_taskflow\local\history\history;

/**
 * Demo step 1 form.
 */
class editassignment extends dynamic_form {
    /**
     * Definition.
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;

        // Add this hidden id to the form.
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('hidden', 'userid');
        $mform->setType('userid', PARAM_INT);

        $statusoptions = assignment_status::get_all();
        $statusoptions = array_unique($statusoptions);
        // Status ändern.
        $mform->addElement(
            'select',
            'status',
            get_string('changestatus', 'local_taskflow'),
            $statusoptions
        );
        $mform->setType('status', PARAM_TEXT);
        $mform->addRule('status', null, 'required', null, 'client');

        $changereasonoptions = assignment_status::get_all_changereasons();
        // Reason for change.
        $mform->addElement(
            'select',
            'change_reason',
            get_string('changereason', 'local_taskflow'),
            $changereasonoptions
        );
        $mform->setType('change_reason', PARAM_TEXT);

        // Comment.
        $mform->addElement('textarea', 'comment', get_string('comment', 'local_taskflow'), 'wrap="virtual" rows="3" cols="50"');
        $mform->setType('comment', PARAM_TEXT);

        // Duedate. Set Ruledata extensionperiod as default.
        $data = $this->_customdata ?? $this->_ajaxformdata ?? [];
        if (!empty($data['id'])) {
            $assignment = new assignment($data['id']);
            $ruledata = json_decode($assignment->rulejson);
            if (isset($ruledata->rulejson->rule->extensionperiod)) {
                $extensionperiod = time() + $ruledata->rulejson->rule->extensionperiod;
            } else {
                $extensionperiod = time();
            }
        } else {
            $extensionperiod = time();
        }
        $mform->addElement('date_selector', 'duedate', get_string('duedate', 'local_taskflow'));
        $mform->setDefault('duedate', $extensionperiod);
        // Changes should be preserved on automatic update via import.
        $mform->addElement(
            'advcheckbox',
            'keepchanges',
            '',
            get_string('keepchangesonimport', 'local_taskflow')
        );
        $mform->setDefault('keepchanges', 1);

        $this->add_action_buttons(false);
    }

    /**
     * Process the form submission.
     * @return void
     */
    public function process_dynamic_submission(): void {
        global $USER;
        $data = $this->get_data();
        $mform = $this->_form;

        $assignment = new assignment($data->id);
        $data->useridmodified = $USER->id;
        $assignment->add_or_update_assignment((array)$data, history::TYPE_MANUAL_CHANGE, true);
    }

    /**
     * Set data for the form.
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {
        $data = $this->_customdata ?? $this->_ajaxformdata ?? [];

        if (!empty($data['id'])) {
            // If no ID is provided, we create a new assignment.
            $assignment = new assignment($data['id']);
            $assignmentdata = $assignment->return_class_data();
            if ($assignmentdata) {
                $data = $assignmentdata;
            } else {
                // If no assignment data is found, we initialize an empty array.
                $data = (object)[];
            }
        }
        $this->set_data($data);
    }

    /**
     * Get the URL for the page.
     *
     * @return \moodle_url
     *
     */
    protected function get_page_url(): \moodle_url {
        return new \moodle_url('/local/taskflow/editassignment.php');
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
}
