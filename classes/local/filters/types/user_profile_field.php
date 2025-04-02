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

namespace local_taskflow\local\filters\types;

use core_user;
use local_taskflow\local\filters\filter_interface;
use stdClass;

/**
 * Class unit
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class user_profile_field implements filter_interface {
    /** @var mixed Event name for user updated. */
    public mixed $data;

    /**
     * Factory for the organisational units
     * @param stdClass $data
     */
    public function __construct($data) {
        $this->data = $data;
    }

    /**
     * Factory for the organisational units
     * @param array $rule
     * @param int $userid
     * @return bool
     */
    public function is_valid($rule, $userid) {
        $fieldvalue = $this->get_user_profil_field_value($userid);
        // Get check profile field.
        // Return if match with eqation.
        $testing = ['drgfjrnd'];
        return true;
    }

    /**
     * Factory for the organisational units
     * @param int $userid
     * @return string
     */
    private function get_user_profil_field_value($userid) {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/profile/lib.php');
        $user = core_user::get_user($userid);
        profile_load_custom_fields($user);
        $profilefield = 'profile_field_' . $this->data->userprofilefiled;
        return $user->$profilefield ?? '';
    }
}
