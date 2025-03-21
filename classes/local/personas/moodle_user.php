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
    /** @var array $user The unique ID of the unit. */
    public $user;

    /**
     * Update the current unit.
     * @param array $persondata
     * @return stdClass
     */
    public function __construct($persondata) {
        $this->user = $persondata;
    }
    /**
     * Update the current unit.
     * @return stdClass
     */
    public function update_or_create() {
        global $DB;
        $user = \core_user::get_user_by_email($this->user['email']);
        if ($user) {
            $userprofile = profile_user_record($user->id, false);
            if ($this->user_has_changed($user, $userprofile)) {
                $updatedata = [
                    'id' => $user->id,
                    'firstname' => $this->user['first_name'],
                    'lastname' => $this->user['second_name'],
                ];
                user_update_user($updatedata);
                $user->profile_field_unit_info = json_encode($this->user['units']);
                profile_save_data($user);
            }
            return $user;
        } else {
            return $this->create_new_user();
        }
    }

    /**
     * Update the current unit.
     * @param stdClass $user
     * @param stdClass $userprofile
     * @return bool
     */
    public function user_has_changed($user, $userprofile) {
        $unitinfo = $userprofile->unit_info ?? '';
        if (
            json_encode($this->user['units']) != json_encode(json_decode($unitinfo, true)) ||
            $user->firstname != $this->user['first_name'] ||
            $user->lastname != $this->user['second_name']
        ) {
            return true;
        }
        return false;
    }

    /**
     * Update the current unit.
     * @return stdClass
     */
    public function create_new_user() {
        global $DB;
        $newuser = new stdClass();
        $newuser->auth = 'manual';
        $newuser->confirmed = 1;
        $newuser->mnethostid = 1;
        $newuser->username = self::generate_unique_username($this->user['first_name'], $this->user['second_name']);
        $newuser->email = $this->user['email'];
        $newuser->firstname = $this->user['first_name'];
        $newuser->lastname = $this->user['second_name'];
        $newuser->password = self::generate_random_password();
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
        $length = 12;
        $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $lowercase = 'abcdefghijklmnopqrstuvwxyz';
        $numbers = '0123456789';
        $special = '@!$%&*-_#';

        $password = substr(str_shuffle($uppercase), 0, 1) .
                    substr(str_shuffle($lowercase), 0, 1) .
                    substr(str_shuffle($numbers), 0, 1) .
                    substr(str_shuffle($special), 0, 1);

        $all = $uppercase . $lowercase . $numbers . $special;
        $remaininglength = $length - strlen($password);
        $password .= substr(str_shuffle($all), 0, $remaininglength);
        return str_shuffle($password);
    }
}
