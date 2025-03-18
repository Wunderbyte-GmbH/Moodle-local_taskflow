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

namespace local_taskflow\local\personas;

use stdClass;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/user/lib.php');

/**
 * Class unit_member
 *
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_user {
    /**
     * Private constructor to prevent direct instantiation.
     *
     * @param stdClass $data The record from the database.
     */
    private function __construct() {
    }

    /**
     * Update the current unit.
     * @param array $persondata
     * @return stdClass
     */
    public static function update_or_create($persondata) {
        global $DB;
        $user = \core_user::get_user_by_email($persondata['email']);
        if ($user) {
            if (
                $user->firstname != $persondata['first_name'] ||
                $user->lastname != $persondata['second_name']
            ) {
                $updatedata = [
                    'id' => $user->id,
                    'firstname' => $persondata['first_name'],
                    'lastname' => $persondata['second_name'],
                ];
                user_update_user($updatedata);
            }
            return $user;
        } else {
            return self::create_new_user($persondata);
        }
    }

    /**
     * Update the current unit.
     * @param array $persondata
     * @return stdClass
     */
    public static function create_new_user($persondata) {
        global $DB;
        $newuser = new stdClass();
        $newuser->auth = 'manual';
        $newuser->confirmed = 1;
        $newuser->mnethostid = 1;
        $newuser->username = self::generate_unique_username($persondata['first_name'], $persondata['second_name']);
        $newuser->email = $persondata['email'];
        $newuser->firstname = $persondata['first_name'];
        $newuser->lastname = $persondata['second_name'];
        $newuser->password = hash_internal_user_password('SecurePassword123');
        $newuser->timecreated = time();
        $newuser->id = user_create_user($newuser);
        return $newuser;
    }

    /**
     * Generate a unique username based on first and second name.
     * @param string $firstname
     * @param string $lastname
     * @return string
     */
    private static function generate_unique_username($firstname, $lastname) {
        global $DB;
        $baseusername = strtolower(preg_replace('/\s+/', '', $firstname . '.' . $lastname));
        $username = $baseusername;
        $counter = 1;
        while ($DB->record_exists('user', ['username' => $username])) {
            $username = $baseusername . $counter;
            $counter++;
        }
        return $username;
    }

    /**
     * Generate a random secure password.
     *
     * @return string
     */
    private static function generate_random_password() {
        return substr(md5(uniqid(mt_rand(), true)), 0, 12);
    }
}