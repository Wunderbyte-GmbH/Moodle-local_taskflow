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

namespace local_taskflow\local\history\types;

use cache_helper;
use local_taskflow\local\assignments\status\assignment_status;
use stdClass;

/**
 * Class unit
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


/**
 * Course type to manage output history.
 */
class course_enrolled extends base {
    /**
     * Summary of render_additional_data
     * @param string $json
     * @return string
     */
    public function render_additional_data(): string {
        $jsonobject = $this->jsonobject;
        $returnstring = '';
        return $returnstring;
    }

    /**
     * Has additional data
     * @return bool
     */
    public function has_additional_data(): bool {
        return false;
    }
}
