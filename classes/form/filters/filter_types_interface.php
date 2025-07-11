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
 * Form to create rules.
 *
 * @package   local_taskflow
 * @copyright 2025
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\form\filters;

use MoodleQuickForm;

/**
 * Demo step 1 form.
 */
interface filter_types_interface {
    /**
     * This class passes on the fields for the mform.
     * @param array $repeatarray
     * @param MoodleQuickForm $mform
     */
    public static function definition(&$repeatarray, $mform);

    /**
     * This class passes on the fields for the mform.
     * @param MoodleQuickForm $mform
     * @param string $elementcounter
     */
    public function hide_and_disable(&$mform, $elementcounter);

    /**
     * Implement get data function to return data from the form.
     * @param array $step
     * @return array
     */
    public static function get_data(array $step);

    /**
     * Get the operators to use in mform select elements.
     * @return array
     */
    public static function get_options();

    /**
     * Get the operators to use in mform select elements.
     * @return array
     */
    public static function get_operators();
}
