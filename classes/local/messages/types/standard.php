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

namespace local_taskflow\local\messages\types;

use local_taskflow\local\messages\messages_interface;
use stdClass;

/**
 * Class unit
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class standard implements messages_interface {
    /** @var stdClass Event name for user updated. */
    public mixed $target;

    /** @var int Event name for user updated. */
    public mixed $userid;

    /**
     * Factory for the organisational units
     * @param stdClass $target
     * @param int $userid
     */
    public function __construct($target, $userid) {
        $this->target = $target;
        $this->userid = $userid;
    }

    /**
     * Factory for the organisational units
     * @return bool
     */
    public function was_already_send() {
        // Check if message was already send.
        return true;
    }

    /**
     * Factory for the organisational units
     * @return void
     */
    public function send_message() {
        // Send the message.
        $test = true;
    }

}
