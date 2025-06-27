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
use local_taskflow\local\competencies\assignment_competency;
use local_taskflow\local\history\history;
use moodle_url;
use stdClass;
use context_user;
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

        $mform->addElement('hidden', 'evidenceid');
        $mform->setType('evidenceid', PARAM_INT);
        $mform->setConstant('evidenceid', $this->_ajaxformdata['evidenceid']);

        $mform->addElement('hidden', 'assignmentid');
        $mform->setType('assignmentid', PARAM_INT);
        $mform->setConstant('assignmentid', $this->_ajaxformdata['assignmentid'] ?? 0);

        $mform->addElement('hidden', 'userid');
        $mform->setType('userid', PARAM_INT);
        $mform->setConstant('userid', $this->_ajaxformdata['userid']);

        $mform->addElement('hidden', 'competencyid');
        $mform->setType('competencyid', PARAM_INT);
        $mform->setConstant('competencyid', $this->_ajaxformdata['competencyid']);

        $mform->addElement('hidden', 'statusmode');
        $mform->setType('statusmode', PARAM_INT);
        $mform->setConstant('statusmode', $this->_ajaxformdata['statusmode']);

        $mform->addElement('hidden', 'assingmentcompetencyid');
        $mform->setType('assingmentcompetencyid', PARAM_INT);
        $mform->setConstant('assingmentcompetencyid', $this->_ajaxformdata['assingmentcompetencyid']);

        // Name.
        $mform->addElement('text', 'name', get_string('userevidencename', 'tool_lp'), 'maxlength="100"');
        $mform->setType('name', PARAM_TEXT);
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

        $mform->addElement(
            'select',
            'setstatus',
            get_string('userevidencestatus', 'local_taskflow'),
            [
                'underreview' => get_string('userevidencestatus_underreview', 'local_taskflow'),
                'approved' => get_string('userevidencestatus_approved', 'local_taskflow'),
                'rejected' => get_string('userevidencestatus_rejected', 'local_taskflow'),
            ]
        );

        $mform->hideIf('name', 'statusmode', 'eq', 'setstatus');
        $mform->hideIf('description', 'statusmode', 'eq', 'setstatus');
        $mform->hideIf('url', 'statusmode', 'eq', 'setstatus');
        $mform->hideIf('files', 'statusmode', 'eq', 'setstatus');
        $mform->hideIf('setstatus', 'statusmode', 'eq', 'view');

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
        $competencyid = $data->competencyid;
        $assignemnetid = $data->assignmentid;
        unset($data->assignmentid);
        unset($data->competencyid);
        $draftitemid = $data->files;
        unset($data->files);
        $description = $data->description['text'] ?? '';
        $descriptionformat = $data->description['format'] ?? FORMAT_HTML;
        unset($data->description);
        $data->description = $description;
        $data->descriptionformat = $descriptionformat;
        if (($data->statusmode) == 'setstatus') {
            return $this->process_set_status($data);
        }
        if (empty($data->assingmentcompetencyid)) {
            try {
                $transaction = $DB->start_delegated_transaction();
                $evidence = \core_competency\api::create_user_evidence($data, $draftitemid);
                if (!$evidence instanceof user_evidence) {
                    throw new \moodle_exception('errorcreatinguserevidence', 'tool_lp');
                }
                $assigncompetency = new stdClass();
                $assigncompetency->competencyevidenceid = $evidence->get('id');
                $assigncompetency->assignmentid = $assignemnetid;
                $assigncompetency->userid = $data->userid;
                $assigncompetency->timecreated = time();
                $assigncompetency->timemodified = time();
                $assigncompetency->competencyid = $competencyid;
                $DB->insert_record('local_taskflow_assignment_competency', $assigncompetency, true);
                history::log(
                    $assignemnetid,
                    $data->userid,
                    history::TYPE_COMPETENCY_UPLOAD,
                    [
                        'action' => 'create',
                        'name' => $data->name,
                        'description' => $data->description,
                        'url' => $data->url,
                    ]
                );
                $transaction->allow_commit();
            } catch (\Exception $e) {
                $transaction->rollback($e);
            }
        } else {
            $data->id = $data->evidenceid;
            unset($data->evidenceid);
            $evidence = \core_competency\api::update_user_evidence($data, $draftitemid);
            history::log(
                $assignemnetid,
                $data->userid,
                history::TYPE_COMPETENCY_UPLOAD,
                [
                    'action' => 'create',
                    'name' => $data->name,
                    'description' => $data->description,
                    'url' => $data->url,
                ]
            );
            if (!$evidence instanceof user_evidence) {
                throw new \moodle_exception('errorcreatinguserevidence', 'tool_lp');
            }
        }

        return $data;
    }

    /**
     * Summary of process_set_status
     * @param mixed $data
     * @throws \moodle_exception
     * @return \stdClass
     */
    public function process_set_status($data): stdClass {
        global $DB;
        $assigncompetency = new assignment_competency();
        $assigncompetency->load_from_db($data->assingmentcompetencyid);
        if (!$assigncompetency->id) {
            throw new \moodle_exception('invaliduserevidenceid', 'tool_lp');
        }
        $assigncompetency->set('id', $data->assingmentcompetencyid);
        $assigncompetency->read();
        $assigncompetency->set('status', $data->setstatus);
        $assigncompetency->update();
        if ($assigncompetency->get('status') == 'approved') {
            $assigncompetency->set_competency();
        }
        if ($assigncompetency->get('status') == 'rejected' || $assigncompetency->get('status') == 'underreview') {
            $assigncompetency->delete_competency();
        }
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

        if (!empty($data['evidenceid'])) {
            // If no ID is provided, we create a new assignment.
            $userevidence = \core_competency\api::read_user_evidence($data['evidenceid']);
            if ($userevidence) {
                $data['description'] = [
                    'text' => $userevidence->get('description'),
                    'format' => $userevidence->get('descriptionformat'),
                ];
                $data['name'] = $userevidence->get('name');
                $data['url'] = $userevidence->get('url');
                $data['userid'] = $userevidence->get('userid');

                $itemid = null;
                if ($userevidence) {
                    $itemid = $userevidence->get('id');
                }
                $context = context_user::instance($data['userid']);
                $draftitemid = file_get_submitted_draft_itemid('files');
                file_prepare_draft_area($draftitemid, $context->id, 'core_competency', 'userevidence', $itemid);
                $data['files'] = $draftitemid;
                $assigncompetency = new assignment_competency();
                $assigncompetency->set('id', $data['assingmentcompetencyid']);
                $assigncompetency->read();
                $data['setstatus'] = $assigncompetency->get('status');
            } else {
                // If no assignment data is found, we initialize an empty array.
                $data = (object)[];
            }
        }

        if (
            has_capability('moodle/site:config', context_system::instance())
            && !empty($data['assingmentcompetencyid'])
            && $data['assingmentcompetencyid']
            && $data['statusmode'] == 'setstatus' && !isset($data['setstatus'])
        ) {
            $data['statusmode'] = $data['statusmode'] ?? 'setstatus';
        } else {
            $data['statusmode'] = 'view';
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
        global $USER;
        if (!has_capability('moodle/site:config', context_system::instance()) && $USER->id != $this->_ajaxformdata['userid']) {
            throw new \moodle_exception('nopermissiontodeleteuserevidence', 'tool_lp');
        }
    }
}
