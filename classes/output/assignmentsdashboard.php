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

use cache;
use core\chart_pie;
use core\chart_series;
use html_writer;
use local_taskflow\form\filters\types\user_profile_field;
use local_taskflow\local\assignment_information\assignment_information;
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\output\assignmentsdashboard\assignmentdataprovider;
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
     * Data provider used to supply SQL and parameters for the dashboard.
     *
     * @var AssignmentDataProvider
     */
    private AssignmentDataProvider $provider;

    /**
     * Constructor.
     *
     * @param AssignmentDataProvider $provider
     * @param int $userid
     * @param array $arguments
     *
     */
    public function __construct(
        AssignmentDataProvider $provider,
        int $userid = 0,
        array $arguments = []
    ) {
        $this->provider = $provider;
        $this->userid = $userid;
        $this->arguments = $arguments;
        $this->table = $this->set_table();
    }

    /**
     * get_assignmentsdashboard.
     *
     * @return mixed
     */
    private function set_table() {
        // Create the table.

        global $USER;

        $selectedadapter = get_config('local_taskflow', 'external_api_option');
        $classname = "\\taskflowadapter_{$selectedadapter}\\table\\assignments_table";
        if (!class_exists($classname)) {
            $classname = "\\local_taskflow\\table\\assignments_table";
        }
        $table = new $classname('local_taskflow_assignments_' . $USER->id);
        $this->set_common_table_options_from_arguments($table, $this->arguments);

        $columns = [
            'id' => 'ID',
            'fullname' => get_string('fullname'),
            'targets' => get_string('targets', 'local_taskflow'),
            'rulename' => get_string('rulenameheader', 'local_taskflow'),
            'supervisor' => get_string('supervisor', 'local_taskflow'),
            'status' => get_string('status', 'local_taskflow'),
            'statussortkey' => get_string('status', 'local_taskflow'),
            'active' => get_string('active', 'local_taskflow'),
            'usermodified' => get_string('usermodified', 'local_taskflow'),
            'usermodified_fullname' => get_string('usermodified_fullname', 'local_taskflow'),
            'timecreated' => get_string('timecreated', 'local_taskflow'),
            'timemodified' => get_string('timemodified', 'local_taskflow'),
            'actions' => get_string('actions', 'local_taskflow'),
            'comment' => get_string('comment', 'local_taskflow'),
            'testmoodleid' => 'testmoodleid',
            'info' => get_string('info', 'local_taskflow'),
            'duedate' => get_String('duedate', 'local_taskflow'),
        ];

        $searchcolumns = [
            'fullname',
            'rulename',
        ];

        $sortablecolumns = [
            'id',
            'fullname',
            'rulename',
            'statussortkey',
            'status',
            'supervisor',
        ];

        $searcharray = ['fullname', 'rulename', 'status'];

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

        $table->define_fulltextsearchcolumns($searcharray);

        $columns['actions'] = get_string('actions', 'local_taskflow');

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
        $data = $this->provider->get_table_data();

        $this->table->set_filter_sql(
            $data['select'],
            $data['from'],
            $data['where'],
            '',
            $data['params']
        );
        $this->table->pageable(true);
        $this->table->showrowcountselect = true;
        $this->data['table'] = '';

        if (!empty($this->arguments['chart'])) {
            $cache = cache::make('local_taskflow', 'dashboardfilter');
            $cachekey = 'supervisordashboardfilter_' . $this->userid;
            $this->create_chart($cache, $cachekey);
            return;
        }
        $this->customize_columns();
        $this->data['table'] = $this->table->outhtml(20, true);
    }

    /**
     * get_assignmentsdashboard.
     */
    public function customize_columns() {
        if (empty($this->arguments['columns'])) {
            return;
        }

        // Parse, trim, and de-duplicate requested columns.
        $requested = array_filter(array_map('trim', explode(',', $this->arguments['columns'])));
        $requested = array_values(array_unique($requested));

        if (empty($requested)) {
            return;
        }

        $newcolumns = [];
        $newheaders = [];

        foreach ($requested as $colname) {
            if (isset($this->table->columns[$colname])) {
                $newcolumns[] = $colname;
                $idx = $this->table->columns[$colname];
                $newheaders[] = $this->table->headers[$idx];
            }
        }

        if (empty($newcolumns)) {
            return;
        }
        $this->table->columns = [];
        $this->table->headers = [];
        $this->table->define_columns($newcolumns);
        $this->table->define_headers($newheaders);
    }

    /**
     * get_assignmentsdashboard.
     */
    public function set_my_table_heading() {
        if (get_config('local_taskflow', 'external_api_option') != 'tuines') {
            $this->data['headline'] = get_string('myassignments', 'local_taskflow');
        }
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
     * @param array $args
     */
    public function set_general_table_heading($args) {
        if (!empty($args['noheading'])) {
            return;
        }

        if (!empty($args['toclarify'])) {
            $this->set_overdue_table_heading();
            return;
        }

        if (get_config('local_taskflow', 'external_api_option') != 'tuines') {
            $this->data['headline'] = get_string('assignmentstableheading', 'local_taskflow');
        }
        if (
            !empty($args['description']) &&
            !empty(get_string($args['description'], 'local_taskflow'))
        ) {
            $this->data['description'] = get_string($args['description'], 'local_taskflow');
        } else {
            $this->data['description'] = get_string('assignmentstabledescription', 'local_taskflow');
        }
    }

    /**
     * get_assignmentsdashboard.
     */
    public function get_supervisordashboard() {
        $data = $this->provider->get_table_data();
        $this->table->set_filter_sql(
            $data['select'],
            $data['from'],
            $data['where'],
            '',
            $data['params']
        );

        if (!empty($this->arguments['chart'])) {
            $cache = cache::make('local_taskflow', 'dashboardfilter');
            $cachekey = 'assignmentsdashboardfilter_' . $this->userid;
            $this->create_chart($cache, $cachekey);
            return;
        }
        $this->customize_columns();
        $this->data['table'] = $this->table->outhtml(20, true);
    }

    /**
     * get_assignmentsdashboard.
     */
    public function set_overdue_table_heading() {
        if (get_config('local_taskflow', 'external_api_option') != 'tuines') {
             $this->data['headline'] = get_string('clarifyassignments', 'local_taskflow');
        }
        $this->data['description'] = get_string('clarifyassignments_desc', 'local_taskflow');
    }



    /**
     * get_assignmentsdashboard.
     * @param array $args
     */
    public function set_supervisor_table_heading($args) {
        if (!empty($args['noheading'])) {
            return;
        }

        if (!empty($args['toclarify'])) {
            $this->set_overdue_table_heading();
            return;
        }

        if (get_config('local_taskflow', 'external_api_option') != 'tuines') {
            $this->data['headline'] = get_string('supervisorheading', 'local_taskflow');
        }
        if (
            !empty($args['description']) &&
            !empty(get_string($args['description'], 'local_taskflow'))
        ) {
            $this->data['description'] = get_string($args['description'], 'local_taskflow');
        } else {
            $this->data['description'] = get_string('supervisordescription', 'local_taskflow');
        }
        return;
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
    /**
     * Makes it possible to create charts for different dashboards.
     *
     * @param mixed $cache
     * @param string $cachekey
     *
     * @return void
     *
     */
    private function create_chart($cache, $cachekey) {
        global $OUTPUT, $DB;
        $filter = $cache->get($cachekey) ?: [];
        if (!isset($filter['chart'])) {
            // Get status identifiers to build IN clause.
            $statusoverdue = assignment_status_facade::get_status_identifier('overdue');
            $statusassigned = assignment_status_facade::get_status_identifier('assigned');
            $statusenrolled = assignment_status_facade::get_status_identifier('enrolled');
            $statuspartiallycompleted = assignment_status_facade::get_status_identifier('partially_completed');
            $statusprolonged = assignment_status_facade::get_status_identifier('prolonged');
            $statuscompleted = assignment_status_facade::get_status_identifier('completed');

            // Statuses for assigned group.
            $assignedstatuses = [
                $statusassigned,
                $statusenrolled,
                $statuspartiallycompleted,
                $statusprolonged,
            ];

            // Build optimized SQL with aggregation and active filter directly in DB.
            $wherecondition = $this->table->sql->where . ' AND active = 1';
            $sql = "SELECT status, COUNT(*) as cnt FROM {$this->table->sql->from}
                    WHERE {$wherecondition}
                    GROUP BY status";

            $results = $DB->get_records_sql($sql, $this->table->sql->params);

            // Count statuses from aggregated results.
            $overdue = 0;
            $assigned = 0;
            $completed = 0;

            foreach ($results as $record) {
                if ($record->status == $statusoverdue) {
                    $overdue = (int)$record->cnt;
                } else if (in_array($record->status, $assignedstatuses)) {
                    $assigned += (int)$record->cnt;
                } else if ($record->status == $statuscompleted) {
                    $completed = (int)$record->cnt;
                }
            }

            if (empty($results)) {
                $this->data['table'] = get_string('nocharttorender', 'local_taskflow');
                return;
            }

            if (
                empty($overdue)
                && empty($assigned)
                && empty($completed)
            ) {
                $this->data['table'] = get_string('nocharttorender', 'local_taskflow');
                return;
            }

                $chart = new chart_pie();
                $chart->set_doughnut(true);
                $chart->set_title('');

                $series = new chart_series('', [$overdue, $assigned, $completed]);
                $chart->add_series($series);
                $series->set_colors([
                                        '#0C3855', // Overdue.
                                        '#2E98D7', // Assigned.
                                        '#BBCF02', // Completed.
                                    ]);
                $chart->set_labels([
                    get_string('statusoverdue', 'local_taskflow'),
                    get_string('statusassigned', 'local_taskflow'),
                    get_string('statuscompleted', 'local_taskflow'),
                ]);
                $rendered = $OUTPUT->render($chart);
                $this->data['table'] = $rendered;
                $filter['chart'] = $chart;
                $cache->set($cachekey, $filter);
        } else {
            $this->data['table'] = $OUTPUT->render($filter['chart']);
        }
    }
}
