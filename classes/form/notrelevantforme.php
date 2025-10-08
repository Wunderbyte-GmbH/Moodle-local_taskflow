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
use local_taskflow\local\assignments\types\standard_assignment;
use local_taskflow\local\competencies\assignment_competency;
use local_taskflow\local\history\history;
use local_taskflow\local\requests;
use moodle_url;
use stdClass;
use context_user;
use core_competency\user_evidence;

/**
 * Upload userevidance
 */
class notrelevantforme extends dynamic_form {
    /**
     * REQUEST_NOTRELEVANT
     *
     * @var int
     */
    public const REQUEST_NOTRELEVANT = 1;
    /**
     * Definition.
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('hidden', 'assignmentid');
        $mform->setType('assignmentid', PARAM_INT);
        $mform->setConstant('assignmentid', $this->_ajaxformdata['assignmentid'] ?? 0);

        $mform->addElement('hidden', 'userid');
        $mform->setType('userid', PARAM_INT);
        $mform->setConstant('userid', $this->_ajaxformdata['userid']);

        // Name.
        $mform->addElement('static', 'notrelevant', '', get_string('askfornotrelevant', 'local_taskflow'));
        $mform->setType('notrelevant', PARAM_TEXT);
        // Description.
    }

    /**
     * Process the form submission.
     * @return stdClass
     */
    public function process_dynamic_submission(): stdClass {
        global $DB, $USER;
        $data = $this->get_data();

        // Get assigment by id.
        $request = requests::create(
            self::REQUEST_NOTRELEVANT,
            $data->userid,
            $data->assignmentid,
            self::REQUEST_NOTRELEVANT,
            $USER->id
        );

        // $assigment = standard_assignment::instance($data->assignmentid);
        // assignment_status_facade::change_status($assigment, 'notrelevant');

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
        global $DB;
        $errors = [];
        $data = [
            'userid' => $data['userid'],
            'assignmentid' => $data['assignmentid'],
            'status' => self::REQUEST_NOTRELEVANT,
        ];
        $record = $DB->get_record('local_taskflow_requests', $data);
        if ($record) {
            $errors['notrelevant'] = get_string('requestnotrelevantalreadyexisiting');
        }

        return $errors;
    }

    /**
     * Set data for the form.
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {
        $data = $this->_customdata ?? $this->_ajaxformdata ?? [];

        $this->set_data($data);
    }

    /**
     * Get the URL for the page.
     *
     * @return \moodle_url
     *
     */
    protected function get_page_url(): \moodle_url {
        return new \moodle_url('/local/taskflow/assignment.php');
    }

    /**
     * Get the URL for the page.
     * @return \moodle_url
     */
    public function get_page_url_for_dynamic_submission(): moodle_url {
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
        global $USER;
        // No check in this case.
    }

    /**
     * Returns the name of this status class.
     *
     * @return string
     *
     */
    public static function get_status_name() {
        return get_string('notrelevantformedisplayname', 'local_taskflow');
    }
}
