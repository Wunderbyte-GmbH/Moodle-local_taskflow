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
 * Contains class mod_questionnaire\output\indexpage
 *
 * @package    local_taskflow
 * @copyright  2025 Wunderbyte Gmbh <info@wunderbyte.at>
 * @author     Magdalena Holczik
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 **/

namespace local_taskflow\output;

use context_system;
use local_taskflow\local\external_adapter\external_api_base;
use local_taskflow\plugininfo\taskflowadapter;
use moodle_url;
use renderable;
use renderer_base;
use templatable;

/**
 * Display this element
 * @package local_taskflow
 *
 */
class requestsdashboard implements renderable, templatable {
    /**
     * data is the array used for output.
     *
     * @var array
     */
    private $data = [];

    /**
     * Constructor.
     * @param array $data
     */
    public function __construct(array $data) {
        global $DB, $USER;

        // Create the table.
        $table = new \local_taskflow\table\requests_table('local_taskflow_requests');

        $columns = [
            'userid' => get_string('requestinguser', 'local_taskflow'),
            'assignmentid' => get_string('assignment', 'local_taskflow'),
            'status' => get_string('status'),
            'act' => get_string('actions', 'local_taskflow'),
            'timecreated' => get_string('timecreated'),
        ];

        $table->define_headers(array_values($columns));
        $table->define_columns(array_keys($columns));

        [$fields, $from, $where, $params] = $this->get_sql_for_records($data);
        $table->set_sql($fields, $from, $where, $params);

        // Add default sorting.
        $table->sort_default_column = 'timecreated';
        $table->sort_default_order = SORT_DESC;

        $table->define_cache('local_taskflow', 'requestslist');

        $html = $table->outhtml(10, true);
        $data['table'] = $html;

        $this->data = $data;
    }

    /**
     * Returns the SQL to fetch the records for the table.
     *
     * @param array $data
     *
     * @return array
     *
     */
    public function get_sql_for_records($data): array {
        global $DB, $USER;

        if (
            isset($data['all'])
            && has_capability('local/taskflow:handleallrequests', context_system::instance())
        ) {
            return ['*', '{local_taskflow_requests}', '1=1', []];
        } else {
            // Only fetch the records where current user is supervisor or deputy of user of request.
            $svfield = external_api_base::return_shortname_for_functionname(taskflowadapter::TRANSLATOR_USER_SUPERVISOR);
            $dpfield = external_api_base::return_shortname_for_functionname(taskflowadapter::TRANSLATOR_USER_DEPUTY);

            $dbfamily = $DB->get_dbfamily();

            if ($dbfamily === 'postgres') {
                $sql = "
                    SELECT r.*
                    FROM {local_taskflow_requests} r
                    WHERE EXISTS (
                        -- current user is supervisor
                        SELECT 1
                        FROM {user_info_data} uid
                        JOIN {user_info_field} uif ON uid.fieldid = uif.id
                        WHERE uid.userid = r.userid
                        AND uif.shortname = :supervisorfield
                        AND :currentuserid::text = ANY(string_to_array(uid.data, ','))
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM {user_info_data} uid                       -- supervisor field of request user
                        JOIN {user_info_field} uif ON uid.fieldid = uif.id
                        JOIN {user_info_data} depuid
                            ON depuid.userid::text = ANY(string_to_array(uid.data, ','))   -- one depuid per supervisor
                        JOIN {user_info_field} depuif ON depuif.id = depuid.fieldid
                        WHERE uid.userid = r.userid
                            AND uif.shortname = :supervisorfield1
                            AND depuif.shortname = :deputyfield
                            AND :currentuserid_deputy::text = ANY(string_to_array(depuid.data, ','))
                            AND uid.data <> ''
                            AND depuid.data <> ''
                    )
                ";
            } else {
                // MySQL / MariaDB.
                $sql = "
                    SELECT r.*
                    FROM {local_taskflow_requests} r
                    WHERE EXISTS (
                        -- current user is supervisor
                        SELECT 1
                        FROM {user_info_data} uid
                        JOIN {user_info_field} uif ON uid.fieldid = uif.id
                        WHERE uid.userid = r.userid
                        AND uif.shortname = :supervisorfield
                        AND FIND_IN_SET(:currentuserid, uid.data)
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM {user_info_data} uid
                        JOIN {user_info_field} uif ON uid.fieldid = uif.id
                        JOIN {user_info_data} depuid
                            ON FIND_IN_SET(depuid.userid, uid.data)      -- match each supervisor in comma-separated list
                        JOIN {user_info_field} depuif ON depuif.id = depuid.fieldid
                        WHERE uid.userid = r.userid
                            AND uif.shortname = :supervisorfield1
                            AND depuif.shortname = :deputyfield
                            AND FIND_IN_SET(:currentuserid_deputy, depuid.data)
                            AND uid.data <> ''
                            AND depuid.data <> ''
                    )
                ";
            }

            $params = [
                'currentuserid' => $USER->id,
                'currentuserid_deputy' => $USER->id,
                'deputyfield' => $dpfield,
                'supervisorfield' => $svfield,
                'supervisorfield1' => $svfield,
            ];
            return ['r.*', "($sql) r", '1=1', $params];
        }
    }

    /**
     * Prepare data for use in a template
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output) {

        return $this->data;
    }
}
