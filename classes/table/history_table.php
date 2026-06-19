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
use local_taskflow\local\history\types\typesfactory;
use local_wunderbyte_table\wunderbyte_table;
use local_taskflow\taskflow_stringmanager;

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
                return taskflow_stringmanager::get_string('status:messagesent');
            case \local_taskflow\local\history\history::TYPE_MANUAL_CHANGE:
                return taskflow_stringmanager::get_string('status:manualchange');
            case \local_taskflow\local\history\history::TYPE_LIMIT_REACHED:
                return taskflow_stringmanager::get_string('status:limitreached');
            case \local_taskflow\local\history\history::TYPE_USER_ACTION:
                return taskflow_stringmanager::get_string('status:useraction');
            case \local_taskflow\local\history\history::TYPE_RULE_CHANGE:
                return taskflow_stringmanager::get_string('status:rulechange');
            case \local_taskflow\local\history\history::TYPE_STATUS_CHANGED:
                return taskflow_stringmanager::get_string('status:statuschanged');
            case \local_taskflow\local\history\history::TYPE_COMPETENCY_UPLOAD:
                return taskflow_stringmanager::get_string('status:competencyupload');
            case \local_taskflow\local\history\history::TYPE_COURSE_COMPLETED:
                return taskflow_stringmanager::get_string('status:coursecompleted');
            case \local_taskflow\local\history\history::TYPE_COURSE_ENROLLED:
                return taskflow_stringmanager::get_string('status:courseenroled');
            case \local_taskflow\local\history\history::TYPE_MAIL_SEND:
                return taskflow_stringmanager::get_string('status:mailsend');
            case \local_taskflow\local\history\history::TYPE_REQUEST_CONFIRMED:
                return taskflow_stringmanager::get_string('status:requestconfirmed');
            case \local_taskflow\local\history\history::TYPE_REQUEST_DECLINED:
                return taskflow_stringmanager::get_string('status:requestdeclined');
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
        $output = typesfactory::create($values->type ?? '', $values->data);
        return $output->output();
    }
}
