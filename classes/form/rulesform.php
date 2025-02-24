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
use context_module;
use local_taskflow\singleton_service;

defined('MOODLE_INTERNAL') || die();

global $CFG;

use context;
use context_system;
use core_form\dynamic_form;
use local_taskflow\taskflow_rules\rules_info;
use moodle_url;

/**
 * Dynamic rules form.
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @package local_taskflow
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rulesform extends dynamic_form {

    /**
     * {@inheritdoc}
     * @see moodleform::definition()
     */
    public function definition() {

        $mform = $this->_form;

        $customdata = $this->_customdata;
        $ajaxformdata = $this->_ajaxformdata;

        // If we open an existing rule, we need to save the id right away.
        if (!empty($ajaxformdata['id'])) {
            $mform->addElement('hidden', 'id', $ajaxformdata['id']);
            $this->prepare_ajaxformdata($ajaxformdata);
        } else if (!empty($ajaxformdata['btn_taskflowruletemplates'])) {
            $this->prepare_ajaxformdata($ajaxformdata);
        }

        $repeateloptions = [];

        rules_info::add_rules_to_mform($mform, $repeateloptions, $ajaxformdata);

        // As this form is called normally from a modal, we don't need the action buttons.
        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /* $this->add_action_buttons(); // Use $this, not $mform. */
    }

    /**
     * Process data for dynamic submission
     * @return object $data
     */
    public function process_dynamic_submission() {
        $data = parent::get_data();

        rules_info::save_taskflow_rule($data);

        return $data;
    }

    /**
     * Set data for dynamic submission.
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {
        if (!empty($this->_ajaxformdata['btn_taskflowruletemplates'])) {
            $tempdata = (object)[
                'id' => $this->_ajaxformdata['taskflowruletemplate'] ?? 0,
                'contextid' => $this->_ajaxformdata['contextid'] ?? 0,
                'btn_taskflowruletemplates' => 1,
                'taskflowruletemplate' => $this->_ajaxformdata['taskflowruletemplate'] ?? 0,
            ];
            $data = rules_info::set_data_for_form($tempdata);
            $data->id = $this->_ajaxformdata['id'];
            $data->contextid = $this->_ajaxformdata['contextid'];
        } else if (!empty($this->_ajaxformdata['id'])) {
            $data = (object)$this->_ajaxformdata;
            $data = rules_info::set_data_for_form($data);
        } else {
            $data = (object)$this->_ajaxformdata;
        }

        $this->set_data($data);
    }

    /**
     * Form validation.
     *
     * @param array $data
     * @param array $files
     * @return array
     *
     */
    public function validation($data, $files) {
        $errors = [];

        if (empty($data['rule_name'])) {
            $errors['rule_name'] = get_string('error:entervalue', 'local_taskflow');
        }

        switch ($data['taskflowruletype']) {
            case '0':
                $errors['taskflowruletype'] = get_string('error:choosevalue', 'local_taskflow');
                break;
            case 'rule_daysbefore':
                if ($data['rule_daysbefore_datefield'] == '0') {
                    $errors['rule_daysbefore_datefield'] = get_string('error:choosevalue', 'local_taskflow');
                }
                break;
            case 'rule_react_on_event':
                if ($data['rule_react_on_event_event'] == '0') {
                    $errors['rule_react_on_event_event'] = get_string('error:choosevalue', 'local_taskflow');
                }
                break;
        }

        switch ($data['taskflowruleconditiontype']) {
            case 'enter_userprofilefield':
                if ($data['condition_enter_userprofilefield_cpfield'] == '0') {
                    $errors['condition_enter_userprofilefield_cpfield'] = get_string('error:choosevalue', 'local_taskflow');
                }
                if (empty($data['condition_enter_userprofilefield_textfield'])) {
                    $errors['condition_enter_userprofilefield_textfield'] = get_string('error:entervalue', 'local_taskflow');
                }
                break;
            case 'match_userprofilefield':
                if ($data['condition_match_userprofilefield_cpfield'] == '0') {
                    $errors['condition_match_userprofilefield_cpfield'] = get_string('error:choosevalue', 'local_taskflow');
                }
                if ($data['condition_match_userprofilefield_optionfield'] == '0') {
                    $errors['condition_match_userprofilefield_optionfield'] = get_string('error:choosevalue', 'local_taskflow');
                }
                break;
            case 'select_student_in_bo':
                if ($data['condition_select_student_in_bo_borole'] == '-1') {
                    $errors['condition_select_student_in_bo_borole'] = get_string('error:choosevalue', 'local_taskflow');
                }
                break;
            case 'select_teacher_in_bo':
                // Nothing to check here.
                break;
            case 'select_user_from_event':
                if ($data['condition_select_user_from_event_type'] == '0') {
                    $errors['condition_select_user_from_event_type'] = get_string('error:choosevalue', 'local_taskflow');
                }
                break;
            case 'select_users':
                if (empty($data['condition_select_users_userids'])) {
                    $errors['condition_select_users_userids'] = get_string('error:choosevalue', 'local_taskflow');
                }
                break;
        }

        switch ($data['taskflowruleactiontype']) {
            case 'send_mail':
                if (empty($data['action_send_mail_subject'])) {
                    $errors['action_send_mail_subject'] = get_string('error:entervalue', 'local_taskflow');
                }
                if (empty(strip_tags($data["action_send_mail_template"]["text"]))) {
                    $errors['action_send_mail_template'] = get_string('error:entervalue', 'local_taskflow');
                }
                break;
            case 'send_copy_of_mail':
                $allowedeventsformailcopy = [
                    '\local_taskflow\event\custom_message_sent',
                    '\local_taskflow\event\custom_bulk_message_sent',
                ];
                if (!isset($data["rule_react_on_event_event"]) ||
                    !in_array($data["rule_react_on_event_event"], $allowedeventsformailcopy)) {
                    $errors['action_send_copy_of_mail_subject_prefix'] =
                        get_string('error:ruleactionsendcopynotpossible', 'local_taskflow');
                    $errors['action_send_copy_of_mail_message_prefix'] =
                        get_string('error:ruleactionsendcopynotpossible', 'local_taskflow');
                }
                break;
        }
        // Check if {#placeholder} is closed with a {/placeholder}.
        if (isset($data['action_send_mail_template']['text'])) {
            $text = $data['action_send_mail_template']['text'];
            preg_match_all('/\{#(\w+)\}/', $text, $matches);

            foreach ($matches[1] as $word) {
                $endtag = '{/' . $word . '}';
                if (strpos($text, $endtag) == false) {
                    $errors['action_send_mail_template'] = get_string('error:noendtagfound', 'local_taskflow', $word);
                }
            }
        }

        return $errors;
    }


    /**
     * Get page URL for dynamic submission.
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        return new moodle_url('/local/taskflow/edit_rules.php');
    }

    /**
     * Get context for dynamic submission.
     * @return context
     */
    protected function get_context_for_dynamic_submission(): context {
        return context_system::instance();
    }

    /**
     * Check access for dynamic submission.
     * @return void
     */
    protected function check_access_for_dynamic_submission(): void {

        $customdata = $this->_customdata;
        $ajaxformdata = $this->_ajaxformdata;

        $contextid = $ajaxformdata['contextid'] ?? $customdata['contextid'];

        $context = context::instance_by_id($contextid);

        require_capability('mod/taskflow:edittaskflowrules', $context);
    }

    /**
     * Prepare the ajax form data with all the information...
     * ... we need no have to load the form with the right handlers.
     *
     * @param array $ajaxformdata
     * @return void
     */
    private function prepare_ajaxformdata(array &$ajaxformdata) {

        global $DB;

        $id = $ajaxformdata['id'];
        if (!empty($ajaxformdata['btn_taskflowruletemplates'])) {
            $id = $ajaxformdata['taskflowruletemplate'];

            $ajaxformdata = [
              'id' => $id,
              'contextid' => $ajaxformdata['contextid'],
              'btn_taskflowruletemplates' => 1,
              'taskflowruletemplate' => $id,
            ];
        }
        // If we have an ID, we retrieve the right rule from DB.
        $record = $DB->get_record('taskflow_rules', ['id' => $id]);

        $jsonboject = json_decode($record->rulejson);

        if (empty($ajaxformdata['taskflowruletype'])) {
            $ajaxformdata['taskflowruletype'] = $jsonboject->rulename;
        }
        if (empty($ajaxformdata['taskflowruleconditiontype'])) {
            $ajaxformdata['taskflowruleconditiontype'] = $jsonboject->conditionname;
        }
        if (empty($ajaxformdata['taskflowruleactiontype'])) {
            $ajaxformdata['taskflowruleactiontype'] = $jsonboject->actionname;
        }
        if (empty($ajaxformdata['isactive'])) {
            $ajaxformdata['isactive'] = $record->isactive;
        }
    }

    /**
     * Definition after data.
     * @return void
     * @throws coding_exception
     */
    public function definition_after_data() {

        $mform = $this->_form;
        $formdata = $this->_customdata ?? $this->_ajaxformdata;

        if (!empty($formdata->id)) {
            return;
        }

        $values = $mform->_defaultValues;
        $formdata = $values;

        // If we have applied the change template value, we override all the values we have submitted.
        if (!empty($formdata['btn_taskflowruletemplates'])) {
            foreach ($values as $k => $v) {

                if ($mform->elementExists($k) && $v !== null) {

                    if ($mform->elementExists($k) && $k != 'rule_name') {
                        $element = $mform->getElement($k);

                        if ($k == 'useastemplate') {
                            $element->setValue(0);
                        } else {
                            $element->setValue($v);
                        }
                    }
                }
            }
        }
    }
}
