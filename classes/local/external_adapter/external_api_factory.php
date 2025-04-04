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

namespace local_taskflow\local\external_adapter;

use local_taskflow\local\external_adapter\adapters\external_api_user_data;
use local_taskflow\local\external_adapter\adapters\external_thour_api;

/**
 * Class unit
 *
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class external_api_factory {
    /**
     * Factory for the organisational units
     * @param string $data
     * @return mixed
     */
    public static function create(string $data) {
        $type = get_config('local_taskflow', 'external_api_option');
        return match (strtolower($type)) {
            'user_data' => new external_api_user_data($data),
            'thour_api' => new external_thour_api($data),
            default => throw new \moodle_exception("Invalid group type: $type")
        };
    }
}
