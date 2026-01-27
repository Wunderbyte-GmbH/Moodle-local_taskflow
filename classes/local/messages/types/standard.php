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

use cache_helper;
use core\task\manager;
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\messages\message_base;
use local_taskflow\local\messages\message_sending_time;
use local_taskflow\local\messages\message_recipient;
use local_taskflow\local\messages\placeholders\placeholders_factory;
use local_taskflow\task\send_taskflow_message;
use stdClass;

/**
 * Class unit
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class standard extends message_base {
    /** @var string */
    public const TYPE = 'standard';

    /** @var string */
    public const TITLE = 'Standard';

    /**
     * Check if the message was already sent.
     * @return bool
     */
    public function was_already_send() {
        if (
            $this->get_sent_message() &&
            !$this->is_multiple_manual()
        ) {
            return true;
        }
        return false;
    }

    /**
     * Check if is still valid.
     * @return bool
     */
    public function is_still_valid() {
        $sendingsettings = json_decode($this->message->sending_settings);
        if ($sendingsettings->sendstart != 'status_change') {
            switch ($this->assignment->status ?? assignment_status_facade::get_status_identifier('completed')) {
                case assignment_status_facade::get_status_identifier('completed'):
                case assignment_status_facade::get_status_identifier('droppedout'):
                case assignment_status_facade::get_status_identifier('paused'):
                case assignment_status_facade::get_status_identifier('notrelevant'):
                    return false;
                default:
                    return true;
            }
        }
        if (is_string($sendingsettings->eventlist)) {
            $sendingsettings->eventlist = json_decode($sendingsettings->eventlist);
        }
        return in_array($this->assignment->status, $sendingsettings->eventlist);
    }

    /**
     * Send and save message.
     * @return void
     */
    public function send_and_save_message() {
        $this->send_message();
        $this->insert_sent_message();
        return;
    }

    /**
     * Send message.
     * @return void
     */
    protected function send_message() {
        global $DB;
        $messagedata = $this->message;
        if (placeholders_factory::has_placeholders((array)$this->message->message)) {
            $messagedata = placeholders_factory::render_placeholders(
                $this->message,
                $this->ruleid,
                $this->userid,
                $this->assignment
            );
        }
        $recipientoperator = new message_recipient($this->userid, $messagedata);
        $recepientlist = $recipientoperator->get_recepient();
        if (empty($recepientlist)) {
            return;
        }

        $ccmaillist = $recipientoperator->get_carbon_copy();
        $this->send_single_mail_with_cc($recepientlist, $ccmaillist, $messagedata);
        $this->send_internal_notifications($recepientlist, $messagedata);
        $this->log_message_in_history($messagedata->message);
        cache_helper::purge_by_event('changesinassignmentslist');
        return;
    }

    /**
     * Factory for the organisational units
     * @return bool
     */
    public function is_scheduled_type() {
        if ($this->message->class == 'standard') {
            return true;
        }
        return false;
    }

    /**
     * Factory for the organisational units
     * @param stdClass $action
     * @param mixed $newassignment
     * @return void
     */
    public function schedule_message($action, $newassignment = null) {
        global $DB;
        $task = new send_taskflow_message();

        $customdata = [
            'userid' => $this->userid,
            'messageid' => $this->message->id,
            'ruleid' => $this->ruleid,
            'manualchanged' => $this->manualchanged,
        ];

        $this->delete_old_scheduled_messages($customdata);

        $task->set_custom_data($customdata);
        $messagesendingtime = new message_sending_time($this->message, $action);
        if (empty($this->assignment) && empty($newassignment)) {
            return;
        }

        if (!empty($newassignment)) {
            $nextruntime = $messagesendingtime->calaculate_sending_time($newassignment);
        } else {
            $nextruntime = $messagesendingtime->calaculate_sending_time($this->assignment);
        }
        if ($nextruntime) {
            $task->set_next_run_time($nextruntime);
            manager::queue_adhoc_task($task);
        }
    }

    /**
     * Factory for the organisational units
     * @return array
     */
    private function get_sent_message() {
        global $DB;
        $records = $DB->get_records(self::TABLENAME, [
            'messageid' => $this->message->id,
            'ruleid' => $this->ruleid,
            'userid' => $this->userid,
        ]);
        return array_shift($records);
    }

    /**
     * Checks if message is manual and multiple is active
     * @return bool
     */
    private function is_multiple_manual() {
        return $this->manualchanged && get_config('local_taskflow', 'sendmanualmailsmultipletimes');
    }
}
