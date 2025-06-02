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
 * Form to create rules.
 *
 * @package   local_taskflow
 * @copyright 2025
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\form\messages;

use local_taskflow\form\messages\form_interface;

/**
 * Demo step 1 form.
 */
class form_messages implements form_interface {
    /** @var string */
    private const TABLENAME = 'local_taskflow_messages';

    /**
     * Definition.
     * @return array
     */
    public function get_form_data(): array {
        global $DB;
        $records = $DB->get_records(
            self::TABLENAME,
            null,
            '',
            'id, message'
        );

        $messages = [];
        foreach ($records as $record) {
            $message = json_decode($record->message);
            $messages[$record->id] = $message->heading;
        }
        return $messages;
    }

    /**
     * Definition.
     * @return array
     */
    public function get_messages_from_package($packageid): array {
        global $DB;
        $sql = "
            SELECT m.id
            FROM {local_taskflow_messages} m
            JOIN {local_taskflow_packages_messages} pm ON pm.message_id = m.id
            WHERE pm.package_id = :packageid
        ";

        $records = $DB->get_records_sql($sql, ['packageid' => $packageid]);

        $messages = [];
        foreach ($records as $record) {
            $messages[] = $record->id;
        }

        return $messages;
    }
}
