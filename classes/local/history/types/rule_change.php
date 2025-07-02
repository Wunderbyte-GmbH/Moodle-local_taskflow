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
 * Unit class to manage users.
 *
 * @package local_taskflow
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\local\history\types;

use cache_helper;
use local_taskflow\local\assignments\status\assignment_status;
use stdClass;

/**
 * Class unit
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


/**
 * Course type to manage output history.
 */
class rule_change extends base {
    /**
     * Summary of render_additional_data
     * @return string
     */
    public function render_additional_data(): string {
        $jsonobject = $this->jsonobject;
        $returnstring = '';
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

    /**
     * Has additional data
     * @return bool
     */
    public function has_additional_data(): bool {
        return true;
    }
}
