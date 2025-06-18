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

namespace local_taskflow\local\messages;

use local_taskflow\local\rules\rules;
use stdClass;

/**
 * Class unit
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class message_sending_time {
    /** @var stdClass */
    private $message;

    /** @var stdClass */
    private $action;
    /**
     * Factory for the organisational units
     * @param stdClass $message
     * @param stdClass $action
     */
    public function __construct($message, $action) {
        $this->message = $message;
        $this->action = $action;
    }
    /**
     * Factory for the organisational units
     * @param stdClass $assignemnt
     * @return int
     */
    public function calaculate_sending_time($assignemnt) {
        $sendingsettings = json_decode($this->message->sending_settings);

        $targetdate = $assignemnt->assigneddate ?? time();
        if ($sendingsettings->sendstart == 'end') {
            $targetdate = $assignemnt->duedate ?? time();
        }

        $days = $sendingsettings->senddays ?? 0;
        $targetdifference = (int)$days * 86400;

        if (
            isset($sendingsettings->senddirection) &&
            $sendingsettings->senddirection === 'before'
        ) {
            return $targetdate - $targetdifference;
        }
        return $targetdate + $targetdifference;
    }
}
