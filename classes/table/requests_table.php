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
 * Requests table.
 *
 * @package     local_taskflow
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Magdalena Holczik
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\table;
use core_user;
use html_writer;
use local_taskflow\local\requests;
use local_taskflow\task\removed_rule;
use local_wunderbyte_table\output\table;
use local_wunderbyte_table\wunderbyte_table;
use core\task\manager;
use moodle_url;

/**
 * Requests table
 *
 * @package     local_taskflow
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Magdalena Holczik
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class requests_table extends wunderbyte_table {
    /**
     * Add column with actions.
     * @param mixed $values
     * @return string
     */
    public function col_act($values) {
        global $OUTPUT;

        $data[] = [
            'label' => '',
            'href' => '#',
            'iclass' => 'fa fa-check',
            'arialabel' => 'confirm',
            'title' => get_string('requestconfirm', 'local_taskflow'),
            'id' => $values->id . '-'  . $this->uniqueid,
            'name' => $this->uniqueid . '-' . $values->id,
            'methodname' => 'confirmrequest',
            'nomodal' => false,
            'selectionmandatory' => true,
            'data' => [
                'id' => "$values->id",
                'titlestring' => 'confirmrequesttitle',
                'requestid' => $values->id,
                'bodystring' => 'confirmdatabody',
                'submitbuttonstring' => 'confirmdatasubmit',
                'component' => 'local_taskflow',
                'labelcolumn' => 'rulename',
                'assignmentid' => $values->assignmentid,
                'userofrequest' => $values->userid,
            ],
        ];
        table::transform_actionbuttons_array($data);
        return
            $OUTPUT->render_from_template('local_wunderbyte_table/component_actionbutton', ['showactionbuttons' => $data]);
    }

    /**
     * Description.
     * @param mixed $values
     * @return string
     */
    public function col_description($values) {
        $jsonobject = json_decode($values->rulejson);
        return html_writer::div($jsonobject->rulejson->rule->description);
    }

    /**
     * Description.
     * @param mixed $values
     * @return string
     */
    public function col_userid($values) {
        return fullname(core_user::get_user($values->userid));
    }

    /**
     * Is active.
     * @param mixed $values
     * @return string
     */
    public function col_isactive($values) {
        return html_writer::div($values->isactive ? get_string('yes') : get_string('no'));
    }

    /**
     * Link to corresponding assignment
     * @param mixed $values
     * @return string
     */
    public function col_assignmentid($values) {

        $url = new moodle_url(
            '/local/taskflow/assignment.php',
            ['id' => $values->assignmentid]
        );

        return html_writer::div(html_writer::link(
            $url->out(),
            get_string('assignmentshow', 'local_taskflow')
        ));
    }

    /**
     * Returns a human readable timestamp of the time created.
     *
     * @param mixed $values
     *
     * @return string
     *
     */
    public function col_timecreated($values): string {
        return userdate($values->timecreated, get_string('strftimedatetime', 'langconfig'));
    }

    /**
     * Returns a human readable timestamp of the time created.
     *
     * @param mixed $values
     *
     * @return string
     *
     */
    public function col_status($values): string {
        return requests::resolve_status($values->status);
    }

    /**
     * Description.
     * @param int $id
     * @param string $data
     * @return array
     */
    public function action_confirmrequest(int $id, string $data) {
        $data = json_decode($data);
        $request = new requests();
        $feedback = $request->confirm(
            $data->requestid,
            $data->assignmentid,
            $data->userid
        );
        if (!$feedback) {
            return [
                'success' => 0,
                'feedback' => get_string('error'),
            ];
        }
        return [
           'success' => 1,
           'feedback' => get_string('requestconfirmsuccess', 'local_taskflow'),
        ];
    }
}
