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
class targets_factory {
    /**
     * Factory for the organisational units.
     * @param string $type
     * @param string $targetid
     * @return mixed
     */
    public static function get_name($type, $targetid) {
        $targettypeclass = 'local_taskflow\\local\\actions\\targets\\types\\' . $type;
        if (class_exists($targettypeclass)) {
            $targetinstance = $targettypeclass::instance($targetid);
            if ($targetinstance) {
                return $targetinstance->get_name();
            }
        }
        return '';
    }
}
