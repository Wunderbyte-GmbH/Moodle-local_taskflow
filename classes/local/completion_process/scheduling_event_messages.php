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
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\local\completion_process;

use local_taskflow\local\messages\messages_factory;
use stdClass;

/**
 * Class unit
 *
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scheduling_event_messages {
    /** @var stdClass Stores the external user data. */
    protected stdClass $assignmentrule;

    /** @var stdClass Stores the external user data. */
    protected stdClass $action;

    /**
     * Update the current unit.
     * @param stdClass $assignmentrule
     * @return void
     */
    public function __construct($assignmentrule) {
        $this->assignmentrule = $assignmentrule;
    }

    /**
     * Update the current unit.
     * @param string $status
     * @return void
     */
    public function schedule_event_messages($status) {
        $completionmessages  = $this->get_completion_messages();
        foreach ($completionmessages as $completionmessage) {
            $sendingsettings = json_decode($completionmessage->sending_settings);
            if ($sendingsettings->sendstart == $status) {
                $completionmessage->messageid = $completionmessage->id;
                $this->add_adhoc_task_to_db($completionmessage);
            }
        }
    }

    /**
     * Update the current unit.
     * @param stdClass $completionmessage
     * @return void
     */
    public function add_adhoc_task_to_db($completionmessage) {
        $assignmentmessageinstance = messages_factory::instance(
            $completionmessage,
            $this->assignmentrule->userid,
            $this->assignmentrule->ruleid
        );
        if (
            $assignmentmessageinstance != null &&
            !$assignmentmessageinstance->was_already_send()
        ) {
            $assignmentmessageinstance->shedule_message($this->action);
        }
    }

    /**
     * Update the current unit.
     * @return array
     */
    private function get_completion_messages() {
        global $DB;
        $messageids = $this->get_rule_messageids();
        [$sqlin, $paramsin] = $DB->get_in_or_equal($messageids, SQL_PARAMS_NAMED, 'mid');
        $paramsin['class'] = 'onevent';

        $sql = "SELECT *
                FROM {local_taskflow_messages}
                WHERE id $sqlin AND class = :class";

        return $DB->get_records_sql($sql, $paramsin);
    }

    /**
     * Update the current unit.
     * @return array
     */
    private function get_rule_messageids() {
        $messageids = [];
        $actions = $this->assignmentrule->rulejson->rulejson->rule->actions ?? [];
        foreach ($actions as $action) {
            $this->action = $action;
            foreach ($action->messages as $message) {
                $messageids[] = $message->messageid;
            }
        }
        return $messageids;
    }
}
