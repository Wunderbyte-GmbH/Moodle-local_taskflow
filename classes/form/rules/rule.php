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

use core\output\html_writer;
use local_taskflow\form\form_base;
use local_taskflow\form\rules\types\unit_rule;
use local_taskflow\local\units\organisational_units_factory;

/**
 * Demo step 1 form.
 */
class rule extends form_base {

    /** @var array The instances of the class. */
    private array $madatoryfields = ['name', 'targettype'];

    /**
     * Definition.
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;
        $icon = html_writer::tag('i', '', ['class' => 'fas fa-exclamation-circle']);
        $this->define_manager();

        // Enabled.
        $mform->addElement(
            'advcheckbox',
            'enabled',
            get_string('enabled', 'local_taskflow'),
            get_string('checktoactivate', 'local_taskflow')
        );
        $mform->setDefault('enabled', 1);

        $mform->addElement(
            'advcheckbox',
            'recursive',
            get_string('recursive', 'local_taskflow'),
            get_string('checktoactivate', 'local_taskflow')
        );
        $mform->setDefault('recursive', 1);
        $mform->addElement('html', '<hr>');

        // Name.
        $label = get_string('name') . ' ' . $icon;
        $mform->addElement('text', 'name', $label);
        $mform->setType('name', PARAM_TEXT);

        // Description.
        $mform->addElement('textarea', 'description', get_string('description'), 'wrap="virtual" rows="5" cols="50"');
        $mform->setType('description', PARAM_TEXT);

        // Rule Target Type.
        $mform->addElement(
            'select',
            'targettype',
            get_string('type', 'local_taskflow') . ' ' . $icon,
            [
                '' => get_string('choosetype', 'local_taskflow'),
                'unit_target' => get_string('unittarget', 'local_taskflow'),
                'user_target' => get_string('usertarget', 'local_taskflow'),
            ]
        );
        $mform->setDefault('targettype', '');

        // User ID field with AJAX autocomplete.
        $mform->addElement(
            'autocomplete',
            'userid',
            get_string('user', 'core') . ' ' . $icon,
            $this->load_choosen_user(),
            [
                'ajax' => 'core_user/form_user_selector',
                'noselectionstring' => get_string('chooseuser', 'local_taskflow'),
                'multiple' => false,
            ]
        );
        $mform->setType('userid', PARAM_INT);
        $mform->hideIf('userid', 'targettype', 'neq', 'user_target');
        $mform->disabledIf('userid', 'targettype', 'neq', 'user_target');

        // Units selection.
        $unitsinstance = organisational_units_factory::instance();
        $units = $unitsinstance->get_units();
        $units = ['' => get_string('choosecohort', 'local_taskflow')] + $units;

        $mform->addElement(
            'autocomplete',
            'unitid',
            get_string('cohort', 'cohort') . ' ' . $icon,
            $units,
            [
                'noselectionstring' => get_string('choosecohort', 'local_taskflow'),
                'placeholder' => get_string('choosecohort', 'local_taskflow'),
                'multiple' => false,
            ],
        );
        $mform->setDefault('unitid', []);

        $mform->setType('unitid', PARAM_INT);
        $mform->hideIf('unitid', 'targettype', 'neq', 'unit_target');
        $mform->disabledIf('unitid', 'targettype', 'neq', 'unit_target');

        $dateoptions = [
            'duration' => get_string('duration', 'local_taskflow'),
            'fixeddate' => get_string('fixeddate', 'local_taskflow'),
        ];
        $mform->addElement(
            'select',
            'duedatetype',
            get_string('duedatetype', 'local_taskflow'),
            $dateoptions
        );
        $mform->setDefault('duedatetype', 'duration');

        $mform->addElement('date_time_selector', 'fixeddate', get_string('fixeddate', 'local_taskflow'));
        $mform->hideIf('fixeddate', 'duedatetype', 'neq', 'fixeddate');
        $mform->setDefault('fixeddate', strtotime('+ 4 weeks', time()));
        $mform->addElement('duration', 'duration', get_string('duration', 'local_taskflow'));
        $mform->setDefault('duration', '2419200');
        $mform->hideIf('duration', 'duedatetype', 'neq', 'duration');

        $mform->addElement('duration', 'extensionperiod', get_string('extensionperiod', 'local_taskflow'));
        $mform->setDefault('extensionperiod', '2419200');

        $mform->addElement('advcheckbox', 'cyclicvalidation', get_string('cyclicvalidation', 'local_taskflow'));
        $mform->setDefault('cyclicvalidation', 0);

        $mform->addElement('duration', 'cyclicduration', get_string('cyclicduration', 'local_taskflow'));
        $mform->setDefault('cyclicduration', '31536000');
        $mform->hideIf('cyclicduration', 'cyclicvalidation', 'notchecked');
    }

    /**
     * Set data for the form.
     * @return array
     */
    private function load_choosen_user(): array {
        global $DB, $CFG;
        $formdata = $this->_ajaxformdata ?? $this->_customdata ?? [];
        $useroptions = [];
        if (!empty($formdata['userid'])) {
            $user = $DB->get_record(
                'user',
                ['id' => $formdata['userid']],
                '*',
                IGNORE_MISSING
            );
            if ($user) {
                require_once($CFG->dirroot . '/user/lib.php');
                $useroptions[$user->id] = fullname($user) . ' (' . $user->email . ')';
            }
        }
        return $useroptions;
    }

    /**
     * Set data for the form.
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files): array {
        $errors = [];
        foreach ($this->madatoryfields as $madatoryfield) {
            if (
                !isset($data[$madatoryfield]) ||
                empty($data[$madatoryfield])
            ) {
                $errors[$madatoryfield] = get_string('errormissingvalue', 'local_taskflow');
            }
        }
        if (
            isset($data['unitid']) ||
            $data['targettype'] == 'unit_target'
        ) {
            if (empty($data['unitid'])) {
                $errors['unitid'] = get_string('errormissingvalue', 'local_taskflow');
            }
        } else if (
            empty($data['userid'])
        ) {
            $errors['userid'] = get_string('errormissingvalue', 'local_taskflow');
        }
        return $errors;
    }


    /**
     * Set data for the form.
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {
        $data = $this->_ajaxformdata ?? $this->_customdata ?? [];
        if (!empty($data)) {
            if (isset($data['userid']) && $data['userid'] > 0) {
                $data['targettype'] = 'user_target';
                unset($data['unitid']);
            } else if (isset($data['unitid']) && $data['unitid'] > 0) {
                $data['targettype'] = 'unit_target';
            } else {
                unset($data['targettype'], $data['unitid']);
            }
            $this->set_data($data);
        }
    }

    /**
     * Each step can provide a specific way how to extract and return the data.
     * @param array $steps
     * @return array
     */
    public function get_data_to_persist(array $steps): array {
        $data = unit_rule::get_data($steps);
        return $data;
    }

    /**
     * With this, we transform the saved data to the right format.
     * @param array $step
     * @param mixed $object
     * @return array
     */
    public static function load_data_for_form($step, $object): array {
        foreach ($object as $key => $value) {
            $step[$key] = $value;
        }
        return $step;
    }
}
