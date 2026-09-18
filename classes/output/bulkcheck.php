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
 * Renders the list of parked messages.
 *
 * @package local_taskflow
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\output;

use local_taskflow\local\messages\bulk_check\bulk_check;
use local_taskflow\table\bulk_check_table;
use local_taskflow\taskflow_stringmanager;
use renderable;
use renderer_base;
use templatable;

/**
 * The parked messages, one row per message with an expandable panel.
 *
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bulkcheck implements renderable, templatable {
    /** @var array Data for the template. */
    private array $data = [];

    /**
     * Builds the table.
     */
    public function __construct() {
        $table = new bulk_check_table('local_taskflow_bulk_check_parked');

        $columns = [
            'messagename' => taskflow_stringmanager::get_string('messagename'),
            'parked' => taskflow_stringmanager::get_string('bulkcheckparkedcolumn'),
            'rules' => taskflow_stringmanager::get_string('bulkcheckrulescolumn'),
            'oldest' => taskflow_stringmanager::get_string('bulkcheckoldestcolumn'),
        ];

        $table->define_headers(array_values($columns));
        $table->define_columns(array_keys($columns));

        // The panel below each row carries the affected users and the two buttons.
        $table->add_subcolumns('invisiblerow', ['users']);
        $table->add_classes_to_subcolumns('invisiblerow', ['columnclass' => 'collapsable-element']);
        $table->tabletemplate = 'local_taskflow/bulk_parked_list';

        // The shared action web service checks this before it dispatches to our handlers.
        $table->requirecapability = 'local/taskflow:editmessages';

        [$fields, $from, $where, $params] = bulk_check::get_parked_messages_sql();
        $table->set_sql($fields, $from, $where, $params);

        $table->sort_default_column = 'oldest';
        $table->sort_default_order = SORT_ASC;

        $table->pageable(true);
        $table->showrowcountselect = true;

        $this->data['table'] = $table->outhtml(10, false);
    }

    /**
     * Prepare data for use in a template.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        return $this->data;
    }
}
