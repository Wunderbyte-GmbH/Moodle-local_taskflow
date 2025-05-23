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

namespace local_taskflow\form\rules;

use context_system;
use core_form\dynamic_form;
use local_multistepform\manager;
use local_taskflow\form\rules\types\unit_rule;
use local_taskflow\local\units\organisational_units_factory;

/**
 * Demo step 1 form.
 */
class rule extends dynamic_form {
    /**
     * Definition.
     *
     * @return void
     *
     */
    protected function definition(): void {
        $mform = $this->_form;
        $formdata = $this->_ajaxformdata ?? $this->_customdata ?? [];

        $uniqueid = $formdata['uniqueid'] ?? 0;
        $recordid = $formdata['recordid'] ?? 0;

        $manager = manager::return_class_by_uniqueid($uniqueid, $recordid);
        $manager->definition($mform, $formdata);

        // Enabled.
        $mform->addElement('advcheckbox', 'enabled', get_string('enabled', 'local_taskflow'));
        $mform->setDefault('enabled', 1);

        // Name.
        $mform->addElement('text', 'name', get_string('name'));
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        // Description.
        $mform->addElement('textarea', 'description', get_string('description'), 'wrap="virtual" rows="5" cols="50"');
        $mform->setType('description', PARAM_TEXT);

        // Rule Target Type.
        $mform->addElement(
            'select',
            'targettype',
            get_string('type', 'local_taskflow'),
            [
                'user_target' => 'Rule for specific user',
                'unit_target' => 'Rule for entire unit',
            ]
        );
        $mform->setDefault('targettype', 'user_target');

        // User ID field with AJAX autocomplete.
        $mform->addElement('autocomplete', 'userid', get_string('user', 'core'), [], [
            'ajax' => 'core_user/form_user_selector',
            'noselectionstring' => get_string('chooseuser', 'local_taskflow'),
            'multiple' => false,
        ]);
        $mform->setType('userid', PARAM_INT);
        $mform->hideIf('userid', 'targettype', 'neq', 'user_target');
        $mform->disabledIf('userid', 'targettype', 'neq', 'user_target');

        // Units selection.
        $unitsinstance = organisational_units_factory::instance();
        $units = $unitsinstance->get_units();
        $mform->addElement(
            'autocomplete',
            'unitid',
            get_string('cohort', 'cohort'),
            $units->get_units(),
            [
                'noselectionstring' => get_string('choosecohort', 'local_taskflow'),
                'multiple' => false,
            ],
        );
        $mform->setType('unitid', PARAM_INT);
        $mform->hideIf('unitid', 'targettype', 'neq', 'unit_target');
        $mform->disabledIf('unitid', 'targettype', 'neq', 'unit_target');
    }

    /**
     * Here, we will render the form from the chosen rule type.
     *
     * @return void
     *
     */
    public function definition_after_data(): void {
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
        $data = $this->_ajaxformdata ?? $this->_customdata ?? [];
        if ($data) {
            $data['targettype'] = 'user_target';
            if (
                isset($data['unitid']) &&
                $data['unitid'] > 0
            ) {
                $data['targettype'] = 'unit_target';
            }
            $this->set_data($data);
        }
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
     *
     * @return \moodle_url
     *
     */
    public function get_page_url_for_dynamic_submission(): \moodle_url {
        return $this->get_page_url();
    }

    /**
     * Get the context for the page.
     *
     * @return \context
     *
     */
    protected function get_context_for_dynamic_submission(): \context {
        return context_system::instance();
    }

    /**
     * Check access for the page.
     *
     * @return void
     *
     */
    protected function check_access_for_dynamic_submission(): void {
        require_login();
    }

    /**
     * Each step can provide a specific way how to extract and return the data.
     * @param array $steps
     * @return array
     *
     */
    public function get_data_to_persist(array $steps): array {
        $data = unit_rule::get_data($steps);
        return $data;
    }

    /**
     * With this, we transform the saved data to the right format.
     *
     * @param array $step
     * @param array|stdClass $object
     *
     * @return array
     *
     */
    public static function load_data_for_form(array $step, $object): array {
        foreach ($object as $key => $value) {
            $step[$key] = $value;
        }
        return $step;
    }
}
