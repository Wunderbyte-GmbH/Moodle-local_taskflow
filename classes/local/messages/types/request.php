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
use core_user;
use local_taskflow\local\messages\message_base;
use local_taskflow\local\messages\message_sending_time;
use local_taskflow\local\messages\message_recipient;
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
class request extends message_base {
    /** @var string */
    public const TYPE = 'request';

    /** @var string */
    public const TITLE = 'Request';

    /** @var string The assignment associated with the message. */
    public string $requestid;


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
        // Change recipient.
        $request = $DB->get_record(
            'local_taskflow_requests',
            ['id' => $this->requestid],
            'treated, forhr'
        );
        if ($request->treated != 0) {
            // Send message to assigned user.
            $user = core_user::get_user($this->assignment->userid, '*', MUST_EXIST);
            $recepientlist = [$user];
        } else {
            // Send message to request administrator.
            $recepientlist = receiver_facade::get_request_receiver($request->forhr, $this->assignment);
        }
        $recipientoperator = new message_recipient($this->userid, $messagedata);
        if (empty($recepientlist)) {
            return;
        }

        $ccmaillist = $recipientoperator->get_carbon_copy();
        $this->send_email_with_cc($recepientlist, $ccmaillist, $messagedata);
        $this->send_internal_notifications($recepientlist, $messagedata);
        $this->log_message_in_history();
        cache_helper::purge_by_event('changesinassignmentslist');
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
            'requestid' => $action->requestid,
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

    /**
     * Factory for the organisational units
     * @param string $requestid
     * @return void
     */
    public function set_request_id($requestid) {
        $this->requestid = $requestid;
        return;
    }
}
