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

namespace local_taskflow\local\messages\placeholders\types;

use local_taskflow\local\messages\placeholders\placeholders_interface;
use stdClass;

/**
 * Class unit
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lastname implements placeholders_interface {
    /** @var mixed Event name for user updated. */
    public mixed $rule;

    /** @var mixed Event name for user updated. */
    public mixed $user;

    /** @var stdClass Event name for user updated. */
    public stdClass $assignment;

    /**
     * Factory for the organisational units
     * @param int $ruleid
     * @param int $userid
     * @param stdClass $assignment
     */
    public function __construct($ruleid, $userid, $assignment) {
        $this->rule = $ruleid;
        $this->user = \core_user::get_user($userid);
        $this->assignment = $assignment;
    }

    /**
     * Factory for the organisational units
     * @param stdClass $message
     */
    public function render(&$message) {
        $placeholdertarget = "<lastname>";
        $placeholderreplace = $this->get_replacement();
        foreach ($message->message as &$messagepart) {
            $messagepart = str_replace(
                $placeholdertarget,
                $placeholderreplace,
                $messagepart
            );
        }
    }

    /**
     * Factory for the organisational units
     * @return string
     */
    private function get_replacement() {
        return $this->user->lastname;
    }
}
