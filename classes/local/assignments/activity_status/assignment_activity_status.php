<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_taskflow\local\assignments\activity_status;

/**
 * Represents assignment status codes and labels.
 *
 * @package     local_taskflow
 * @copyright   2025 Wunderbyte Gmbh <info@wunderbyte.at>
 * @author      Mahdi Poustini
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignment_activity_status {
    /**
     * Assignment is paused (temporarily inactive).
     */
    public const PAUSED = -1;

    /**
     * Assignment is inactive (not started or archived).
     */
    public const INACTIVE = 0;

    /**
     * Assignment is active (in progress).
     */
    public const ACTIVE = 1;

    /**
     * Returns all activity status values with translated labels.
     *
     * @return array<int, string>
     */
    public static function get_all(): array {
        return [
            self::PAUSED => get_string('activitypaused', 'local_taskflow'),
            self::INACTIVE => get_string('activityinactive', 'local_taskflow'),
            self::ACTIVE => get_string('activityactive', 'local_taskflow'),
        ];
    }

    /**
     * Get a label for a given activity status.
     *
     * @param int $status
     * @return string
     */
    public static function get_label(int $status): string {
        $all = self::get_all();
        return $all[$status] ?? get_string('statusunknown', 'local_taskflow');
    }
}
