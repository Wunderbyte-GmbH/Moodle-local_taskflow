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

namespace local_taskflow\local\messages;

use local_taskflow\local\messages\types\chat;

/**
 * Class unit
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class messages_facade {
    /**
     * Factory for the organisational units
     * @return array
     */
    public static function get_message_types() {
        $messagetypes = [];
        $allowinternalcommunication = (bool)get_config('local_taskflow', 'allowinternalcommunication');
        $path = __DIR__ . '/types';
        $prefix = 'local_taskflow\\local\\messages\\types\\';
        foreach (glob($path . '/*.php') as $file) {
            $basename = basename($file, '.php');
            $classname = $prefix . $basename;
            if (class_exists($classname)) {
                if ($classname::TYPE === chat::TYPE && !$allowinternalcommunication) {
                    continue;
                }
                $messagetypes[$classname::TYPE] = $classname::TITLE;
            }
        }
        return $messagetypes;
    }

    /**
     * Factory for the organisational units
     * @param object $assignment
     * @return void
     */
    public static function removed_send_messages($assignment) {
        global $DB;
        $DB->delete_records(
            'local_taskflow_sent_messages',
            [
                'userid' => $assignment->userid,
                'ruleid' => $assignment->ruleid,
            ]
        );
        return;
    }

    /**
     * Factory for the organisational units
     * @param string $userid
     * @param string $ruleid
     * @return void
     */
    public static function removed_send_messages_of_user($userid, $ruleid) {
        global $DB;
        $DB->delete_records(
            'local_taskflow_sent_messages',
            [
                'userid' => $userid,
                'ruleid' => $ruleid,
            ]
        );
        return;
    }
}
