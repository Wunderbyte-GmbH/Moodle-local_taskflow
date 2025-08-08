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
 * Interface for Editassignment templates
 *
 * @package local_taskflow
 * @author David Ala
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\output;

use renderable;
use renderer_base;
use templatable;
/**
 * Interface Editassignment_template
 *
 * @author David Ala
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface editassignment_template_data_interface extends renderable, templatable {
    /**
     * editassignment_template_interface.
     *
     * @param array $data
     *
     */
    public function __construct(array $data);

    /**
     * editassignment_template_interface.
     *
     * @param renderer_base $output
     *
     * @return array
     *
     */
    public function export_for_template(renderer_base $output);
}
