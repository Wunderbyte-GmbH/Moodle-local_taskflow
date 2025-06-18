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
use core_competency\user_evidence;

/**
 * Upload userevidance
 */
class userevidence extends dynamic_form {
    /**
     * Definition.
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('hidden', 'userid');
        $mform->setType('userid', PARAM_INT);
        $mform->setConstant('userid', $this->_ajaxformdata['userid']);

        // Name.
        $mform->addElement('text', 'name', get_string('userevidencename', 'tool_lp'), 'maxlength="100"');
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 100), 'maxlength', 100, 'client');
        // Description.
        $mform->addElement('editor', 'description', get_string('userevidencedescription', 'tool_lp'), ['rows' => 10]);
        $mform->setType('description', PARAM_CLEANHTML);

        $mform->addElement('url', 'url', get_string('userevidenceurl', 'tool_lp'), ['size' => '60'], ['usefilepicker' => false]);
        $mform->setType('url', PARAM_RAW_TRIMMED);      // Can not use PARAM_URL, it silently converts bad URLs to ''.
        $mform->addHelpButton('url', 'userevidenceurl', 'tool_lp');

        $mform->addElement(
            'filemanager',
            'files',
            get_string('userevidencefiles', 'tool_lp'),
            [],
            $this->_customdata['fileareaoptions']
        );
        // Disable short forms.
        $mform->setDisableShortforms();
    }

    /**
     * Process the form submission.
     * @return stdClass
     */
    public function process_dynamic_submission(): stdClass {
        $data = $this->get_data();
        $draftitemid = $data->files;
        unset($data->files);
        $description = $data->description['text'] ?? '';
        $descriptionformat = $data->description['format'] ?? FORMAT_HTML;
        unset($data->description);
        $data->description = $description;
        $data->descriptionformat = $descriptionformat;
        \core_competency\api::create_user_evidence($data, $draftitemid);
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
        global $USER;
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
