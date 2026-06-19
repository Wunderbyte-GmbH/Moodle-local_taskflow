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

namespace local_taskflow\local\history\types;

use local_taskflow\taskflow_stringmanager;

/**
 * Mail send type to manage output history.
 */
class mail_send extends base {
    /**
     * Render the output
     * @return string
     */
    public function render_additional_data(): string {
        $messagename = $this->jsonobject->data ?? '';
        if (empty($messagename)) {
            return '';
        }
        // For old entries, the message name was stored as an object with a heading property, so we need to check for that.
        if (is_object($messagename)) {
            $messagename = $messagename->heading;
        }
        return taskflow_stringmanager::get_string('mailsend:messagename', $messagename);
    }

    /**
     * Has additional data
     * @return bool
     */
    public function has_additional_data(): bool {
        return true;
    }
}
