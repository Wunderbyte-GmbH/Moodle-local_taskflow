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

namespace local_taskflow\local\actions\types;

use local_taskflow\local\actions\actions_interface;
use stdClass;

/**
 * Class unit
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enroll implements actions_interface {
    /** @var mixed Event name for user updated. */
    public mixed $data;

    /**
     * Factory for the organisational units
     * @param stdClass $data
     */
    public function __construct($data) {
        $this->data = $data;
    }

    /**
     * Factory for the organisational units
     * @param array $action
     * @param int $userid
     * @return bool
     */
    public function is_active($action, $userid) {
        if ($userid == '') {
            return false;
        }
        return true;
    }

    /**
     * Factory for the organisational units
     * @param array $action
     * @param int $userid
     * @return void
     */
    public function execute($action, $userid) {
        if ($userid == '') {
            $test = false;
        }
        $test = true;
    }

}
