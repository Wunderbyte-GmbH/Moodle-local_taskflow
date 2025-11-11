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
use local_taskflow\local\requests;
use local_taskflow\plugininfo\taskflowadapter;
use local_wunderbyte_table\filters\types\datepicker;
use local_wunderbyte_table\filters\types\standardfilter;
use moodle_url;
use renderable;
use renderer_base;
use templatable;

/**
 * Display this element
 * @package local_taskflow
 *
 */
class requestsdashboardhr implements renderable, templatable {
    /**
     * data is the array used for output.
     *
     * @var array
     */
    public $data = [];

    /**
     * Constructor.
     * @param array $data
     */
    public function __construct(array $data) {
        global $DB, $USER;

        // Create the table.
        $table = new \local_taskflow\table\requests_table('local_taskflow_requests');

        $columns = [
            'fullname' => get_string('requestinguser', 'local_taskflow'),
            'assignmentid' => get_string('assignment', 'local_taskflow'),
            'status' => get_string('status'),
            'act' => get_string('actions', 'local_taskflow'),
            'timecreated' => get_string('timecreated'),
            'comment' => get_string('comment', 'local_taskflow'),
        ];

        $table->define_headers(array_values($columns));
        $table->define_columns(array_keys($columns));
        $table->define_sortablecolumns(['timecreated']);

        $statusfilter = new standardfilter('treated', get_string('status'));
        $statusfilter->add_options([
                requests::TREATED_STATUS_UNTREATED => requests::resolve_treated(requests::TREATED_STATUS_UNTREATED),
                requests::TREATED_STATUS_CONFIRMED => requests::resolve_treated(requests::TREATED_STATUS_CONFIRMED),
                requests::TREATED_STATUS_DECLINED => requests::resolve_treated(requests::TREATED_STATUS_DECLINED),
        ]);
        $table->add_filter($statusfilter);

        $datepicker = new datepicker(
            'timecreated',
            get_string('timecreated'),
        );
            $datepicker->add_options(
                'in between',
                '<',
                get_string('apply_filter', 'local_wunderbyte_table'),
                'today 00:00',
                'today 00:00 1 year',
                ['within', 'before', 'after']
            );
        $table->add_filter($datepicker);

        $table->showfilterontop = 1;
        $table->filteronloadinactive = 1;

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
     * @return string
     *
     */
    public function get_sql_for_records($data): array {
        global $USER;

        $all = false;
        foreach ($data as $sub) {
            if (is_array($sub) && array_key_exists('all', $sub)) {
                $all = true;
                break;
            }
        }

        if ($all && has_capability('local/taskflow:viewallrequests', context_system::instance())) {
            return ['*', '{local_taskflow_requests}', '1=1', []];
        } else {
            $fields = '*';
            $from = '{local_taskflow_requests}';
            $where = 'forhr = :forhr';
            $params = ['forhr' => 1];
            return [$fields, $from, $where, $params];
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
