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
use core_user;
use html_writer;
use local_taskflow\local\assignments\activity_status\assignment_activity_status;
use local_taskflow\local\assignments\status\assignment_status;
use local_wunderbyte_table\wunderbyte_table;
use moodle_url;

/**
 * Assignments table
 *
 * @package     local_taskflow
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class history_table extends wunderbyte_table {
    /**
     * Returns the fullname of the user who created the entry.
     *
     * @param mixed $values
     *
     * @return string
     *
     */
    public function col_createdby($values): string {
        return fullname(core_user::get_user($values->createdby));
    }

    /**
     * Returns the fullname of the user who created the entry.
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
     * Returns the fullname of the user who created the entry.
     *
     * @param mixed $values
     *
     * @return string
     *
     */
    public function col_type($values): string {

        switch ($values->type) {
            case \local_taskflow\local\history\history::TYPE_MESSAGE:
                return get_string('status:messagesent', 'local_taskflow');
            case \local_taskflow\local\history\history::TYPE_MANUAL_CHANGE:
                return get_string('status:manualchange', 'local_taskflow');
            case \local_taskflow\local\history\history::TYPE_LIMIT_REACHED:
                return get_string('status:limitreached', 'local_taskflow');
            case \local_taskflow\local\history\history::TYPE_USER_ACTION:
                return get_string('status:useraction', 'local_taskflow');
            default:
                return $values->type;
        }
    }

    /**
     * Returns the data of the entry.
     *
     * @param mixed $values
     *
     * @return string
     *
     */
    public function col_data($values): string {

        $returnstring = '';
        $jsonobject = json_decode($values->data);
        $changereasons = assignment_status::get_all_changereasons();
        $assignmentstauts = assignment_status::get_all();
        $changereason = $changereasons[$jsonobject->data->change_reason ?? 0] ?? false;
        if ($changereason) {
            $returnstring = get_string('changereasonbecause', 'local_taskflow', $changereason);
        }
        if (!empty($jsonobject->data->comment)) {
            $returnstring .= "<br>" . get_string('changereasoncomment', 'local_taskflow', $jsonobject->data->comment);
        }
        if (isset($jsonobject->data->status) && !is_null($jsonobject->data->status)) {
            $returnstring .=
                "<br>" .
                get_string('currentstatus', 'local_taskflow', $assignmentstauts[$jsonobject->data->status]);
        }
        return $returnstring;
    }
}
