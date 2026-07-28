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
use local_wunderbyte_table\filters\types\standardfilter;
use local_wunderbyte_table\filters\types\toggle;
use local_wunderbyte_table\wunderbyte_table;
use renderable;
use renderer_base;
use templatable;
use local_taskflow\taskflow_stringmanager;

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

        global $USER, $PAGE;

        $selectedadapter = get_config('local_taskflow', 'external_api_option');
        $classname = "\\taskflowadapter_{$selectedadapter}\\table\\assignments_table";
        if (!class_exists($classname)) {
            $classname = "\\local_taskflow\\table\\assignments_table";
        }
        $uniqueid = 'local_taskflow_assignments_' . $USER->id . '_' . mt_rand(100000, 999999);
        $table = new $classname($uniqueid);
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
            'lastinternalcomment',
            'timecreated',
            'timemodified',
            'duedate',
            'comment',
        ];

        $assignmentfields = get_config('local_taskflow', 'assignment_fields');
        $customprofilenames = user_profile_field::get_userprofilefields();
        $assignmentfields = array_filter(array_map('trim', explode(',', $assignmentfields)));
        $customcolumns = [];
        foreach ($assignmentfields as $fieldshortname) {
            $columnkey = "custom_{$fieldshortname}";
            $customcolumns[$columnkey] = $customprofilenames[$fieldshortname] ?? $columnkey;
            $sortablecolumns[] = $columnkey;
            $searchcolumns[] = $columnkey;
        }

        $columns = $this->customize_columns($customcolumns);

        // The actions column is always shown, even when the shortcode reduces the columns.
        $columns['actions'] = taskflow_stringmanager::get_string('actions');

        // Search and sorting must only ever reference visible columns — hidden
        // ones would show up with raw keys in the search info popover and the
        // sort dropdown.
        $table->define_fulltextsearchcolumns(array_values(array_intersect($searchcolumns, array_keys($columns))));
        $table->define_sortablecolumns(array_values(array_intersect($sortablecolumns, array_keys($columns))));

        $table->define_headers(array_values($columns));
        $table->define_columns(array_keys($columns));
        $this->set_common_table_options_from_arguments($table, $this->arguments);

        $table->define_cache('local_taskflow', 'assignmentslist');
        if (!empty($this->arguments['filter'])) {
            $filters = array_filter(array_map('trim', explode(',', $this->arguments['filter'])));
            $this->add_filters($table, $filters);
        }
        // Add default sorting, unless the shortcode requested its own.
        if (empty($this->arguments['sortby'])) {
            $table->sort_default_column = 'timecreated';
            $table->sort_default_order = SORT_DESC;
        }

        $downloaddashboard = has_capability('local/taskflow:downloaddashboard', $PAGE->context);

        $table->showdownloadbutton = $downloaddashboard;
        $table->showdownloadbuttonatbottom = $downloaddashboard;

        return $table;
    }

    /**
     * Add the dropdown filters and the hide-completed toggle to the table.
     *
     * @param wunderbyte_table $table
     * @param array $filters
     * @return void
     */
    private function add_filters(wunderbyte_table $table, array $filters): void {
        foreach ($filters as $filter) {
            switch ($filter) {
                case 'status':
                    $statusfilter = new standardfilter('status', taskflow_stringmanager::get_string('status'));
                    $statusfilter->add_options(assignment_status_facade::get_all_wanted_stati());
                    $table->add_filter($statusfilter);
                    break;
                case 'rulename':
                    $rulefilter = new standardfilter('rulename', taskflow_stringmanager::get_string('rulenameheader'));
                    $table->add_filter($rulefilter);
                    break;
                case 'completed':
                    $hidecompleted = new toggle('notcompleted', taskflow_stringmanager::get_string('hidecompleted'));
                    $table->add_filter($hidecompleted);
                    break;
                default:
                    break;
            }
        }
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
        $this->data['table'] = $this->table->outhtml(20, true);
    }

    /**
     * Build the columnname => localized header map for the table.
     *
     * When the shortcode provides a 'columns' argument, only the requested
     * columns are returned, in the requested order. Since the table is defined
     * from this map in one go, hidden columns never make it into the table
     * definition (columns, headers, subcolumns or search popover).
     *
     * @param array $customcolumns additional columnname => header entries (custom profile fields)
     * @return array
     */
    public function customize_columns(array $customcolumns = []) {
        $columns = [
            'id' => 'ID',
            'fullname' => get_string('fullname'),
            'targets' => taskflow_stringmanager::get_string('targets'),
            'rulename' => taskflow_stringmanager::get_string('rulenameheader'),
            'supervisor' => taskflow_stringmanager::get_string('supervisor'),
            'status' => taskflow_stringmanager::get_string('status'),
            'statussortkey' => taskflow_stringmanager::get_string('status'),
            'active' => taskflow_stringmanager::get_string('active'),
            'usermodified' => taskflow_stringmanager::get_string('usermodified'),
            'usermodified_fullname' => taskflow_stringmanager::get_string('usermodified_fullname'),
            'timecreated' => taskflow_stringmanager::get_string('timecreated'),
            'timemodified' => taskflow_stringmanager::get_string('timemodified'),
            'actions' => taskflow_stringmanager::get_string('actions'),
            'comment' => taskflow_stringmanager::get_string('comment'),
            'testmoodleid' => 'testmoodleid',
            'info' => taskflow_stringmanager::get_string('info'),
            'duedate' => taskflow_stringmanager::get_string('duedate'),
            'lastinternalcomment' => taskflow_stringmanager::get_string('lastinternalcomment'),
        ];
        $columns = array_merge($columns, $customcolumns);
        if (empty($this->arguments['columns'])) {
            return $columns;
        }
        $includedcolumns = array_filter(array_map('trim', explode(',', $this->arguments['columns'])));
        $customized = [];
        foreach ($includedcolumns as $includedcolumn) {
            if (isset($columns[$includedcolumn])) {
                $customized[$includedcolumn] = $columns[$includedcolumn];
            }
        }
        // If no requested column matched, ignore the argument instead of rendering an empty table.
        return $customized ?: $columns;
    }

    /**
     * get_assignmentsdashboard.
     * @param array $args
     */
    public function set_my_table_heading(array $args) {
        if (get_config('local_taskflow', 'external_api_option') != 'tuines') {
            $this->data['headline'] = taskflow_stringmanager::get_string('myassignments');
        }
        $this->data['description'] = taskflow_stringmanager::get_string('myassignments_desc');
        if (!empty($args['noheading'])) {
            $this->data['headline'] = "";
        }
        if (!empty($args['nodescription'])) {
            $this->data['description'] = "";
        }
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
            $this->data['headline'] = taskflow_stringmanager::get_string('assignmentstableheading');
        }
        if (
            !empty($args['description']) &&
            !empty(taskflow_stringmanager::get_string($args['description']))
        ) {
            $this->data['description'] = taskflow_stringmanager::get_string($args['description']);
        } else {
            $this->data['description'] = taskflow_stringmanager::get_string('assignmentstabledescription');
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
        $this->data['table'] = $this->table->outhtml(20, true);
    }

    /**
     * get_assignmentsdashboard.
     */
    public function set_overdue_table_heading() {
        if (get_config('local_taskflow', 'external_api_option') != 'tuines') {
             $this->data['headline'] = taskflow_stringmanager::get_string('clarifyassignments');
        }
        $this->data['description'] = taskflow_stringmanager::get_string('clarifyassignments_desc');
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
            $this->data['headline'] = taskflow_stringmanager::get_string('supervisorheading');
        }
        if (
            !empty($args['description']) &&
            !empty(taskflow_stringmanager::get_string($args['description']))
        ) {
            $this->data['description'] = taskflow_stringmanager::get_string($args['description']);
        } else {
            $this->data['description'] = taskflow_stringmanager::get_string('supervisordescription');
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
        global $PAGE;
        $PAGE->requires->js_call_amd('local_taskflow/myblocktab', 'init');
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
                // Wunderbyte_table::define_columns() merges with already defined
                // columns, so this appends the sortby column. Append a header too,
                // so column indexes keep matching the headers array.
                $table->define_columns([$args['sortby']]);
                $table->headers[] = $args['sortby'];
            }
            $table->sortable(true, $args['sortby'], $defaultorder);
        } else {
            $table->sortable(true, 'text', $defaultorder);
        }
        if (isset($args['requirelogin']) && $args['requirelogin'] == "false") {
            $table->requirelogin = false;
        }
        if (!empty($args['filter'])) {
            $table->showcountlabel = true;
            $table->filteronloadinactive = 1;
        }
        if (isset($args['filterontop']) && ($args['filterontop'] == 'true' || $args['filterontop'] == '1')) {
            $table->showfilterontop = true;
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
                $this->data['table'] = taskflow_stringmanager::get_string('nocharttorender');
                return;
            }

            if (
                empty($overdue)
                && empty($assigned)
                && empty($completed)
            ) {
                $this->data['table'] = taskflow_stringmanager::get_string('nocharttorender');
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
                    taskflow_stringmanager::get_string('statusoverdue'),
                    taskflow_stringmanager::get_string('statusassigned'),
                    taskflow_stringmanager::get_string('statuscompleted'),
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
