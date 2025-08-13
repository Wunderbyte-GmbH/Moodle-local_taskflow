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
 * Customizable columns:
 * id
 * fullname
 * targets
 * rulename
 * supervisor
 * status
 * active
 * usermodified
 * usermodified_fullname
 * timecreated
 * timemodified
 * actions
 * @package    local_taskflow
 * @copyright  2025 Wunderbyte Gmbh <info@wunderbyte.at>
 * @author     Georg Maißer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 **/

namespace local_taskflow\output;

use local_taskflow\form\filters\types\user_profile_field;
use local_taskflow\local\assignment_information\assignment_information;
use local_taskflow\local\assignments\assignment;
use local_wunderbyte_table\wunderbyte_table;
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
     * data is the array used for output.
     * @var int
     */
    public $userid = 0;

    /**
     * data is the array used for output.
     * @var array
     */
    public $arguments = [];

    /**
     * data is the array used for output.
     * @var \local_taskflow\table\assignments_table
     */
    public $table;

    /**
     * Constructor.
     *
     * @param int $userid
     * @param array $arguments
     *
     */
    public function __construct(int $userid = 0, array $arguments = []) {
        $this->userid = $userid;
        $this->arguments = $arguments;
        $this->table = $this->set_table();
    }

    /**
     * get_assignmentsdashboard.
     */
    private function set_table() {
        // Create the table.
        $table = new \local_taskflow\table\assignments_table('local_taskflow_assignments');
        $this->set_common_table_options_from_arguments($table, $this->arguments);

        $columns = [
            'id' => 'ID',
            'fullname' => get_string('fullname'),
            'targets' => get_string('targets', 'local_taskflow'),
            'rulename' => get_string('rulenameheader', 'local_taskflow'),
            'supervisor' => get_string('supervisor', 'local_taskflow'),
            'status' => get_string('status', 'local_taskflow'),
            'active' => get_string('active', 'local_taskflow'),
            'usermodified' => get_string('usermodified', 'local_taskflow'),
            'usermodified_fullname' => get_string('usermodified_fullname', 'local_taskflow'),
            'timecreated' => get_string('timecreated', 'local_taskflow'),
            'timemodified' => get_string('timemodified', 'local_taskflow'),
            'actions' => get_string('actions', 'local_taskflow'),
        ];

        $searchcolumns = [
            'fullname',
            'rulename',
        ];

        $sortablecolumns = [
            'fullname',
            'rulename',
            'status',
            'supervisor',
        ];
        $assignmentfields = get_config('local_taskflow', 'assignment_fields');
        $customprofilenames = user_profile_field::get_userprofilefields();
        $assignmentfields = array_filter(array_map('trim', explode(',', $assignmentfields)));
        foreach ($assignmentfields as $fieldshortname) {
            $columnkey = "custom_{$fieldshortname}";
            $columns[$columnkey] = $customprofilenames[$fieldshortname];
            $sortablecolumns[] = $columnkey;
            $searchcolumns[] = $columnkey;
        }
        $table->define_fulltextsearchcolumns($searchcolumns);
        $table->define_sortablecolumns($sortablecolumns);

        $table->define_headers(array_values($columns));
        $table->define_columns(array_keys($columns));

        $table->define_cache('local_taskflow', 'assignmentslist');

        // Add default sorting.
        $table->sort_default_column = 'timecreated';
        $table->sort_default_order = SORT_DESC;

        return $table;
    }

    /**
     * get_assignmentsdashboard.
     */
    public function get_assignmentsdashboard() {
        $assignments = new assignment();
        [$select, $from, $where, $params] = $assignments->return_user_assignments_sql($this->userid, $this->arguments['active']);
        $this->table->set_filter_sql($select, $from, $where, '', $params);
        $this->table->pageable(true);
        $this->table->showrowcountselect = true;
        $this->customize_columns();
        $this->data['table'] = $this->table->outhtml(10, true);
    }

    /**
     * get_assignmentsdashboard.
     */
    public function customize_columns() {
        if (isset($this->arguments['columns'])) {
            $modifiedcolumns = [];
            $modifiedheaders = [];
            $tablecolumns = explode(',', $this->arguments['columns']);
            foreach ($tablecolumns as $key => $tablecolumn) {
                if (isset($this->table->columns[$tablecolumn])) {
                    $modifiedcolumns[$tablecolumn] = $key;
                    $modifiedheaders[] = $this->table->headers[$this->table->columns[$tablecolumn]];
                }
            }
            $this->table->columns = $modifiedcolumns;
            $this->table->headers = $modifiedheaders;
        }
    }

    /**
     * get_assignmentsdashboard.
     */
    public function set_my_table_heading() {
        $this->data['headline'] = get_string('myassignments', 'local_taskflow');
        $this->data['description'] = get_string('myassignments_desc', 'local_taskflow');
    }

    /**
     * get_assignmentsdashboard.
     */
    public function set_my_table_information() {
        $assignmentinformation = new assignment_information($this->userid);
        $information = $assignmentinformation->render_information();
        if (!empty($information)) {
            $this->data['information'] = $information;
        }
    }

    /**
     * get_assignmentsdashboard.
     */
    public function set_general_table_heading() {
        $this->data['headline'] = get_string('assignmentstableheading', 'local_taskflow');
        $this->data['description'] = get_string('assignmentstabledescription', 'local_taskflow');
    }

    /**
     * get_assignmentsdashboard.
     */
    public function get_supervisordashboard() {
        $assignments = new assignment();
        [$select, $from, $where, $params] =
                $assignments->return_supervisor_assignments_sql($this->userid, $this->arguments);

        $this->table->set_sql($select, $from, $where, $params);
        $this->data['table'] = $this->table->outhtml(10, true);
    }

    /**
     * get_assignmentsdashboard.
     */
    public function set_overdue_table_heading() {
        $this->data['headline'] = get_string('clarifyassignments', 'local_taskflow');
        $this->data['description'] = get_string('clarifyassignments_desc', 'local_taskflow');
    }

    /**
     * get_assignmentsdashboard.
     */
    public function set_supervisor_table_heading() {
        $this->data['headline'] = get_string('supervisorheading', 'local_taskflow');
        $this->data['description'] = get_string('supervisordescription', 'local_taskflow');
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

    /**
     * Setting options from shortcodes arguments common for all children of wunderbyte_table .
     *
     * @param wunderbyte_table $table reference to table
     * @param array $args
     *
     * @return void
     *
     */
    public static function set_common_table_options_from_arguments(&$table, $args): void {
        $defaultorder = SORT_ASC; // Default.
        if (!empty($args['sortorder'])) {
            if (strtolower($args['sortorder']) === "desc") {
                $defaultorder = SORT_DESC;
            }
        }
        if (!empty($args['sortby'])) {
            if (
                !isset($table->columns[$args['sortby']])
            ) {
                $table->define_columns([$args['sortby']]);
            }
            $table->sortable(true, $args['sortby'], $defaultorder);
        } else {
            $table->sortable(true, 'text', $defaultorder);
        }
        if (isset($args['requirelogin']) && $args['requirelogin'] == "false") {
            $table->requirelogin = false;
        }
    }
}
