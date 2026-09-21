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

namespace local_taskflow\output;

use renderer_base;

/**
 * A person opened as a tab on the dashboard: the person page inside the dashboard tabs.
 *
 * Same constructor, data and access rules as the person page; only the surrounding template differs.
 *
 * @package local_taskflow
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class persontab extends personpage {
    /**
     * Rendered inside the dashboard page.
     *
     * @return string
     */
    public function get_template(): string {
        return 'local_taskflow/dashboardpage';
    }

    /**
     * Person page data, flagged as a person view of the dashboard.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $data = parent::export_for_template($output);
        $data['personview'] = true;
        $data['isperson'] = false;
        return $data;
    }
}
