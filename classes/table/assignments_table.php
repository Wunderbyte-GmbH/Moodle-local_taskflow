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
use html_writer;
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
class assignments_table extends wunderbyte_table {
    /**
     * Add column with actions.
     * @param mixed $values
     * @return string
     */
    public function col_actions($values) {

        $url = new moodle_url('/local/taskflow/editassignment.php', [
            'id' => $values->id,
        ]);

        $html = html_writer::div(html_writer::link(
            $url->out(),
            "<i class='icon fa fa-edit'></i>"
        ));
        return $html;
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
    public function col_targets($values) {
        $jsonobject = json_decode($values->targets) ?? [];
        $html = '';
        foreach ($jsonobject as $item) {
            $html .= "$item->targettype: $item->targetname";
        }
        return html_writer::div($html);
    }

    /**
     * Is active.
     * @param mixed $values
     * @return string
     */
    public function col_isactive($values) {
        return html_writer::div($values->isactive ? get_string('yes') : get_string('no'));
    }
}
