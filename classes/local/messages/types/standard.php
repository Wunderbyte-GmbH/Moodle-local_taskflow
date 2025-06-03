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
use local_taskflow\local\messages\message_sending_time;
use local_taskflow\local\messages\messages_interface;
use local_taskflow\local\messages\placeholders\placeholders_factory;
use local_taskflow\sheduled_tasks\send_taskflow_message;
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
        $messagedata = $DB->get_record('local_taskflow_messages', ['id' => $this->message->messageid]);
        $messagedata->message = json_decode($messagedata->message ?? '');
        if (placeholders_factory::has_placeholders($messagedata->message)) {
            $messagedata = placeholders_factory::render_placeholders(
                $messagedata,
                $this->ruleid,
                $this->userid
            );
        }
        $eventdata = new \core\message\message();
        $eventdata->component = 'local_taskflow';
        $eventdata->name = 'notificationmessage';
        $eventdata->userfrom = \core_user::get_noreply_user();
        $eventdata->userto = $this->userid;
        $eventdata->subject = $messagedata->message->heading ?? 'Taskflow notification';
        $eventdata->fullmessage = $messagedata->message->body ?? '';
        $eventdata->fullmessageformat = FORMAT_MARKDOWN;
        $eventdata->fullmessagehtml = $messagedata->message->body ?? '';
        $eventdata->smallmessage = $messagedata->message->body ?? '';
        $eventdata->notification = 1;
        return message_send($eventdata);
    }

    /**
     * Factory for the organisational units
     * @param stdClass $action
     * @return void
     */
    public function shedule_message($action) {
        global $DB;
        $task = new send_taskflow_message();

        $customdata = [
            'userid' => $this->userid,
            'messageid' => $this->message->messageid,
            'ruleid' => $this->ruleid,
        ];

        $this->delete_old_sheduled_messages($customdata);

        $task->set_custom_data($customdata);
        // OPEN: calculate sending time.
        $messagesendingtime = new message_sending_time($this->message, $action);
        $task->set_next_run_time($messagesendingtime->calaculate_sending_time());
        manager::queue_adhoc_task($task);
    }

    /**
     * Factory for the organisational units
     * @param array $customdata
     */
    private function delete_old_sheduled_messages($customdata) {
        global $DB;
        $encodeddata = json_encode($customdata);

        $sql = "SELECT *
                FROM {task_adhoc}
                WHERE component = :component
                AND classname = :classname
                AND " . $DB->sql_compare_text('customdata') . " = :customdata";

        $params = [
            'component' => 'local_taskflow',
            'classname' => '\local_taskflow\sheduled_tasks\send_taskflow_message',
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
        return $DB->get_record(self::TABLENAME, [
            'message_id' => $this->message->messageid,
            'rule_id' => $this->ruleid,
            'user_id' => $this->userid,
        ]);
    }

    /**
     * Factory for the organisational units
     * @return int
     */
    private function insert_sent_message() {
        global $DB;
        return $DB->insert_record(self::TABLENAME, (object)[
            'message_id' => $this->message->messageid,
            'rule_id' => $this->ruleid,
            'user_id' => $this->userid,
            'timesent' => time(),
        ]);
    }
}
