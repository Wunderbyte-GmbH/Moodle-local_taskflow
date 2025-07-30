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
 * Rules table.
 *
 * @package     local_taskflow
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\table;
use context_system;
use core_user;
use html_writer;
use local_taskflow\local\assignments\assignments_facade;
use local_taskflow\local\assignments\status\assignment_status;
use local_taskflow\local\external_adapter\external_api_base;
use local_taskflow\local\supervisor\supervisor;
use local_taskflow\plugininfo\taskflowadapter;
use local_wunderbyte_table\wunderbyte_table;
use local_wunderbyte_table\output\table;
use moodle_url;

/**
 * Assignments table
 *
 * @package     local_taskflow
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignments_table extends wunderbyte_table {
    /**
     * Add column with actions.
     * @param mixed $values
     * @return string
     */
    public function col_actions($values) {
        global $OUTPUT, $USER, $PAGE;

        $url = new moodle_url('/local/taskflow/assignment.php', [
            'id' => $values->id,
        ]);

        $html = html_writer::div(html_writer::link(
            $url->out(),
            '<i class="icon fa fa-info-circle"></i>'
        ));
        $data = [];
        $supervisor = supervisor::get_supervisor_for_user($values->userid);
        $hascapability = has_capability('local/taskflow:editassignment', context_system::instance());
        if (
            $hascapability ||
            $supervisor->id === $USER->id
        ) {
            $returnurl = $PAGE->url;
            $returnurlout = $returnurl->out(false);
            $url = new moodle_url('/local/taskflow/editassignment.php', [
                'id' => $values->id,
                'returnurl' => $returnurlout,
            ]);

            $html .= html_writer::div(html_writer::link(
                $url,
                "<i class='icon fa fa-edit'></i>"
            ));

            $class = 'fa fa-eye-slash';
            $title = get_string('assignmentactivate', 'local_taskflow');
            if ((int) $values->active > 0) {
                $class = 'fa fa-eye';
                $title = get_string('assignmentdeactivate', 'local_taskflow');
            }

            $data[] = [
                'label' => '', // Name of your action button.
                'href' => '#', // You can either use the link, or JS, or both.
                'iclass' => $class, // Add an icon before the label.
                'arialabel' => 'eye',
                'title' => $title,
                'id' => $values->id . '-'  . $this->uniqueid,
                'name' => $this->uniqueid . '-' . $values->id,
                'methodname' => 'toggleassigmentactive', // The method needs to be added to your child of wunderbyte_table class.
                'nomodal' => true,
                'data' => [ // Will be added eg as data-id = $values->id, so values can be transmitted to the method above.
                    'id' => $values->id,
                    'username' => $values->fullname ?? '',
                    'rulename' => $values->rulename,
                ],
            ];
            table::transform_actionbuttons_array($data);
        }
        return
            $html .
            $OUTPUT->render_from_template('local_wunderbyte_table/component_actionbutton', ['showactionbuttons' => $data]);
    }

    /**
     * Description.
     * @param mixed $values
     * @return string
     */
    public function col_targets($values) {
        $jsonobject = json_decode($values->targets) ?? [];
        $html = '';
        $stringmanager = get_string_manager();
        foreach ($jsonobject as $item) {
            if ($stringmanager->string_exists($item->targettype, 'local_taskflow')) {
                $type = get_string($item->targettype, 'local_taskflow');
            } else {
                $type = $item->targettype;
            }

            $html .= "<b>$type:</b> $item->targetname </br>";
        }
        return html_writer::div($html);
    }

    /**
     * Status Label
     * @param mixed $values
     * @return string
     */
    public function col_status($values): string {
        return assignment_status::get_label($values->status);
    }

    /**
     * Rule Link
     * @param mixed $values
     * @return string
     */
    public function col_rulename($values): string {
        $url = new moodle_url('/local/taskflow/assignment.php', [
            'id' => $values->id,
        ]);
        return html_writer::link($url, $values->rulename, ['class' => 'assignment-rulename']);
    }

    /**
     * All other columns are here.
     *
     * @param mixed $column
     * @param mixed $values
     *
     * @return string
     *
     */
    public function other_cols($column, $values): string {

        $supervisorfield = external_api_base::return_shortname_for_functionname(
            taskflowadapter::TRANSLATOR_USER_SUPERVISOR
        );

        try {
            switch ($column) {
                // Cast userid to name of user.
                case "custom_$supervisorfield":
                    $user = core_user::get_user($values->$column);
                    if ($user) {
                        return core_user::get_fullname($user) ?? '';
                    }
                    return '';
                default:
                    return $values->$column ?? '';
            }
        } catch (\Throwable $e) {
            // If there is an error, we return an empty string.
            return $values->$column ?? '';
        }
    }

    /**
     * Toggle active state of assignement to active - unactive.
     *
     * @param int $id
     * @param string $data
     *
     * @return array
     *
     */
    public function action_toggleassigmentactive(int $id, string $data) {
        $state = assignments_facade::toggle_assignment_active($id);
        $dataobject = json_decode($data);
        $uncheckedmessage = get_string('assignmentuncheckedmess', 'local_taskflow', $dataobject);
        $checkedmessage = get_string('assignmentcheckedmess', 'local_taskflow', $dataobject);
        return [
           'success' => 1,
           'message' => $state > 0 ? $checkedmessage : $uncheckedmessage,
        ];
    }
}
