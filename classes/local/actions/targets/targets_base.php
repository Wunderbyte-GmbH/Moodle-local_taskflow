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

namespace local_taskflow\local\actions\targets;

/**
 * Class unit
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class targets_base {
    /** @var int $id The target ID. */
    protected int $id;

    /** @var string $name The name of the target. */
    protected string $name;

    /**
     * Factory for the organisational units
     * @return string
     */
    public function get_name() {
        return $this->name;
    }

    /**
     * Factory for the organisational units
     * @param int $assignmentid
     * @return string
     */
    public function get_name_with_link($assignmentid) {
        return $this->name;
    }

    /**
     * Factory for the organisational units
     * @return string
     */
    public function get_id() {
        return $this->id;
    }
}
