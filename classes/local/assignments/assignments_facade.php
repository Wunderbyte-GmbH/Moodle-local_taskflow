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
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\local\assignments;

use local_taskflow\local\assignments\types\standard_assignment;
use local_taskflow\local\personas\moodle_users\types\moodle_user;
use local_taskflow\local\personas\unit_members\types\unit_member;

/**
 * Class unit
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignments_facade {
    /**
     * Factory for the organisational units
     * @param mixed $record
     * @return int
     */
    public static function update_or_create_assignment(mixed $record) {
        return standard_assignment::update_or_create_assignment((object) $record);
    }

    /**
     * Factory for the organisational units
     * @param int $unit
     * @param int $userid
     * @return bool
     */
    public static function delete_assignments($unit, $userid) {
        return standard_assignment::delete_assignments($unit, $userid);
    }

    /**
     * Factory for the organisational units
     * @param int $userid
     * @return void
     */
    public static function set_all_assignments_inactive($userid) {
        $assignemnts = standard_assignment::get_all_active_user_assignments($userid);
        foreach ($assignemnts as $assignemnt) {
            $assignemnt->active = 0;
            $assignemnt->timemodified = time();
            standard_assignment::update_or_create_assignment((object) $assignemnt);
        }

        unit_member::inactivate_all_acitve_units_of_user($userid);
        return;
    }
}
