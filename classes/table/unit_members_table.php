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

namespace local_taskflow\table;

use html_writer;
use local_wunderbyte_table\wunderbyte_table;
use moodle_url;

/**
 * Members of one organisational unit, loaded lazily on the organisation page (search, paging).
 *
 * @package     local_taskflow
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class unit_members_table extends wunderbyte_table {
    /**
     * Full name linked to the person page.
     *
     * @param mixed $values
     * @return string
     */
    public function col_fullname($values): string {
        $name = fullname($values);
        if ($this->is_downloading()) {
            return $name;
        }
        return html_writer::link(new moodle_url('/local/taskflow/person.php', ['id' => $values->id]), $name);
    }

    /**
     * E-mail.
     *
     * @param mixed $values
     * @return string
     */
    public function col_email($values): string {
        return s($values->email);
    }
}
