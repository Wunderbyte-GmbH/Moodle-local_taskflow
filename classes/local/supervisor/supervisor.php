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

namespace local_taskflow\local\supervisor;

use stdClass;

/**
 * Class unit
 *
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class supervisor {
    /** @var int $supervisorid */
    private $supervisorid;

    /** @var int $userid */
    private $userid;

    /**
     * Private constructor to prevent direct instantiation.
     * @param int $supervisorid The record from the database.
     * @param int $userid The record from the database.
     */
    public function __construct(int $supervisorid, int $userid) {
        $this->supervisorid = $supervisorid;
        $this->userid = $userid;
    }

    /**
     * Get the instance of the class for a specific ID.
     * @return void
     */
    public function set_supervisor_for_user() {
        global $DB;

        $fieldname = get_config('local_taskflow', 'supervisor_field');
        if (empty($fieldname)) {
            return null;
        }

        $fieldid = $DB->get_field('user_info_field', 'id', ['shortname' => $fieldname], IGNORE_MISSING);
        if (empty($fieldid)) {
            return;
        }

        if ($record = $this->does_exist($fieldid)) {
            $this->update_customfield($record->id);
        } else {
            $this->create_customfield($fieldid);
        }
    }

    /**
     * Get the instance of the class for a specific ID.
     * @param $userid
     * @return stdClass
     */
    public static function get_supervisor_for_user(int $userid) {
        global $DB;

        $fieldname = get_config('local_taskflow', 'supervisor_field');
        if (empty($fieldname)) {
            return (object)[];
        }

        $sql = "SELECT su.*
                FROM {user} u
                JOIN {user_info_data} uid ON uid.userid = u.id
                JOIN {user_info_field} uif ON uif.id = uid.fieldid
                JOIN {user} su ON su.id = CAST(uid.data AS INT)
                WHERE u.id = :userid
                AND uif.shortname = :supervisor";
        $parms = [
            'userid' => $userid,
            'supervisor' => $fieldname,
        ];
        return $DB->get_record_sql($sql, $parms, IGNORE_MISSING);
    }

    /**
     * Get the instance of the class for a specific ID.
     * @param int $fieldid
     * @return stdClass
     */
    public function does_exist($fieldid) {
        global $DB;
        return $DB->get_record('user_info_data', [
            'userid' => (string)$this->userid,
            'fieldid' => $fieldid,
        ]);
    }

    /**
     * Get the instance of the class for a specific ID.
     * @param int $id
     * @return void
     */
    public function update_customfield($id) {
        global $DB;
        $data = (object)[
            'id' => $id,
            'userid'  => (string)$this->userid,
            'data'    => (string)$this->supervisorid,
        ];
        $DB->update_record('user_info_data', $data);
    }

    /**
     * Get the instance of the class for a specific ID.
     * @param int $fieldid
     * @return void
     */
    public function create_customfield($fieldid) {
        global $DB;
        $data = (object)[
            'userid'  => (string)$this->userid,
            'fieldid' => $fieldid,
            'data'    => (string)$this->supervisorid,
        ];
        $DB->insert_record('user_info_data', $data);
    }
}
