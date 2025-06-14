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

use local_taskflow\form\filters\types\user_profile_field;
use local_taskflow\local\assignments\assignment;
use renderable;
use renderer_base;
use templatable;

/**
 * Display this element
 * @package local_taskflow
 *
 */
class history implements renderable, templatable {
    /**
     * data is the array used for output.
     *
     * @var array
     */
    private $data = [];

    /**
     * Constructor.
     *
     * @param int $assignmentid
     * @param int $userid
     * @param string $historytype
     * @param int $limit
     *
     */
    public function __construct(int $assignmentid = 0, int $userid = 0, $historytype = '', $limit = 0) {
       // Create the table.
        $table = new \local_taskflow\table\history_table('local_taskflow_history' . $userid . '_' . $assignmentid);

        $columns = [
            'type' => get_string('action'),
            'timecreated' => get_string('date', 'local_taskflow'),
            'createdby' => get_string('usermodified', 'local_taskflow'),
        ];

        $table->define_headers(array_values($columns));
        $table->define_columns(array_keys($columns));

        $table->add_subcolumns('invisiblerow', ['data']);
        $table->add_classes_to_subcolumns('invisiblerow', ['columnclass' => 'collapsable-element']);

        $table->define_cache('local_taskflow', 'historylist');
        $table->tabletemplate = 'local_taskflow/history_list';

        // Which table do we need.
        [$select, $from, $where, $params] = \local_taskflow\local\history\history::return_sql($assignmentid, $userid, $historytype, $limit);

        $table->set_sql($select, $from, $where, $params);

        $table->sort_default_column = 'timecreated';
        $table->sort_default_order = SORT_DESC;

        $table->pageable(true);
        $table->showrowcountselect = true;

        $html = $table->outhtml(5, true);
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
