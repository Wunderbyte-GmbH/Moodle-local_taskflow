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

use stdClass;


/**
 * Class unit
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class message_recipient {
    /** @var int */
    private $userid;

    /** @var stdClass */
    private $sendingsettings;
    /**
     * Factory for the organisational units
     * @param int $userid
     * @param stdClass $messagedata
     */
    public function __construct($userid, $messagedata) {
        $this->userid = $userid;
        $this->sendingsettings = json_decode($messagedata->sending_settings);
    }
    /**
     * Factory for the organisational units
     * @return string
     */
    public function get_recepient() {

        if (
            isset($this->sendingsettings->recipientrole) &&
            $this->sendingsettings->recipientrole == 'supervisor'
        ) {
            $user = get_complete_user_data('id', $this->userid);
            $supervisorconfig = get_config('local_taskflow', 'supervisor_field');
            if (isset($user->profile[$supervisorconfig])) {
                return $user->profile[$supervisorconfig];
            }
            return '';
        }
        return $this->userid;
    }
}
