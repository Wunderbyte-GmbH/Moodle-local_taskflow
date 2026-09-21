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
use local_taskflow\local\rules\unit_rule_assignment_service;
use local_taskflow\output\unitspage;
use local_taskflow\taskflow_stringmanager;
use moodle_url;
use stdClass;

/**
 * Modal form on the organisation page: assign one or more rules to a unit, with or without inheritance.
 *
 * @package local_taskflow
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_rule_to_unit extends dynamic_form {
    /**
     * Form definition.
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;
        $unitid = (int)($this->_ajaxformdata['unitid'] ?? 0);
        $unitnames = unitspage::get_all_unit_names();

        $mform->addElement('hidden', 'unitid');
        $mform->setType('unitid', PARAM_INT);
        $mform->setConstant('unitid', $unitid);

        $mform->addElement(
            'static',
            'unitname',
            taskflow_stringmanager::get_string('unit'),
            $unitnames[$unitid] ?? (string)$unitid
        );

        $options = [];
        foreach (unit_rule_assignment_service::get_assignable_rules() as $rule) {
            $options[(int)$rule->id] = format_string($rule->rulename);
        }
        if (empty($options)) {
            $mform->addElement('static', 'norules', '', taskflow_stringmanager::get_string('assignruletounit_norules'));
            return;
        }

        $mform->addElement(
            'autocomplete',
            'ruleids',
            taskflow_stringmanager::get_string('assignrule_rules'),
            $options,
            [
                'multiple' => true,
                'noselectionstring' => taskflow_stringmanager::get_string('chooserule'),
            ]
        );
        $mform->addRule('ruleids', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('ruleids', 'assignruletounit_rules', 'local_taskflow');

        $mform->addElement(
            'advcheckbox',
            'inheritance',
            taskflow_stringmanager::get_string('inheritance'),
            taskflow_stringmanager::get_string('assignruletounit_inheritance')
        );
        $mform->setDefault('inheritance', 0);
        $mform->addHelpButton('inheritance', 'assignruletounit_inheritance', 'local_taskflow');
    }

    /**
     * Assigns the selected rules.
     * @return stdClass
     */
    public function process_dynamic_submission(): stdClass {
        global $USER;
        $data = $this->get_data();
        $service = new unit_rule_assignment_service();
        $count = 0;
        foreach ((array)($data->ruleids ?? []) as $ruleid) {
            $service->assign((int)$ruleid, (int)$data->unitid, !empty($data->inheritance), (int)$USER->id);
            $count++;
        }
        return (object)['count' => $count];
    }

    /**
     * Nothing to preload.
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {
        $this->set_data(['unitid' => (int)($this->_ajaxformdata['unitid'] ?? 0)]);
    }

    /**
     * Page URL.
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        return new moodle_url('/local/taskflow/units.php', ['id' => (int)($this->_ajaxformdata['unitid'] ?? 0)]);
    }

    /**
     * Context.
     * @return \context
     */
    protected function get_context_for_dynamic_submission(): \context {
        return context_system::instance();
    }

    /**
     * Access check.
     * @return void
     */
    protected function check_access_for_dynamic_submission(): void {
        require_login();
        require_capability('local/taskflow:vieworganisation', context_system::instance());
        require_capability('local/taskflow:createrules', context_system::instance());
    }
}
