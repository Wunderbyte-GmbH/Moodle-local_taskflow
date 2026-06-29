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

use core\message\message;
use local_taskflow\local\history\history;
use local_taskflow\local\messages\messages_interface;
use local_taskflow\taskflow_stringmanager;
use stdClass;

/**
 * Class unit
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class message_base implements messages_interface {
    /** @var string */
    protected const TABLENAME = 'local_taskflow_sent_messages';

    /** @var stdClass The entire DB record of the message. */
    public stdClass $message;

    /** @var int The user ID associated with the message. */
    public int $userid;

    /** @var int The rule ID associated with the message. */
    public int $ruleid;

    /** @var bool Indication if changes were made manually or not. */
    public bool $manualchanged;

    /** @var mixed The assignment associated with the message. */
    public mixed $assignment;

    /**
     * Factory for the message.
     * @param stdClass $message
     * @param int $userid
     * @param int $ruleid
     * @param bool $manualchanged
     */
    public function __construct($message, $userid, $ruleid, $manualchanged = false) {
        $this->message = $this->set_message($message);
        $this->userid = $userid;
        $this->ruleid = $ruleid;
        $this->manualchanged = $manualchanged;
        $this->assignment = $this->set_assignment();
    }

    /**
     * Set the assignment.
     * @param stdClass $message
     * @return stdClass
     */
    private function set_message($message) {
        $message->message = json_decode($message->message ?? '["body": ""]', false);
        if ($message->message) {
            foreach ($message->message as &$messagepart) {
                if (isset($messagepart->text)) {
                    $messagepart = $messagepart->text;
                }
                $messagepart = html_entity_decode(
                    $messagepart,
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                );
            }
        }
        return $message;
    }

    /**
     * Set the assignment.
     * @return mixed
     */
    private function set_assignment() {
        global $DB;
        $records = $DB->get_records('local_taskflow_assignment', [
            'userid' => $this->userid,
            'ruleid' => $this->ruleid,
        ]);

        if (count($records) === 1) {
            return reset($records);
        }
        return null;
    }

    /**
     * Send email to primary recipients and separate direct emails to CC recipients.
     * CC recipients receive an individual email with a translated copy prefix in the subject.
     * All validation (deleted, suspended, bounce threshold, diversion) is handled by email_to_user().
     * @param array $recipientlist
     * @param array $ccemails
     * @param stdClass $messagedata
     * @return void
     */
    protected function send_email_with_cc(array $recipientlist, array $ccemails, stdClass $messagedata): void {
        $fromuser = \core_user::get_noreply_user();
        $subject = $messagedata->message->heading ?? 'Taskflow notification';
        $body = $messagedata->message->body ?? '';
        $bodytext = html_to_text($body);

        $isvaliduser = fn($u) => is_object($u) && !empty($u->id) && !empty($u->email) && validate_email($u->email);
        $recipientlist = array_values(array_filter($recipientlist, $isvaliduser));
        $ccemails = array_values(array_filter($ccemails, $isvaliduser));

        if (empty($recipientlist)) {
            return;
        }

        $ccnames = implode(', ', array_map('fullname', $ccemails));
        $recipientnames = implode(', ', array_map('fullname', $recipientlist));

        $primarysubject = !empty($ccemails)
            ? taskflow_stringmanager::get_string('emailsubjectwithccsuffix', (object)['subject' => $subject, 'names' => $ccnames])
            : $subject;
        foreach ($recipientlist as $user) {
            email_to_user($user, $fromuser, $primarysubject, $bodytext, $body);
        }

        if (!empty($ccemails)) {
            $a = (object)['subject' => $subject, 'names' => $recipientnames];
            $ccsubject = taskflow_stringmanager::get_string('emailsubjectcopyprefix', $a);
            $bodytext = taskflow_stringmanager::get_string('ccemailbody', $a) . $bodytext;
            foreach ($ccemails as $ccuser) {
                email_to_user($ccuser, $fromuser, $ccsubject, html_to_text($bodytext), $bodytext);
            }
        }
    }

    /**
     * Send internal notifications.
     * @param array $recepientlist
     * @param stdClass $messagedata
     * @return void
     */
    protected function send_internal_notifications(array $recepientlist, stdClass $messagedata): void {
        $fromuser = \core_user::get_noreply_user();
        $subject = $messagedata->message->heading ?? 'Taskflow notification';
        $body = $messagedata->message->body ?? '';

        foreach ($recepientlist as $recipient) {
            if (!is_object($recipient) || empty($recipient->id)) {
                continue;
            }

            $eventdata = new message();
            $eventdata->component         = 'local_taskflow';
            $eventdata->name              = 'notificationmessage';
            $eventdata->userfrom          = $fromuser;
            $eventdata->userto            = $recipient->id;
            $eventdata->subject           = $subject;
            $eventdata->fullmessage       = html_to_text($body);
            $eventdata->fullmessageformat = FORMAT_HTML;
            $eventdata->fullmessagehtml   = $body;
            $eventdata->smallmessage      = strip_tags($subject);
            $eventdata->notification      = 1;
            if (\core_message\api::can_send_message($recipient->id, $fromuser->id)) {
                message_send($eventdata);
            };
        }
    }

    /**
     * Factory for the organisational units
     * @return void
     */
    protected function log_message_in_history() {
        global $USER, $DB;
        history::log(
            $this->assignment->id ?? 0,
            $USER->id,
            history::TYPE_MAIL_SEND,
            [
                'action' => 'mail_send',
                'data' => $this->message->name,
            ],
            $this->message->usermodified ?? null
        );
        return;
    }

    /**
     * Factory for the organisational units
     * @param array $customdata
     */
    protected function delete_old_scheduled_messages($customdata) {
        global $DB;
        $encodeddata = json_encode($customdata);

        $sql = "SELECT *
                FROM {task_adhoc}
                WHERE component = :component
                AND classname = :classname
                AND " . $DB->sql_compare_text('customdata') . " = :customdata";

        $params = [
            'component' => 'local_taskflow',
            'classname' => '\local_taskflow\task\send_taskflow_message',
            'customdata' => $encodeddata,
        ];

        $tasks = $DB->get_records_sql($sql, $params);

        foreach ($tasks as $task) {
            $DB->delete_records('task_adhoc', ['id' => $task->id]);
        }
    }

    /**
     * Factory for the organisational units
     * @return int
     */
    protected function insert_sent_message() {
        global $DB;
        return $DB->insert_record(self::TABLENAME, (object)[
            'messageid' => $this->message->id,
            'ruleid' => $this->ruleid,
            'userid' => $this->userid,
            'timesent' => time(),
        ]);
    }
}
