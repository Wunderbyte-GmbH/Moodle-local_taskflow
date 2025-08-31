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
 * Class to manage dashboard caching.
 *
 * @package   local_taskflow
 * @author    Georg Maißer
 * @copyright 2023 Your Name
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\local\dashboardcache;

use cache;
use core_user;

/**
 * Class to manage dashboard caching.
 */
class dashboardcache {
    /**
     * Constructor.
     *
     *
     */
    public function __construct() {
    }

    /**
     * Sets the userid in the session cache.
     *
     * @param int $userid
     *
     * @return void
     *
     */
    public function set_userid(int $userid) {
        $cache  = cache::make('local_taskflow', 'dashboardfilter');
            $key    = 'dashboardfilter';
            $filter = $cache->get($key) ?: [];

            $user = core_user::get_user($userid);
            $filter['userids'][$userid]  = [
                'id'       => $userid,
                'username' => fullname($user),
            ];
            $cache->set($key, $filter);
    }

    /**
     * Remove the userid and return a status message.
     *
     * @param int $userid
     *
     * @return array
     *
     */
    public static function remove_userid(int $userid) {
        // Access the cache.
        $cache  = cache::make('local_taskflow', 'dashboardfilter');
        $filter = $cache->get('dashboardfilter') ?: [];

        if (isset($filter['userids'][$userid])) {
            unset($filter['userids'][$userid]);
            $cache->set('dashboardfilter', $filter);
            $status  = 'removed';
            $message = "User {$userid} removed from dashboardfilter cache.";
        } else {
            $status  = 'missing';
            $message = "User {$userid} not present in cache.";
        }
        return [$status, $message];
    }

    /**
     * Returns all currently stored users in an array.
     *
     * @return array
     *
     */
    public function get_all_users() {
        $cache  = cache::make('local_taskflow', 'dashboardfilter');
            $key    = 'dashboardfilter';
            $filter = $cache->get($key) ?: [];
            return $filter;
    }
}
