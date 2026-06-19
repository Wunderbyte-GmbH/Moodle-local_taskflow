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

use local_taskflow\taskflow_stringmanager;

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
     * CHANGEREASON_SICKNESS
     *
     * @var int
     */
    public const CHANGEREASON_SICKNESS = 1;
    /**
     * CHANGEREASON_HOLIDAYS
     *
     * @var int
     */
    public const CHANGEREASON_HOLIDAYS = 5;
    /**
     * CHANGEREASON_OTHER
     *
     * @var int
     */
    public const CHANGEREASON_OTHER = 10;

    /**
     * Get all change reasons as value => string key.
     *
     * @return array
     */
    public static function get_all_changereasons(): array {
        return [
            self::CHANGEREASON_HOLIDAYS => taskflow_stringmanager::get_string('changereason_holidays'),
            self::CHANGEREASON_OTHER => taskflow_stringmanager::get_string('changereason_other'),
            self::CHANGEREASON_SICKNESS => taskflow_stringmanager::get_string('changereason_sickness'),
        ];
    }
}
