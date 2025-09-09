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

namespace local_taskflow\task;

use local_taskflow\local\messages\messages_factory;

/**
 * Class send_taskflow_message
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_taskflow_message extends \core\task\adhoc_task {
    /**
     * Execute sending messags function
     * @return void
     */
    public function execute() {
        global $DB;

        $data = (object) $this->get_custom_data();
        $message = $DB->get_record('local_taskflow_messages', ['id' => $data->messageid]);
        if (empty($message)) {
            return;
        }

        $message->messagetype = $message->class;
        $message->messageid = $message->id;

        $assignmentmessageinstance = messages_factory::instance(
            $message,
            $data->userid,
            $data->ruleid
        );

        if (
            $assignmentmessageinstance !== null &&
            $assignmentmessageinstance->assignment !== null &&
            !$assignmentmessageinstance->was_already_send() &&
            $assignmentmessageinstance->is_still_valid()
        ) {
            $assignmentmessageinstance->send_and_save_message();
        }
    }
}
