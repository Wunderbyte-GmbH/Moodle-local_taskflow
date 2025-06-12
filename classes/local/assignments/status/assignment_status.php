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

namespace local_taskflow\local\assignments\status;

/**
 * Represents assignment status codes and labels.
 *
 * @package     local_taskflow
 * @copyright   2025 Wunderbyte Gmbh <info@wunderbyte.at>
 * @author      Mahdi Poustini
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignment_status {
    /**
     * Status indicating that the assignment has been created but no further action has been taken yet.
     */
    public const STATUS_CREATED = 0;

    /**
     * Status indicating that the user is enrolled or has booked the course.
     */
    public const STATUS_ENROLLED = 3;

    /**
     * Status indicating that the deadline has been extended beyond the original due date.
     */
    public const STATUS_PROLONGED = 5;

    /**
     * Status indicating that at least one target in the assignment has been completed.
     */
    public const STATUS_PARTIALLY_COMPLETED = 7;

    /**
     * Status indicating that all targets have been completed and the assignment is finished.
     */
    public const STATUS_COMPLETED = 10;

    /**
     * Status indicating that the assignment is overdue and the deadline has passed.
     */
    public const STATUS_OVERDUE = 15;


    /**
     * Get all statuses as value => string key.
     *
     * @return array
     */
    public static function get_all(): array {
        return [
            self::STATUS_CREATED => get_string('statuscreated', 'local_taskflow'),
            self::STATUS_ENROLLED => get_string('statusenrolled', 'local_taskflow'),
            self::STATUS_PROLONGED => get_string('statusprolonged', 'local_taskflow'),
            self::STATUS_PARTIALLY_COMPLETED => get_string('statuspartiallycompleted', 'local_taskflow'),
            self::STATUS_COMPLETED => get_string('statuscompleted', 'local_taskflow'),
            self::STATUS_OVERDUE => get_string('statusoverdue', 'local_taskflow'),
        ];
    }

    /**
     * Get the string label for a single status.
     *
     * @param int $status
     * @return string
     */
    public static function get_label(int $status): string {
        $all = self::get_all();
        return $all[$status] ?? get_string('statusunknown', 'local_taskflow');
    }
}
