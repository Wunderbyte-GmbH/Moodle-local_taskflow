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

namespace local_taskflow\local\units;

use local_taskflow\local\units\organisational_units\cohort;
use local_taskflow\local\units\organisational_units\unit;
use stdClass;
/**
 * Class unit
 *
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class organisational_unit_factory {
    /**
     * Factory for the organisational units
     * @param int $id
     * @return mixed
     */
    public static function instance(int $id) {
        $type = get_config('local_taskflow', 'organisational_unit_option');
        return match (strtolower($type)) {
            'unit' => unit::instance($id),
            'cohort' => cohort::instance($id),
            default => throw new \moodle_exception("Invalid group type: $type")
        };
    }

    /**
     * Factory for the organisational units
     * @param stdClass $data
     * @return mixed
     */
    public static function create_unit(stdClass $data) {
        $type = get_config('local_taskflow', 'organisational_unit_option');
        return match (strtolower($type)) {
            'unit' => unit::create_unit($data),
            'cohort' => cohort::create_unit($data),
            default => throw new \moodle_exception("Invalid group type: $type")
        };
    }
    /**
     * Teardownfunction for unittests.
     *
     * @return void
     *
     */
    public static function teardown() {
        unit::teardown();
        cohort::teardown();
    }
}
