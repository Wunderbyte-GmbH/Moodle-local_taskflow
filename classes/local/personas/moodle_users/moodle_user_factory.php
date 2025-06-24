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

namespace local_taskflow\local\personas\moodle_users;

use local_taskflow\local\personas\moodle_users\types\moodle_user;
use local_taskflow\local\users_profile\users_profile_factory;

/**
 * Repository for dependecy injection
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_user_factory implements user_repository_interface {
    /**
     * Private constructor to prevent direct instantiation.
     * @param array $userdata
     * @return mixed
     */
    public function update_or_create(array $userdata): mixed {
        $user = new moodle_user($userdata);
        return $user->update_or_create();
    }

    /**
     * Private constructor to prevent direct instantiation.
     * @param array $userdata
     * @return mixed
     */
    public function get_user(array $userdata): mixed {
        $user = \core_user::get_user_by_email($userdata['email']);
        if (!$user) {
            return null;
        }

        $customfields = profile_user_record($user->id, false);
        return json_decode($customfields->unit_info ?? null);
    }
}
