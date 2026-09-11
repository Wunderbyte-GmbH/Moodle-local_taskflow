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

use local_taskflow\local\supervisor\supervisor;
use context_system;
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
        $cache = cache::make('local_taskflow', 'dashboardfilter');
            $key = 'dashboardfilter';
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
        $cache = cache::make('local_taskflow', 'dashboardfilter');
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
     * Whether the logged-in user may open a dashboard tab for a person.
     *
     * Same scope as the user search (local_taskflow_search_users): everybody with viewreports, the own visible
     * team with issupervisor, and always the viewer themselves.
     *
     * @param int $userid
     * @return bool
     */
    public static function may_show_user(int $userid): bool {
        return in_array($userid, self::filter_visible_userids([$userid]), true);
    }

    /**
     * Reduces a list of user ids to the persons the logged-in user may see on the dashboard.
     *
     * @param int[] $userids
     * @return int[]
     */
    public static function filter_visible_userids(array $userids): array {
        global $USER;
        $context = context_system::instance();
        $canviewall = has_capability('local/taskflow:viewreports', $context);
        $issupervisor = has_capability('local/taskflow:issupervisor', $context);
        $team = null;
        $visible = [];
        foreach ($userids as $userid) {
            $userid = (int)$userid;
            $user = core_user::get_user($userid);
            if (!$user || !empty($user->deleted)) {
                continue;
            }
            if ($userid === (int)$USER->id || $canviewall) {
                $visible[] = $userid;
                continue;
            }
            if (!$issupervisor) {
                continue;
            }
            $team ??= array_map('intval', supervisor::get_visible_subordinate_ids((int)$USER->id));
            if (in_array($userid, $team, true)) {
                $visible[] = $userid;
            }
        }
        return $visible;
    }

    /**
     * Returns all currently stored users in an array.
     *
     * @return array
     *
     */
    public function get_all_users() {
        $cache = cache::make('local_taskflow', 'dashboardfilter');
            $key = 'dashboardfilter';
            $filter = $cache->get($key) ?: [];
            return $filter;
    }
}
