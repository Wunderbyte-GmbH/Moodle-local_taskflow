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

namespace local_taskflow\local\assignment_process;

use local_taskflow\local\assignments\assignments_facade;
use local_taskflow\local\personas\unit_members\types\unit_member;


/**
 * Class user_updated event handler.
 *
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class longleave_facade {
    /**
     * React on the triggered event.
     * @param string $userid
     * @return void
     */
    public static function longleave_activation($userid): void {
        // Deactive user mebership.
        unit_member::inactivate_all_active_units_of_user($userid);
        // Inactivate assignments.
        assignments_facade::set_all_assignments_inactive($userid);
    }

    /**
     * React on the triggered event.
     * @param string $userid
     * @return void
     */
    public static function longleave_deactivation($userid): void {
        // Active user mebership.
        unit_member::activate_all_inactive_units_of_user($userid);
        // Activate assignments.
        assignments_facade::set_all_paused_assignments_active($userid);
    }

}
