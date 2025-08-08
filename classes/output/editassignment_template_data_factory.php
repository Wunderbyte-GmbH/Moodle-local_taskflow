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
 * Contains class mod_questionnaire\output\indexpage
 *
 * @package    local_taskflow
 * @copyright  2025 Wunderbyte Gmbh <info@wunderbyte.at>
 * @author     Georg Maißer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 **/

namespace local_taskflow\output;

/**
 * Display this element
 * @package local_taskflow
 *
 */
class editassignment_template_data_factory {
    /**
     * Get Data function.
     * @param array $data
     */
    public static function get_data(array $data) {
        $selectedadapter = get_config('local_taskflow', 'external_api_option');
            $classname = "\\taskflowadapter_{$selectedadapter}\\output\\editassignment_template_data";
        if (!class_exists($classname)) {
            $classname = "\\taskflowadapter_standard\\output\\editassignment_template_data";
        }
        return new $classname($data);
    }
}
