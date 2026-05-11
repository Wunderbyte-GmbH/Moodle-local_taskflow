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

/**
 * Class unit
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Competency completed type to manage output history.
 */
class competency_completed extends base {
    /**
     * Render additional data for the history entry.
     * @return string
     */
    public function render_additional_data(): string {
        global $DB;
        $usercompid = (int) ($this->jsonobject->objectid ?? 0);
        $relateduserid = (int) ($this->jsonobject->relateduserid ?? 0);
        if ($usercompid && $relateduserid) {
            $competencyid = $DB->get_field(
                'competency_usercomp',
                'competencyid',
                ['id' => $usercompid, 'userid' => $relateduserid]
            );
            if ($competencyid) {
                return $DB->get_field('competency', 'shortname', ['id' => $competencyid]) ?: '';
            }
        }
        return '';
    }

    /**
     * Has additional data
     * @return bool
     */
    public function has_additional_data(): bool {
        return true;
    }
}
