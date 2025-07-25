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
 * Plugin renderer.
 *
 * @package local_taskflow
 * @copyright 2025 Wunderbyte Gmbh <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\output;

use plugin_renderer_base;
use templatable;

/**
 * Renderer class.
 * @package local_taskflow
 */
class renderer extends plugin_renderer_base {
    /**
     * Render add to cart button
     *
     * @param templatable $class
     * @return string|bool
     */
    public function render_rulesdashboard(templatable $class) {
        $data = $class->export_for_template($this);
        return $this->render_from_template('local_taskflow/dashboards/dashboard_rules', $data);
    }

    /**
     * Render add to cart button
     *
     * @param templatable $class
     * @return string|bool
     */
    public function render_assignmentsdashboard(templatable $class) {
        $data = $class->export_for_template($this);
        return $this->render_from_template('local_taskflow/dashboards/dashboard_assignments', $data);
    }

    /**
     * Render add to cart button
     *
     * @param templatable $class
     * @return string|bool
     */
    public function render_userassignment(templatable $class) {
        $data = $class->export_for_template($this);
        return $this->render_from_template('local_taskflow/userassignment', $data);
    }

    /**
     * Render add to cart button
     *
     * @param templatable $class
     * @return string|bool
     */
    public function render_editassignment(templatable $class) {
        $data = $class->export_for_template($this);
        return $this->render_from_template('local_taskflow/editassignment', $data);
    }

    /**
     * Render add to cart button
     *
     * @param templatable $class
     * @return string|bool
     */
    public function render_history(templatable $class) {
        $data = $class->export_for_template($this);
        return $this->render_from_template('local_taskflow/history', $data);
    }

    /**
     * Render single assignment
     *
     * @param templatable $class
     * @return string|bool
     */
    public function render_singleassignment(templatable $class) {
        $data = $class->export_for_template($this);
        return $this->render_from_template('local_taskflow/singleassignment', $data);
    }
}
