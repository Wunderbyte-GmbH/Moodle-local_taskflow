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

namespace local_taskflow\local\personas\moodle_users\types;

use local_taskflow\local\external_adapter\external_api_base;
use local_taskflow\local\users_profile\users_profile_factory;
use local_taskflow\local\users_profile\users_profile_interface;
use local_taskflow\plugininfo\taskflowadapter;
use moodle_exception;
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

    /** @var users_profile_interface $userdatarepo The unique ID of the unit. */
    public $userdatarepo;

    /** @var string */
    private const TABLENAME = 'local_taskflow_unit_members';

    /**
     * Update the current unit.
     * @param array $persondata
     * @return stdClass
     */
    public function __construct($persondata) {
        $this->user = $persondata;
        $this->userdatarepo = users_profile_factory::instance($persondata);
    }
    /**
     * Update the current unit.
     * @return stdClass
     */
    public function update_or_create() {
        global $DB;

        $externalid = external_api_base::return_shortname_for_functionname(taskflowadapter::TRANSLATOR_USER_EXTERNALID);
        if (
            !empty($externalid)
            && isset($this->user[$externalid])
        ) {
            $moodleuser = external_api_base::get_user_by_externalid($this->user[$externalid]);
            if (!empty($moodleuser) && empty($moodleuser->id)) {
                $moodleuser = \core_user::get_user_by_username($this->user[$externalid]);
            }
        }
        if (empty($moodleuser)) {
            $moodleuser = external_api_base::get_user_by_mail($this->user['email']);
            if (empty($moodleuser->id)) {
                $moodleuser = \core_user::get_user_by_email($this->user['email']);
            }
        }
        if (empty($moodleuser->id)) {
            $moodleuser = $this->create_new_user();
        }
        if ($this->user_has_changed($moodleuser, (object)($moodleuser->profile ?? []))) {
            $updatedata = [
                'id' => $moodleuser->id,
                'firstname' => $this->user['firstname'],
                'lastname' => $this->user['lastname'],
                'phone' => $this->user['phone'] ?? '',
                'email' => $this->user['email'] ?? '',
            ];
            user_update_user($updatedata);
            $this->userdatarepo->update_or_create();
        }
        return $moodleuser;
    }

    /**
     * Update the current unit.
     * @param stdClass $user
     * @param stdClass $userprofile
     * @return bool
     */
    public function user_has_changed($user, $userprofile) {
        $shortname = external_api_base::return_shortname_for_functionname(taskflowadapter::TRANSLATOR_USER_TARGETGROUP);
        if (empty($shortname)) {
            $shortname = external_api_base::return_shortname_for_functionname(taskflowadapter::TRANSLATOR_USER_ORGUNIT);
        }
        if (empty($shortname)) {
            throw new moodle_exception('orgrolenotattributedcorrectly');
        }
        if (!is_array($this->user[$shortname])) {
            $this->user[$shortname] = json_encode($this->user[$shortname] ?? '');
        }

        $newvalue  = $this->normalize_profile_value($this->user[$shortname] ?? null);
        $oldvalue = $this->normalize_profile_value($userprofile->$shortname ?? null);

        if (
            $oldvalue != $newvalue
            || $user->firstname != $this->user['firstname']
            || $user->lastname != $this->user['lastname']
            || $user->email != $this->user['email']
        ) {
            return true;
        }
        return false;
    }

    /**
     * Helper function to make sure we compare the same thing.
     *
     * @param mixed $value
     *
     * @return [type]
     *
     */
    public function normalize_profile_value($value) {
        // Not set → treat as empty
        if (!isset($value)) {
            return [];
        }

        // Real array → keep as is
        if (is_array($value)) {
            return $value;
        }

        // Trim whitespace
        $value = trim($value);

        // Empty string → treat as empty array
        if ($value === '') {
            return [];
        }

        // Detect JSON arrays like '[]' or '["a","b"]'
        if ((str_starts_with($value, '[') && str_ends_with($value, ']'))) {
            $decoded = json_decode($value, true);

            // If valid JSON and decoded to array
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // Anything else (string values etc.)
        return [$value];
    }

    /**
     * Update the current unit.
     * @return stdClass
     */
    public function create_new_user() {
        global $DB, $CFG;
        $newuser = new stdClass();
        $newuser->auth = get_config('local_taskflow', 'defaultauth') ? get_config('local_taskflow', 'defaultauth') : 'manual';
        $newuser->confirmed = 1;
        $newuser->mnethostid = $CFG->mnet_localhost_id;
        $newuser->username = $this->create_username();
        $newuser->email = $this->user['email'];
        $newuser->firstname = $this->user['firstname'];
        $newuser->lastname = $this->user['lastname'];
        $newuser->password = self::generate_random_password();
        $newuser->timecreated = time();
        $newuser->id = user_create_user($newuser);
        return $newuser;
    }

    /**
     * Generate a unique username based on first and last name.
     * @param string $firstname
     * @param string $lastname
     * @return string
     */
    private static function generate_unique_username($firstname, $lastname) {
        global $DB;
        $base = strtolower(trim($firstname) . '.' . trim($lastname));

        $translit = [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue',
            'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue',
            'ß' => 'ss',
        ];
        $base = strtr($base, $translit);
        $base = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base);
        $base = strtolower(preg_replace('/[^a-z0-9.]/', '', $base));
        if (empty($base)) {
            $base = 'user';
        }
        $username = $base;
        $counter = 1;
        while ($DB->record_exists('user', ['username' => $username])) {
            $username = $base . $counter;
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

    /**
     * Generate a random secure password.
     * @param int $userid
     * @return array
     */
    public static function get_all_units_of_user($userid) {
        global $DB;
        return array_keys(
            $DB->get_records(
                self::TABLENAME,
                [
                    'userid' => $userid,
                ],
                null,
                'unitid'
            )
        );
    }
    /**
     * Creates username.
     *
     * @return string
     *
     */
    private function create_username() {
        $externalid = external_api_base::return_shortname_for_functionname(taskflowadapter::TRANSLATOR_USER_EXTERNALID);
        if (!isset($this->user[$externalid])) {
            return self::generate_unique_username($this->user['firstname'], $this->user['lastname']);
        }
        return (string) $this->user[$externalid];
    }
}
