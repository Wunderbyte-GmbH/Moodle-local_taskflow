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
use moodle_url;
use stdClass;
use context_user;
use core_competency\user_evidence;

/**
 * Delete userevidance
 */
class delete_userevidence extends dynamic_form {
    /**
     * Definition.
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('hidden', 'evidenceid');
        $mform->setType('evidenceid', PARAM_INT);
        $mform->setConstant('evidenceid', $this->_ajaxformdata['evidenceid']);

        $mform->addElement('hidden', 'userid');
        $mform->setType('userid', PARAM_INT);
        $mform->setConstant('userid', $this->_ajaxformdata['userid']);

        // Disable short forms.
        $mform->setDisableShortforms();
    }

    /**
     * Process the form submission.
     * @return stdClass
     */
    public function process_dynamic_submission(): stdClass {
        global $DB;
        $data = $this->get_data();

        $transaction = $DB->start_delegated_transaction();
        try {
            $taskflowacrecord = $DB->get_record('local_taskflow_assignment_competency', [
                'competencyevidenceid' => $data->evidenceid,
                'userid' => $data->userid,
            ], '*', MUST_EXIST);
            $DB->delete_records('local_taskflow_assignment_competency', [
                'id' => $taskflowacrecord->id,
            ]);
            \core_competency\api::delete_user_evidence($data->evidenceid);
            $transaction->allow_commit();
        } catch (\Exception $e) {
            $transaction->rollback($e);
        }
        $data->success = true;

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
     * Check user has permission to submit the form.
     */
    private function can_delete(): bool {
        // TODO: Implement permission check logic.
        global $DB;
        $evidenceid = $this->_ajaxformdata['evidenceid'] ?? 0;
        if (empty($evidenceid)) {
            return false;
        }
        // Check if the evidence exists.
        return $DB->record_exists('local_taskflow_assignment_competency', ['competencyevidenceid' => $evidenceid]);
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
        $this->can_delete();
    }
}
