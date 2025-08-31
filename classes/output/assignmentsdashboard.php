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
use local_taskflow\local\assignments\assignment;
use local_taskflow\local\assignments\status\assignment_status;
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
     *
     * @return mixed
     */
    private function set_table() {
        // Create the table.
        $selectedadapter = get_config('local_taskflow', 'external_api_option');
        $classname = "\\taskflowadapter_{$selectedadapter}\\table\\assignments_table";
        if (!class_exists($classname)) {
            $classname = "\\local_taskflow\\table\\assignments_table";
        }
        $table = new $classname('local_taskflow_assignments');
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
            'comment' => get_string('comment', 'local_taskflow'),
            'testmoodleid' => 'testmoodleid',
            'info' => get_string('info', 'local_taskflow'),
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
        global $OUTPUT;

        $assignments = new assignment();
        [$select, $from, $where, $params] = $assignments->return_user_assignments_sql($this->userid, $this->arguments['active']);
        $this->table->set_filter_sql($select, $from, $where, '', $params);
        $this->table->pageable(true);
        $this->table->showrowcountselect = true;
        $this->data['table'] = '';
        $cache = cache::make('local_taskflow', 'dashboardfilter');
        $cachekey   = 'dashboardfilter_' . $this->userid;
        $filter = $cache->get($cachekey) ?: [];
        if (!empty($this->arguments['top5'])) {
            if (!isset($filter['top5'])) {
                $targetcounts = [];
                $this->table->printtable(20000, true);
                foreach ($this->table->rawdata as $record) {
                    if (!empty($record->targets)) {
                        $targets = json_decode($record->targets);

                        if (json_last_error() === JSON_ERROR_NONE && is_array($targets)) {
                            foreach ($targets as $t) {
                                $key = "{$t->targetid}|{$t->targetname}";
                                $targetcounts[$key] = ($targetcounts[$key] ?? 0) + 1;
                            }
                        }
                    }
                }
                arsort($targetcounts);
                $top5 = array_slice($targetcounts, 0, 5, true);

                $html = html_writer::start_tag('ul');
                foreach ($top5 as $key => $hits) {
                    [$id, $name] = explode('|', $key, 2);
                    $html .= html_writer::tag('li', format_string($name) . " ({$hits})");
                }
                $html .= html_writer::end_tag('ul');
                $this->data['table'] = $html;
                $filter['top5'] = $html;

                $cache->set($cachekey, $filter);
            } else {
                $this->data['table'] = $filter['top5'];
                return;
            }
        }
        if (!empty($this->arguments['chart'])) {
            if (!isset($filter['chart'])) {
                $this->table->printtable(20000, true);
                $overdue = 0;
                $assigned = 0;
                $completed = 0;
                if (empty($this->table->rawdata)) {
                    $this->data['table'] = get_string('nocharttorender', 'local_taskflow');
                    return;
                }
                foreach ($this->table->rawdata as $record) {
                    switch ($record->status) {
                        case assignment_status::STATUS_OVERDUE:
                            $overdue++;
                            break;
                        case assignment_status::STATUS_ASSIGNED:
                            $assigned++;
                            break;
                        case assignment_status::STATUS_COMPLETED:
                            $completed++;
                            break;
                    }
                }

                $chart = new chart_pie();
                $chart->set_doughnut(true);
                $chart->set_title('');

                $series = new chart_series('', [$overdue, $assigned, $completed]);
                $chart->add_series($series);
                $chart->set_labels([
                    'overdue',
                    'assigned',
                    'completed',
                ]);
                $rendered = $OUTPUT->render($chart);
                $this->data['table'] = $rendered;
                $filter['chart'] = $chart;
                $cache->set($cachekey, $filter);
                return;
            } else {
                $this->data['table'] = $OUTPUT->render($filter['chart']);
                return;
            }
        }
        $this->customize_columns();
        $this->data['table'] = $this->table->outhtml(3, true);
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
        $this->customize_columns();
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
