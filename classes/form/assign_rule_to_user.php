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
use local_taskflow\local\rules\personal_rule_assignment_service;
use local_taskflow\local\units\organisational_units_factory;
use local_taskflow\output\personpage;
use local_taskflow\taskflow_stringmanager;
use moodle_url;
use stdClass;

/**
 * Modal form on the person page: assign one or more rules / curricula to a user.
 *
 * @package local_taskflow
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_rule_to_user extends dynamic_form {
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

        $options = $this->get_assignable_rules($userid);
        if (empty($options)) {
            $mform->addElement('static', 'norules', '', taskflow_stringmanager::get_string('assignrule_norules'));
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

        $mform->addElement(
            'textarea',
            'annotation',
            taskflow_stringmanager::get_string('assignrule_annotation'),
            'wrap="virtual" rows="3" cols="50"'
        );
        $mform->setType('annotation', PARAM_TEXT);
    }

    /**
     * Active rules that are not yet assigned to the user individually, labelled with their audience.
     *
     * @param int $userid
     * @return array ruleid => label
     */
    private function get_assignable_rules(int $userid): array {
        global $DB;
        $assigned = personal_rule_assignment_service::get_rule_ids_for_user($userid);
        $unitnames = [];
        try {
            $unitnames = organisational_units_factory::instance()->get_units();
        } catch (\Throwable $e) {
            $unitnames = [];
        }
        $options = [];
        foreach ($DB->get_records('local_taskflow_rules', ['isactive' => 1], 'rulename') as $rule) {
            if (in_array((int)$rule->id, $assigned, true)) {
                continue;
            }
            if (!empty($rule->unitid)) {
                $audience = taskflow_stringmanager::get_string(
                    'ruleaudience_unit',
                    $unitnames[$rule->unitid] ?? $rule->unitid
                );
            } else if (!empty($rule->userid)) {
                $audience = taskflow_stringmanager::get_string('ruleaudience_user');
            } else {
                $audience = taskflow_stringmanager::get_string('ruleaudience_curriculum');
            }
            $options[(int)$rule->id] = format_string($rule->rulename) . ' (' . $audience . ')';
        }
        return $options;
    }

    /**
     * Assigns the selected rules.
     * @return stdClass
     */
    public function process_dynamic_submission(): stdClass {
        global $USER;
        $data = $this->get_data();
        $service = new personal_rule_assignment_service();
        $count = 0;
        foreach ((array)($data->ruleids ?? []) as $ruleid) {
            $service->assign((int)$ruleid, (int)$data->userid, (int)$USER->id, (string)($data->annotation ?? ''));
            $count++;
        }
        return (object)['count' => $count];
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
     * Access check.
     * @return void
     */
    protected function check_access_for_dynamic_submission(): void {
        global $USER;
        require_login();
        require_capability('local/taskflow:assignrulestouser', context_system::instance());
        // Capability plus scope: only persons the viewer is in charge of (own team, or everybody as manager).
        $userid = (int)($this->_ajaxformdata['userid'] ?? 0);
        if (!personpage::can_view((int)$USER->id, $userid)) {
            throw new \required_capability_exception(
                context_system::instance(),
                'local/taskflow:assignrulestouser',
                'nopermissions',
                ''
            );
        }
    }
}
