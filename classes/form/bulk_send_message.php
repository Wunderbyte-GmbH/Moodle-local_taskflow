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
use local_taskflow\local\messages\messages_factory;
use local_taskflow\table\assignments_table;
use local_taskflow\taskflow_stringmanager;
use moodle_url;
use stdClass;

/**
 * Modal of the "send reminder" bulk button: sends an existing message template to the assignees
 * of the selected assignments, with the same message classes the scheduled sending uses.
 *
 * @package local_taskflow
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bulk_send_message extends dynamic_form {
    /**
     * Selected assignment ids from the table checkboxes.
     *
     * @return int[]
     */
    private function get_checkedids(): array {
        $raw = $this->_ajaxformdata['checkedids'] ?? '';
        if (is_array($raw)) {
            $ids = $raw;
        } else {
            $ids = explode(',', (string)$raw);
        }
        return array_values(array_filter(array_map('intval', $ids)));
    }

    /**
     * Form definition.
     * @return void
     */
    protected function definition(): void {
        global $DB;
        $mform = $this->_form;

        $mform->addElement('hidden', 'checkedids');
        $mform->setType('checkedids', PARAM_SEQUENCE);
        $mform->setConstant('checkedids', implode(',', $this->get_checkedids()));

        $mform->addElement(
            'static',
            'selectedcount',
            '',
            taskflow_stringmanager::get_string('bulk_sendreminder_body_count', count($this->get_checkedids()))
        );

        $options = [];
        foreach ($DB->get_records('local_taskflow_messages', null, 'name', 'id, name') as $template) {
            $options[(int)$template->id] = format_string($template->name);
        }
        if (empty($options)) {
            $mform->addElement('static', 'notemplates', '', taskflow_stringmanager::get_string('bulk_sendreminder_notemplates'));
            return;
        }
        $mform->addElement('select', 'messageid', taskflow_stringmanager::get_string('bulk_sendreminder_template'), $options);
        $mform->setType('messageid', PARAM_INT);
    }

    /**
     * Sends the template to every selected assignment the user may change; already sent messages are skipped.
     *
     * @return stdClass
     */
    public function process_dynamic_submission(): stdClass {
        global $DB;
        $data = $this->get_data();
        $messageid = (int)($data->messageid ?? 0);
        $ids = array_filter(array_map('intval', explode(',', (string)($data->checkedids ?? ''))));
        $done = 0;
        $skipped = 0;
        if ($messageid > 0 && !empty($ids)) {
            [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'a');
            foreach ($DB->get_records_select('local_taskflow_assignment', "id $insql", $params) as $assignment) {
                if (!assignments_table::may_change_assignment($assignment)) {
                    $skipped++;
                    continue;
                }
                try {
                    $message = messages_factory::instance(
                        (object)['messageid' => $messageid],
                        (int)$assignment->userid,
                        (int)$assignment->ruleid,
                        true
                    );
                } catch (\Throwable $e) {
                    $message = null;
                }
                if ($message === null || $message->was_already_send()) {
                    $skipped++;
                    continue;
                }
                $message->send_and_save_message();
                $done++;
            }
        }
        return (object)[
            'success' => $done > 0 ? 1 : 0,
            'message' => taskflow_stringmanager::get_string(
                'bulk_sendreminder_done',
                (object)['done' => $done, 'skipped' => $skipped]
            ),
        ];
    }

    /**
     * Nothing to preload.
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {
        $this->set_data(['checkedids' => implode(',', $this->get_checkedids())]);
    }

    /**
     * Page URL.
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        return new moodle_url('/local/taskflow/dashboard.php');
    }

    /**
     * Context.
     * @return \context
     */
    protected function get_context_for_dynamic_submission(): \context {
        return context_system::instance();
    }

    /**
     * Access: editors of assignments or supervisors; the rights per assignment are checked on submit.
     * @return void
     */
    protected function check_access_for_dynamic_submission(): void {
        require_login();
        $context = context_system::instance();
        if (
            !has_capability('local/taskflow:editassignment', $context)
            && !has_capability('local/taskflow:issupervisor', $context)
        ) {
            throw new \required_capability_exception($context, 'local/taskflow:editassignment', 'nopermissions', '');
        }
    }
}
