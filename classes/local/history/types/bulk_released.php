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
 * bulk_released type to manage output history.
 *
 * @package local_taskflow
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\local\history\types;

use local_taskflow\taskflow_stringmanager;

/**
 * bulk_released type to manage output history.
 */
class bulk_released extends base {
    /**
     * Render the output: which parked message the decision was taken for.
     * @return string
     */
    public function render_additional_data(): string {
        $messagename = $this->jsonobject->data ?? '';
        if (is_object($messagename)) {
            // For old entries, the message name was stored as an object with a heading property.
            $messagename = $messagename->heading ?? '';
        }
        if (empty($messagename)) {
            return '';
        }
        return taskflow_stringmanager::get_string('bulkreleased:messagename', $messagename);
    }

    /**
     * Has additional data
     * @return bool
     */
    public function has_additional_data(): bool {
        return true;
    }
}
