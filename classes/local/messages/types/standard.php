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
use local_taskflow\local\assignments\status\assignment_status;
use local_taskflow\local\history\history;
use local_taskflow\local\messages\message_sending_time;
use local_taskflow\local\messages\message_recipient;
use local_taskflow\local\messages\messages_interface;
use local_taskflow\local\messages\placeholders\placeholders_factory;
use local_taskflow\scheduled_tasks\send_taskflow_message;
use stdClass;

/**
 * Class unit
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class standard implements messages_interface {
    /** @var string */
    private const TABLENAME = 'local_taskflow_sent_messages';

    /** @var stdClass Event name for user updated. */
    public stdClass $message;

    /** @var int Event name for user updated. */
    public int $userid;

    /** @var int Event name for user updated. */
    public int $ruleid;

    /** @var mixed Event name for user updated. */
    public mixed $assignment;

    /**
     * Factory for the organisational units
     * @param stdClass $message
     * @param int $userid
     * @param int $ruleid
     */
    public function __construct($message, $userid, $ruleid) {
        $this->message = $message;
        $this->userid = $userid;
        $this->ruleid = $ruleid;
        $this->assignment = $this->set_assignment();
    }

    /**
     * Factory for the organisational units
     * @return mixed
     */
    public function set_assignment() {
        global $DB;
        $records = $DB->get_records('local_taskflow_assignment', [
            'userid' => $this->userid,
            'ruleid' => $this->ruleid,
            'active' => 1,
        ]);

        if (count($records) === 1) {
            return reset($records);
        }
        return null;
    }

    /**
     * Factory for the organisational units
     * @return bool
     */
    public function was_already_send() {
        if ($this->get_sent_message()) {
            return true;
        }
        return false;
    }

    /**
     * Factory for the organisational units
     * @return bool
     */
    public function is_still_valid() {
        switch ($this->assignment->status ?? '0') {
            case assignment_status::STATUS_COMPLETED:
                return $this->send_only_messages_after_completion();
            default:
                break;
        }
        return true;
    }

    /**
     * Factory for the organisational units
     * @return bool
     */
    public function send_only_messages_after_completion() {
        $sendingsettings = json_decode($this->message->sending_settings);
        $eventlist = json_decode($sendingsettings->eventlist ?? '');
        if (
            $sendingsettings->sendstart == 'status_change' &&
            in_array(assignment_status::STATUS_COMPLETED, $eventlist)
        ) {
            return true;
        }
        return false;
    }

    /**
     * Factory for the organisational units
     * @return void
     */
    public function send_and_save_message() {
        $this->send_message();
        $this->insert_sent_message();
        return;
    }

    /**
     * Factory for the organisational units
     * @return void
     */
    protected function send_message() {
        global $DB;
        $this->message->message = json_decode($this->message->message ?? '');
        $messagedata = $this->message;
        if (placeholders_factory::has_placeholders($this->message->message)) {
            $messagedata = placeholders_factory::render_placeholders(
                $this->message,
                $this->ruleid,
                $this->userid,
                $this->assignment
            );
        }
        $recipientoperator = new message_recipient($this->userid, $messagedata);
        $recepient = $recipientoperator->get_recepient();
        if (empty($recepient)) {
            return;
        }

        if (is_numeric($recepient)) {
            $this->send_mail_with_id($recepient, $messagedata);
        } else if (filter_var($recepient, FILTER_VALIDATE_EMAIL)) {
            $this->send_mail_with_mail($recepient, $messagedata);
        }

        $this->log_message_in_history($messagedata->message);
        cache_helper::purge_by_event('changesinassignmentslist');
        return;
    }

    /**
     * Factory for the organisational units
     * @param string $recipientmail
     * @param stdClass $messagedata
     * @return void
     */
    private function send_mail_with_mail($recipientmail, $messagedata) {
        $fakeuser = (object)[
            'id' => -1,
            'email' => $recipientmail,
            'firstname' => 'Personal',
            'lastname' => 'Admin',
            'maildisplay' => true,
            'firstnamephonetic' => '',
            'lastnamephonetic' => '',
            'middlename' => '',
            'alternatename' => '',
            'username' => 'Personal Admin',
        ];
        $fromuser = \core_user::get_noreply_user();
        $body = $messagedata->message->body ?? '';

        email_to_user(
            $fakeuser,
            $fromuser,
            $messagedata->message->heading ?? 'Taskflow notification',
            $body,
            nl2br($body)
        );
        return;
    }

    /**
     * Factory for the organisational units
     * @param string $recipientid
     * @param stdClass $messagedata
     * @return void
     */
    private function send_mail_with_id($recipientid, $messagedata) {
        $body = $messagedata->message->body ?? '';
        $eventdata = new \core\message\message();
        $eventdata->component = 'local_taskflow';
        $eventdata->name = 'notificationmessage';
        $eventdata->userfrom = \core_user::get_noreply_user();
        $eventdata->userto = $recipientid;
        $eventdata->subject = $messagedata->message->heading ?? 'Taskflow notification';
        $eventdata->fullmessage = $body;
        $eventdata->fullmessageformat = FORMAT_MARKDOWN;
        $eventdata->fullmessagehtml = nl2br($body);
        $eventdata->smallmessage = shorten_text($body, 100);
        $eventdata->notification = 1;
        message_send($eventdata);
        return;
    }

    /**
     * Factory for the organisational units
     * @param stdClass $message
     * @return void
     */
    protected function log_message_in_history($message) {
        global $USER, $DB;

        history::log(
            $this->assignment->id ?? 0,
            $USER->id,
            history::TYPE_MAIL_SEND,
            [
                'action' => 'mail_send',
                'data' => $message,
            ],
            $data['usermodified'] ?? null
        );
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
     * @return void
     */
    public function schedule_message($action) {
        global $DB;
        $task = new send_taskflow_message();

        $customdata = [
            'userid' => $this->userid,
            'messageid' => $this->message->id,
            'ruleid' => $this->ruleid,
        ];

        $this->delete_old_scheduled_messages($customdata);

        $task->set_custom_data($customdata);
        $messagesendingtime = new message_sending_time($this->message, $action);
        $task->set_next_run_time($messagesendingtime->calaculate_sending_time($this->assignment));
        manager::queue_adhoc_task($task);
    }

    /**
     * Factory for the organisational units
     * @param array $customdata
     */
    private function delete_old_scheduled_messages($customdata) {
        global $DB;
        $encodeddata = json_encode($customdata);

        $sql = "SELECT *
                FROM {task_adhoc}
                WHERE component = :component
                AND classname = :classname
                AND " . $DB->sql_compare_text('customdata') . " = :customdata";

        $params = [
            'component' => 'local_taskflow',
            'classname' => '\local_taskflow\scheduled_tasks\send_taskflow_message',
            'customdata' => $encodeddata,
        ];

        $tasks = $DB->get_records_sql($sql, $params);

        foreach ($tasks as $task) {
            $DB->delete_records('task_adhoc', ['id' => $task->id]);
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
     * Factory for the organisational units
     * @return int
     */
    private function insert_sent_message() {
        global $DB;
        return $DB->insert_record(self::TABLENAME, (object)[
            'messageid' => $this->message->id,
            'ruleid' => $this->ruleid,
            'userid' => $this->userid,
            'timesent' => time(),
        ]);
    }
}
