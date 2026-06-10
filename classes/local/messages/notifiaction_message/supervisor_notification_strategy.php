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

namespace local_taskflow\local\messages\notifiaction_message;

use html_writer;
use moodle_url;

/**
 * Notification strategy for assignees.
 *
 * @package local_taskflow
 */
class supervisor_notification_strategy implements notification_strategy {
    /**
     * {@inheritdoc}
     */
    public function get_message_provider(): string {
        return 'supervisornotification';
    }

    /**
     * Returns a list of user IDs who should receive this message.
     *
     * @param int   $userid        Base user ID
     * @param array $notifications Assignment IDs grouped by type
     * @return int[]
     */
    public function get_recipients(int $userid, array $notifications): array {
        return [$userid];
    }

    /**
     * Builds the notification message body.
     *
     * @param array $records Assignment records
     * @return string HTML message body
     */
    public function build_email_body(array $records): string {
        if (empty($records)) {
            return '';
        }

        $items = [];

        foreach ($records as $record) {
            $assigneename = $record->firstname . ' ' . $record->lastname;

            $url = new moodle_url(
                '/local/taskflow/editassignment.php',
                ['id' => $record->assignmentid]
            );

            $items[] = html_writer::tag(
                'li',
                format_string($record->rulename) . ' – ' .
                s($assigneename) . ' – ' .
                html_writer::link(
                    $url,
                    get_string('editassignment', 'local_taskflow')
                )
            );
        }

        $preamble = html_writer::tag('p', get_string('notificationmessagepreamble', 'local_taskflow'));
        return $preamble . html_writer::tag('ul', implode("\n", $items));
    }

    /**
     * Builds the plain-text small message for popup notifications.
     *
     * @param array $records Assignment records
     * @return string Plain-text message, no HTML tags
     */
    public function build_notification_body(array $records): string {
        if (empty($records)) {
            return '';
        }
        $lines = [get_string('notificationmessagepreamble', 'local_taskflow')];
        foreach ($records as $record) {
            $assigneename = $record->firstname . ' ' . $record->lastname;
            $lines[] = format_string($record->rulename) . ' – ' . s($assigneename);
        }
        return implode("\n", $lines);
    }
}
