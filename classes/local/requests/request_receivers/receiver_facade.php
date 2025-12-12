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

namespace local_taskflow\local\requests\request_receivers;

use stdClass;

/**
 * Class requests
 *
 * @package    local_taskflow
 * @copyright  2025 Georg Maißer <georg.maißer@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class receiver_facade {
    /**
     * Factory for the organisational units.
     * @return array
     */
    public static function get_request_receivers() {
        $receivers = [];
        $path = __DIR__ . '/receivers';
        $prefix = 'local_taskflow\\local\\requests\\request_receivers\\receivers\\';
        foreach (glob($path . '/*.php') as $file) {
            $basename = basename($file, '.php');
            $classname = $prefix . $basename;
            if (class_exists($classname)) {
                $instance = new $classname();
                $receivers[$instance->get_id()] = $instance;
            }
        }
        return $receivers;
    }

    /**
     * Factory for the organisational units.
     * @param string $receiverid
     * @param stdClass $assignment
     * @return array
     */
    public static function get_request_receiver($receiverid, $assignment) {
        $receivers = [];
        $path = __DIR__ . '/receivers';
        $prefix = 'local_taskflow\\local\\requests\\request_receivers\\receivers\\';
        foreach (glob($path . '/*.php') as $file) {
            $basename = basename($file, '.php');
            $classname = $prefix . $basename;
            if (
                class_exists($classname) &&
                $classname::ID == $receiverid
            ) {
                $instance = new $classname();
                $receivers = $instance->get_users($assignment);
                continue;
            }
        }
        return $receivers;
    }
}
