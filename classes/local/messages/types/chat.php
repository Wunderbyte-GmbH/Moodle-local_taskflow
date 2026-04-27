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

use core\task\manager;
use local_taskflow\local\messages\message_base;
use local_taskflow\local\messages\message_sending_time;
use local_taskflow\local\messages\placeholders\placeholders_factory;
use local_taskflow\local\requests\request_receivers\receiver_facade;
use local_taskflow\task\send_taskflow_message;
use stdClass;

/**
 * Class unit
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chat extends message_base {
    /** @var string */
    public const TYPE = 'chat';

    /** @var string */
    public const TITLE = 'Chat';

    /** @var string The assignment associated with the message. */
    public string $assignmentid;

    /** @var stdClass Additional data for message. */
    private stdClass $additional;

    /**
     * Check if the message was already sent.
     * @return bool
     */
    public function was_already_send() {
        return false;
    }

    /**
     * Check if is still valid.
     * @return bool
     */
    public function is_still_valid() {
        return true;
    }

    /**
     * Send and save message.
     * @param stdClass $additional
     * @return void
     */
    public function set_additional_data($additional) {
        $this->additional = $additional;
        return;
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
        $messagedata = $this->message;
        if (placeholders_factory::has_placeholders((array)$this->message->message)) {
            $messagedata = placeholders_factory::render_placeholders(
                $this->message,
                $this->ruleid,
                $this->userid,
                $this->assignment
            );
        }
        // Change recipient.
        $receiver = receiver_facade::get_chat_receiver(
            $this->assignment->userid,
            $this->additional->sender
        );

        if (!$receiver) {
            return;
        }
        $fromuser = \core_user::get_noreply_user();

        $eventdata = new \core\message\message();
        $eventdata->component         = 'local_taskflow';
        $eventdata->name              = 'notificationmessage';
        $eventdata->userfrom          = $fromuser;
        $eventdata->userto            = $receiver;
        $eventdata->subject           = $messagedata->message->heading;
        $eventdata->fullmessage       = $messagedata->message->body;
        $eventdata->fullmessageformat = FORMAT_HTML;
        $eventdata->fullmessagehtml   = $messagedata->message->body;
        $eventdata->smallmessage      = $messagedata->message->body;
        $eventdata->notification      = 0;
        if (\core_message\api::can_send_message($receiver->id, $fromuser->id)) {
            message_send($eventdata);
        };
        return;
    }

    /**
     * Factory for the organisational units
     * @return bool
     */
    public function is_scheduled_type() {
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
            'other' => $action->other,
        ];

        $this->delete_old_scheduled_messages($customdata);

        $task->set_custom_data($customdata);
        $messagesendingtime = new message_sending_time($this->message, $action);
        if (empty($this->assignment) && empty($newassignment)) {
            return;
        }
        if (!empty($newassignment)) {
            $task->set_next_run_time($messagesendingtime->calaculate_sending_time($newassignment));
        } else {
            $task->set_next_run_time($messagesendingtime->calaculate_sending_time($this->assignment));
        }
        manager::queue_adhoc_task($task);
    }
}
