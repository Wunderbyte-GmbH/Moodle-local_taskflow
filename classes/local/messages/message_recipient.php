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

use core_user;
use local_taskflow\local\external_adapter\external_api_base;
use local_taskflow\plugininfo\taskflowadapter;
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
     * @return array
     */
    public function get_recepient() {
        $recipientmails = [];
        $recipients = $this->sendingsettings->recipientrole ?? [];
        if (is_array($recipients)) {
            foreach ($recipients as $recipient) {
                $email = $this->get_recipient($recipient);
                if ($email) {
                    $recipientmails[] = $email;
                }
            }
        } else {
            $recipientmails[] = $this->get_recipient($recipients);
        }
        return $recipientmails;
    }

    /**
     * Factory for the organisational units
     * @return array
     */
    public function get_carbon_copy() {
        $carboncopymails = [];
        $recipients = $this->sendingsettings->carboncopyrole ?? [];
        foreach ($recipients as $recipient) {
            $email = $this->get_recipient($recipient);
            if (!empty($email)) {
                $carboncopymails[] = $email;
            }
        }
        return $carboncopymails;
    }

    /**
     * Factory for the organisational units
     * @param string $recipient
     * @return stdClass|bool
     */
    private function get_recipient($recipient) {
        $user = false;
        switch ($recipient) {
            case 'supervisor':
                $user = $this->get_supervisor();
                break;
            case 'specificuser':
                $user = $this->get_specificuser();
                break;
            case 'ccspecificuser':
                $user = $this->get_ccspecificuser();
                break;
            default:
                $user = $this->get_user($this->userid);
                break;
        }
        return $user;
    }

    /**
     * Factory for the organisational units
     * @return stdClass|bool
     */
    private function get_supervisor() {
        $user = get_complete_user_data('id', $this->userid);
        $supervisorconfig = external_api_base::return_shortname_for_functionname(taskflowadapter::TRANSLATOR_USER_SUPERVISOR);
        if (
            isset($user->profile[$supervisorconfig]) &&
            is_number($user->profile[$supervisorconfig])
        ) {
            return $this->get_user($user->profile[$supervisorconfig]);
        }
        return false;
    }

    /**
     * Factory for the organisational units
     * @param int $userid
     * @return stdClass|bool
     */
    private function get_user($userid) {
        return core_user::get_user($userid);
    }

    /**
     * Factory for the organisational units
     * @return stdClass|bool
     */
    private function get_specificuser() {
        return $this->get_user($this->sendingsettings->userid);
    }

    /**
     * Factory for the organisational units
     * @return stdClass|bool
     */
    private function get_ccspecificuser() {
        return $this->get_user($this->sendingsettings->ccuserid);
    }
}
