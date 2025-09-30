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

use local_taskflow\local\external_adapter\external_api_base;
use local_taskflow\local\personas\moodle_users\types\moodle_user;
use local_taskflow\plugininfo\taskflowadapter;

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
        if (empty($userdata)) {
            return false;
        }
        $user = new moodle_user($userdata);
        return $user->update_or_create();
    }

    /**
     * Private constructor to prevent direct instantiation.
     * @param array $persons
     * @return void
     */
    public function inactivate_moodle_users(array $persons): void {
        global $DB;
        foreach ($persons as $person) {
            if (
                (
                    isset($person->suspended) &&
                    $person->suspended == '1'
                ) ||
                is_siteadmin($person->id)
            ) {
                continue;
            }

            $person->suspended = 1;
            $person->timemodified = time();

            user_update_user($person);
            \core\session\manager::destroy_user_sessions($person->id);
        }
        return;
    }

    /**
     * Private constructor to prevent direct instantiation.
     * @param array $persons
     * @return void
     */
    public function activate_moodle_users(array $persons): void {
        global $DB;

        if (!empty($persons)) {
            $personsids = array_keys($persons);
            [$notinsql, $notinparams] = $DB->get_in_or_equal($personsids, SQL_PARAMS_NAMED, 'param', false);
            $where = "suspended = 1 AND id $notinsql";
            $params = $notinparams;
        } else {
            $where = "suspended = 1";
            $params = [];
        }

        $suspendedusers = $DB->get_records_select('user', $where, $params, '', 'id, suspended, timemodified');

        foreach ($suspendedusers as $user) {
            $user->suspended = 0;
            $user->timemodified = time();
            external_api_base::$importing = false;
            user_update_user($user);
        }
        return;
    }

    /**
     * Get targetgroups for user.
     *
     * @param array $userdata
     * @param external_api_base $adapter
     *
     * @return mixed
     *
     */
    public function get_user_targetgroups(array $userdata, external_api_base $adapter): mixed {
        $user = \core_user::get_user_by_email($userdata['email'] ?? null);
        if (!$user) {
            return null;
        }

        $customfields = profile_user_record($user->id, false);
        $user->profile = (array) $customfields;
        $oldunits = $adapter->return_value_for_functionname(taskflowadapter::TRANSLATOR_USER_TARGETGROUP, $user);
        return empty($oldunits) ? null : json_decode($oldunits);
    }


    /**
     * Returns the user object by email from static or db.
     *
     * @param string $email
     * @param bool $includeprofile
     *
     * @return mixed
     *
     */
    public function get_user_by_mail(string $email, bool $includeprofile = true): mixed {
        // First try to receive user by singleton.
        $user = external_api_base::get_user_by_mail($email);
        if (empty($user->id)) {
            $user = \core_user::get_user_by_email($email);
        }
        if (empty($user->id)) {
            return null;
        }
        if ($includeprofile) {
            $customfields = profile_user_record($user->id, false);
            $user->profile = (array) $customfields;
        }
        return $user;
    }

    /**
     * Retrieve user by external id from static or the db.
     *
     * @param string $externalid
     * @param bool $includeprofile
     *
     * @return mixed
     *
     */
    public function get_user_by_externalid(string $externalid, bool $includeprofile = true): mixed {
        // First try to receive user by singleton.
        $user = external_api_base::get_user_by_externalid($externalid);
        if (empty($user->id)) {
            $user = \core_user::get_user_by_username($externalid);
        }
        if (empty($user->id)) {
            return null;
        }
        if ($includeprofile) {
            $customfields = profile_user_record($user->id, false);
            $user->profile = (array) $customfields;
        }
        return $user;
    }
}
