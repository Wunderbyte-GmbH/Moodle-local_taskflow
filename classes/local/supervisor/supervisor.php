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

use Exception;
use local_taskflow\local\external_adapter\external_api_base;
use local_taskflow\plugininfo\taskflowadapter;
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
     * [Description for set_supervisor_for_user]
     *
     * @param int $supervisorid
     * @param string $shortname
     * @param stdClass $user
     * @param array $users
     *
     * @return void
     *
     */
    public function set_supervisor_for_user(int $supervisorid, string $shortname, stdClass $user, array $users) {
        global $DB;
        if (isset($users[$supervisorid])) {
            $supervisor = $users[$supervisorid];
            $user->profile[$shortname] = $supervisor->id;

            $supervisorroleid = get_config('local_taskflow', 'supervisorrole');
            $context = \context_system::instance();
            // Check if the user already has the role in that context.
            if (
                !empty($supervisorroleid)
                && is_numeric($supervisorroleid)
                && !user_has_role_assignment($supervisor->id, $supervisorroleid, $context->id)
            ) {
                role_assign($supervisorroleid, $supervisor->id, $context->id);
            }
        }
    }

    /**
     * Get the instance of the class for a specific ID.
     * @param int $userid
     * @return stdClass
     */
    public static function get_supervisor_for_user(int $userid) {
        $subplugin = get_config('local_taskflow', 'external_api_option');
        try {
            $class = "taskflowadapter_$subplugin\\taskflowadapter_$subplugin";
            return $class::get_supervisor_for_user($userid);
        } catch (Exception $e) {
             return taskflowadapter::get_supervisor_for_user($userid);
        }
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

    /**
     * Function to lazyload userlist for autocomplete.
     *
     * @param string $query
     * @return array
     */
    public static function load_users(string $query): array {
        global $DB;
        $params = [];
        $values = explode(' ', $query);
        $fullsql = $DB->sql_concat(
            '\' \'',
            'u.id',
            '\' \'',
            'u.firstname',
            '\' \'',
            'u.lastname',
            '\' \'',
            'u.email',
            '\' \''
        );
        $sql = "SELECT * FROM (
                    SELECT u.id, u.firstname, u.lastname, u.email, $fullsql AS fulltextstring
                    FROM {user} u
                    WHERE u.deleted = 0
                ) AS fulltexttable";
                // Check for u.deleted = 0 is important, so we do not load any deleted users!

        if (!empty($query)) {
            // We search for every word extra to get better results.
            $firstrun = true;
            $counter = 1;
            foreach ($values as $value) {
                $sql .= $firstrun ? ' WHERE ' : ' AND ';
                $sql .= " " . $DB->sql_like('fulltextstring', ':param' . $counter, false) . " ";
                // If it's numeric, we search for the full number - so we need to add blanks.
                $params['param' . $counter] = is_numeric($value) ? "% $value %" : "%$value%";
                $firstrun = false;
                $counter++;
            }
        }

        // We don't return more than 100 records, so we don't need to fetch more from db.
        $sql .= " limit 102";
        $rs = $DB->get_recordset_sql($sql, $params);
        $count = 0;
        $list = [];

        foreach ($rs as $record) {
            $user = (object)[
                'id' => $record->id,
                'firstname' => $record->firstname,
                'lastname' => $record->lastname,
                'email' => $record->email,
            ];

            $count++;
            $list[$record->id] = $user;
        }

        $rs->close();

        return [
            'warnings' => count($list) > 100 ? get_string('toomanyuserstoshow', 'core', '> 100') : '',
            'list' => count($list) > 100 ? [] : $list,
        ];
    }
}
