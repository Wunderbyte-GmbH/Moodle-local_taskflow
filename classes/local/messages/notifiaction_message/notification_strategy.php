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

/**
 * Class unit
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface notification_strategy {
    /**
     * Returns the Moodle message provider name.
     *
     * @return string
     */
    public function get_message_provider(): string;

    /**
     * Builds the HTML notification message body (used for email/fullmessage).
     *
     * @param array $records Assignment records
     * @param string $lang User's language preference
     * @return string HTML message body
     */
    public function build_email_body(array $records, string $lang): string;

    /**
     * Builds the plain-text small message (used for popup/bell notifications).
     *
     * @param array $records Assignment records
     * @param string $lang User's language preference
     * @return string Plain-text message, no HTML tags
     */
    public function build_notification_body(array $records, string $lang): string;

    /**
     * Returns a list of user IDs who should receive this message.
     *
     * @param int   $userid        Base user ID
     * @param array $notifications Assignment IDs grouped by type
     * @return int[]
     */
    public function get_recipients(int $userid, array $notifications): array;
}
