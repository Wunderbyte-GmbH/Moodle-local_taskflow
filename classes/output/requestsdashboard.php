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

        // Add default sorting.
        $table->sort_default_column = 'timecreated';
        $table->sort_default_order = SORT_DESC;

        $table->define_cache('local_taskflow', 'requestslist');

        $table->set_sql('*', '{local_taskflow_requests}', '1=1', []);

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
