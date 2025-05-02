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

namespace local_taskflow\local\messages\placeholders;

use stdClass;

/**
 * Class unit
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class placeholders_factory {
    /**
     * Factory for the organisational units
     * @param stdClass $message
     * @param int $ruleid
     * @param int $userid
     * @return stdClass
     */
    public static function render_placeholders($message, $ruleid, $userid) {
        $placeholders = self::get_placeholder($message->message);
        foreach ($placeholders as $placeholdertype) {
            $placeholder = new $placeholdertype($ruleid, $userid);
            $message = $placeholder->render($message);
        }
        return $message;
    }

    /**
     * Factory for the organisational units
     * @param array $message
     * @return bool
     */
    public static function has_placeholders($message) {
        $potentialplaceholders = [];
        foreach ($message as $part) {
            preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $part, $matches);
            $potentialplaceholders = array_merge($matches[1], $potentialplaceholders);
        }
        foreach ($potentialplaceholders as $potentialplaceholder) {
            $placeholdertypeclass = 'local_taskflow\\local\\messages\\placeholders\\types\\' . $potentialplaceholder;
            if (class_exists($placeholdertypeclass)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Factory for the organisational units
     * @param array $message
     * @return array
     */
    private static function get_placeholder($message) {
        $validplaceholders = [];
        $potentialplaceholders = [];

        foreach ($message as $part) {
            preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $part, $matches);
            $potentialplaceholders = array_merge($matches[1], $potentialplaceholders);
        }
        foreach ($potentialplaceholders as $potentialplaceholder) {
            $placeholdertypeclass = 'local_taskflow\\local\\messages\\placeholders\\types\\' . $potentialplaceholder;
            if (class_exists($placeholdertypeclass)) {
                $validplaceholders[] = $placeholdertypeclass;
            }
        }
        return $validplaceholders;
    }

    /**
     * Factory for the organisational units
     * @param array $message
     * @return array
     */
    private static function extract_placeholders($message) {
        $validplaceholders = [];
        $potentialplaceholders = [];
        foreach ($message as $part) {
            preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $part, $matches);
            $potentialplaceholders = array_merge($matches[1], $potentialplaceholders);
        }
        foreach ($potentialplaceholders as $potentialplaceholder) {
            $placeholdertypeclass = 'local_taskflow\\local\\messages\\placeholders\\types\\' . $potentialplaceholder;
            if (class_exists($placeholdertypeclass)) {
                $validplaceholders[] = $potentialplaceholder;
            }
        }
        return $validplaceholders;
    }
}
