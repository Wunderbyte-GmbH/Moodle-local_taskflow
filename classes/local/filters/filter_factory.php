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

namespace local_taskflow\local\filters;

use stdClass;

/**
 * Class unit
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class filter_factory {
    /**
     * Factory for the organisational units
     * @param stdClass $filter
     * @return mixed
     */
    public function instance(stdClass $filter) {
        $filtertypeclass = 'local_taskflow\\local\\filters\\types\\' . $filter->filtertype;
        if (class_exists($filtertypeclass)) {
            return new $filtertypeclass($filter);
        }
        return null;
    }

    /**
     * Implement get data function to return data from the form.
     *
     * @param array $step
     *
     * @return array
     *
     */
    public static function get_data(array $step): array {

        // We just need the filter data values.
        $replacement = '\\types\\' . $step['typeclass'];

        // Replace the last class segment (after the last backslash).
        $typeclassname = preg_replace('/\\\\[^\\\\]+$/', $replacement, $step['stepclass']);
        $typeclass = new $typeclassname();
        $data = $typeclass->get_data();

        return $data;
    }
}
