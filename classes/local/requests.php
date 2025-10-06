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

namespace local_taskflow\local;

/**
 * Class requests
 *
 * @package    local_taskflow
 * @copyright  2025 Georg Maißer <georg.maißer@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class requests {
    /** @var string Table name */
    protected static $table = 'local_taskflow_requests';

    /**
     * Create a new request entry.
     *
     * @param int $requesttype
     * @param int $userid
     * @param int $assignmentid
     * @param int $status
     * @param int $usermodified
     * @return int New record ID
     * @throws \dml_exception
     */
    public static function create(
        int $requesttype,
        int $userid,
        int $assignmentid,
        int $status = 0,
        int $usermodified = 0
    ): int {
        global $DB, $USER;

        $record = new \stdClass();
        $record->request = $requesttype;
        $record->assignmentid = $assignmentid;
        $record->userid = $userid;
        $record->status = $status;
        $record->usermodified = $usermodified ?: $USER->id;
        $record->timecreated = time();
        $record->timemodified = time();

        return $DB->insert_record(self::$table, $record);
    }

    /**
     * Get a request by ID.
     *
     * @param int $id
     * @return \stdClass|null
     * @throws \dml_exception
     */
    public static function get(int $id): ?\stdClass {
        global $DB;

        return $DB->get_record(self::$table, ['id' => $id], '*', IGNORE_MISSING);
    }

    /**
     * Update an existing request.
     *
     * @param int $id
     * @param array $data Fields to update
     * @param int|null $usermodified
     * @return bool True on success
     * @throws \dml_exception
     */
    public static function update(int $id, array $data, ?int $usermodified = null): bool {
        global $DB, $USER;

        if (!$DB->record_exists(self::$table, ['id' => $id])) {
            return false;
        }

        $record = $DB->get_record(self::$table, ['id' => $id]);
        foreach ($data as $field => $value) {
            if (property_exists($record, $field)) {
                $record->$field = $value;
            }
        }

        $record->usermodified = $usermodified ?? $USER->id;
        $record->timemodified = time();

        return $DB->update_record(self::$table, $record);
    }

    /**
     * Delete a request entry.
     *
     * @param int $id
     * @return bool
     * @throws \dml_exception
     */
    public static function delete(int $id): bool {
        global $DB;

        if (!$DB->record_exists(self::$table, ['id' => $id])) {
            return false;
        }
        return $DB->delete_records(self::$table, ['id' => $id]);
    }

    /**
     * Get all requests for a user.
     *
     * @param int $userid
     * @param int|null $status
     * @return array of \stdClass records
     * @throws \dml_exception
     */
    public static function get_by_user(int $userid, ?int $status = null): array {
        global $DB;

        $params = ['userid' => $userid];
        $where = 'userid = :userid';

        if ($status !== null) {
            $where .= ' AND status = :status';
            $params['status'] = $status;
        }

        return $DB->get_records_select(self::$table, $where, $params, 'timecreated DESC');
    }
}
