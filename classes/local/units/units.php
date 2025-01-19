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
 * Shopping cart class to manage the shopping cart.
 *
 * @package local_taskflow
 * @author Thomas Winkler
 * @copyright 2021 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\local\units;

/**
 * Class units
 *
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class unit {
    /**
     * The instances of the class.
     *
     * @var array
     */
    private static $instances = [];

    /**
     * Units constructor.
     */
    private function __construct() {
    }

    /**
     * Get the instance of the class for a specific ID.
     *
     * @param int $id
     * @return unit
     * @throws \moodle_exception
     */
    public static function instance($id) {
        if (!isset(self::$instances[$id])) {
            throw new \moodle_exception('invalidid', 'local_taskflow', '', $id);
        }
        return self::$instances[$id];
    }
}
