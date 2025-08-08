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

use local_taskflow\local\assignments\assignment;
use local_taskflow\local\supervisor\supervisor;
use renderable;
use renderer_base;
use templatable;
use context_system;

/**
 * Display this element
 * @package local_taskflow
 *
 */
class editassignment_template_factory {
    /**
     * data is the array used for output.
     *
     * @var array
     */
    private $data = [];

    /**
     * Instance
     * @param array $data
     */
    public static function instance(array $data) {
        $selectedadapter = get_config('local_taskflow', 'external_api_option');
            $formclassname = "\\taskflowadapter_{$selectedadapter}\\output\\editassignment_template";
        if (!class_exists($formclassname)) {
            $formclassname = "\\taskflowadapter_standard\\output\\editassignment_template";
        }
        return new $formclassname($data);
    }
}
