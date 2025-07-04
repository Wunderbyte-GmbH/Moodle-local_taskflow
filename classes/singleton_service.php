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

namespace local_taskflow;

use core_user;
use stdClass;

/**
 * Singleton Service to improve performance.
 *
 * @package local_taskflow
 * @since Moodle 3.11
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class singleton_service {
    // Hold the class instance of the singleton service.

    /** @var singleton_service $instance */
    private static $instance = null;

    /** @var array $users */
    public array $users = [];

    /**
     * Constructor
     *
     * The constructor is private to prevent initiation with outer code.
     *
     * @return void
     */
    private function __construct() {
        // The expensive process (e.g.,db connection) goes here.
    }

    /**
     * The object is created from within the class itself only if the class has no instance.
     *
     * @return singleton_service
     *
     */
    public static function get_instance() {
        if (self::$instance == null) {
            self::$instance = new singleton_service();
        }
        return self::$instance;
    }

    /**
     * Service to create and return singleton instance of Moodle user.
     *
     * @param int $userid
     * @param bool $includeprofilefields
     *
     * @return stdClass
     */
    public static function get_instance_of_user(int $userid, bool $includeprofilefields = false) {
        global $CFG;
        $instance = self::get_instance();
        if (isset($instance->users[$userid])) {
            if ($includeprofilefields && !isset($instance->users[$userid]->profile)) {
                require_once("{$CFG->dirroot}/user/profile/lib.php");
                profile_load_custom_fields($instance->users[$userid]);
            }
            return $instance->users[$userid];
        } else {
            $user = core_user::get_user($userid);
            if ($includeprofilefields) {
                require_once("{$CFG->dirroot}/user/profile/lib.php");
                profile_load_custom_fields($user);
            }
            $instance->users[$userid] = $user;
            return $user;
        }
    }
}
