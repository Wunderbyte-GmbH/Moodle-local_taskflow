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

/**
 * Class unit
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class messages_manager {
    /** @var int Userid. */
    public int $userid;

    /** @var array All sent messages to user. */
    public array $sentmessages;

    /** @var array All assignments from user. */
    public array $userassignment;

    /**
     * Factory for the organisational units
     * @param int $userid
     * @return void
     */
    public function __construct($userid) {
        $this->userid = $userid;
        $this->sentmessages = $this->get_user_sent_messages();
        $this->userassignment = $this->get_user_assignments();
        return;
    }

    /**
     * Set all sent messages
     * @return array
     */
    private function get_user_sent_messages(): array {
         global $DB;

        $sql = "
            SELECT sms.id,
                sms.messageid,
                sms.ruleid,
                m.sending_settings,
                m.message
            FROM {local_taskflow_sent_messages} sms
            JOIN {local_taskflow_messages} m
                ON m.id = sms.messageid
            WHERE sms.userid = :userid
            AND m.class = :class
        ";

        $params = [
            'userid' => $this->userid,
            'class'  => 'onevent'
        ];

        $records = $DB->get_records_sql($sql, $params);
        $grouped = [];

        foreach ($records as $record) {
            $grouped[$record->ruleid][] = [
                'id'        => $record->id,
                'messageid'        => $record->messageid,
                'message'          => json_decode($record->message, true),
                'sending_settings' => json_decode($record->sending_settings, true),
            ];
        }

        return $grouped;
    }

    /**
     * Set all user assignemnts
     * @return array
     */
    private function get_user_assignments(): array {
        global $DB;
        $asssignments = [];
        $userassignments = $DB->get_records('local_taskflow_assignment', ['userid' => $this->userid], '', 'status, ruleid');
        foreach ($userassignments as $userassignment) {
            $asssignments[$userassignment->ruleid] = $userassignment->status;
        }
        return $asssignments;
    }

    /**
     * Set all user assignemnts
     * @param array $states
     * @return void
     */
    public function delete_all_not_matching_messages_with_status($states): void {
        global $DB;
        $deletetmessageids = [];
        foreach ($this->sentmessages as $ruleid => $sentrulemessages) {
            if (!in_array($this->userassignment[$ruleid], $states)) {
                foreach ($sentrulemessages as $sentmessage) {
                    if (array_intersect($states, $sentmessage['sending_settings']['eventlist'])){
                        $deletetmessageids[] = $sentmessage['id'];
                    }
                }
            }
        }
        if (!empty($deletetmessageids)) {
            $DB->delete_records_list('local_taskflow_sent_messages', 'id', $deletetmessageids);
        }
        return;
    }
}
