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
 * @author     Georg Maißer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 **/

namespace local_taskflow\output;

use renderable;
use renderer_base;
use templatable;

/**
 * Display this element
 * @package local_taskflow
 *
 */
class assignmentsdashboard implements renderable, templatable {
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
       // Create the table.
        $table = new \local_taskflow\table\assignments_table('local_taskflow_assignments');

        $columns = [
            'userid' => get_string('assignmentsname', 'local_taskflow'),
            'firstname' => get_string('firstname'),
            'lastname' => get_string('lastname'),
            'assigned_date' => get_string('description'),
            'targets' => get_string('targets', 'local_taskflow'),
            'active' => get_string('isactive', 'local_taskflow'),
            'actions' => get_string('actions', 'local_taskflow'),
        ];

        $table->define_headers(array_values($columns));
        $table->define_columns(array_keys($columns));

        $table->define_cache('local_taskflow', 'assignmentslist');

        $select = "ta.id, u.id userid, u.firstname, u.lastname, ta.assigned_date, ta.active, ta.targets";
        $from = "{local_taskflow_assignment} ta
                LEFT JOIN {user} u ON ta.userid = u.id";
        $where = " 1 = 1 ";
        $params = [];

        $table->set_sql($select, $from, $where, $params);

        $html = $table->outhtml(10, true);
        $data['table'] = $html;

        $this->data = $data;
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
