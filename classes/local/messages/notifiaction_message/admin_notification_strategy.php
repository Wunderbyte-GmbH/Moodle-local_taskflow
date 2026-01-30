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
class admin_notification_strategy implements notification_strategy {
    /**
     * {@inheritdoc}
     */
    public function get_message_provider(): string {
        return 'adminnotification';
    }

    /**
     * {@inheritdoc}
     */
    public function get_recipients(int $userid, array $notifications): array {
        // Admin recipients are handled externally (get_admins()).
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function build_message_body(array $records): string {
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

        return html_writer::tag('ul', implode("\n", $items));
    }
}
