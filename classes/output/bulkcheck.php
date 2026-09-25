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
use local_wunderbyte_table\filters\types\standardfilter;
use renderable;
use renderer_base;
use templatable;

/**
 * Every mail the bulk check stopped, one per row, with a checkbox on each of them.
 *
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bulkcheck implements renderable, templatable {
    /** @var array Data for the template. */
    private array $data = [];

    /**
     * Builds the table.
     *
     * @param int $messageid Limits the list to one message, zero shows all of them.
     */
    public function __construct(int $messageid = 0) {
        $table = new bulk_check_table('local_taskflow_bulk_check_parked');

        $columns = [
            'messagename' => taskflow_stringmanager::get_string('bulkcheckmessagecolumn'),
            'rulename' => taskflow_stringmanager::get_string('bulkcheckrulecolumn'),
            'recipient' => taskflow_stringmanager::get_string('bulkcheckusercolumn'),
            'email' => taskflow_stringmanager::get_string('bulkcheckemailcolumn'),
            'scheduledtime' => taskflow_stringmanager::get_string('bulkcheckduecolumn'),
            'waiting' => taskflow_stringmanager::get_string('bulkcheckoldestcolumn'),
        ];

        $table->define_headers(array_values($columns));
        $table->define_columns(array_keys($columns));

        // The shared action web service checks this before it dispatches to our handlers.
        $table->requirecapability = 'local/taskflow:editmessages';

        [$fields, $from, $where, $params] = bulk_check::get_parked_mails_sql($messageid);
        $table->set_sql($fields, $from, $where, $params);

        $table->add_filter(new standardfilter('messagename', taskflow_stringmanager::get_string('bulkcheckmessagecolumn')));
        $table->add_filter(new standardfilter('rulename', taskflow_stringmanager::get_string('bulkcheckrulecolumn')));
        $table->define_fulltextsearchcolumns(['messagename', 'rulename', 'recipient', 'email']);
        $table->define_sortablecolumns(['messagename', 'rulename', 'recipient', 'email', 'scheduledtime']);

        $table->sort_default_column = 'scheduledtime';
        $table->sort_default_order = SORT_ASC;

        $this->add_actionbuttons($table, $messageid);

        // A cache of its own, so that a release can clear this list alone. It cannot be
        // switched off instead: the filters of a table are built through the cache without
        // asking whether there is one.
        $table->define_cache('local_taskflow', 'bulkcheckmails');

        $table->pageable(true);
        $table->showrowcountselect = true;
        $table->showcountlabel = true;
        $table->showfilterontop = true;

        $this->data['table'] = $table->outhtml(25, false);
    }

    /**
     * The four buttons above and below the list.
     *
     * Two of them act on what is ticked, two on the whole scope of the page. The scope ones
     * cannot honour the filter, because the action web service is told neither the filter nor
     * the search, so their confirmation says what they really do.
     *
     * The id of every button is -1: that is what makes the library send one request with the
     * ticked ids in it, instead of ignoring them or firing one request per row.
     *
     * @param bulk_check_table $table
     * @param int $messageid
     * @return void
     */
    private function add_actionbuttons(bulk_check_table $table, int $messageid): void {
        $parked = bulk_check::count_parked($messageid);

        $table->actionbuttons[] = [
            'label' => taskflow_stringmanager::get_string('bulkchecksendselected'),
            'class' => 'btn btn-success btn-sm mr-2',
            'href' => '#',
            'iclass' => 'fa fa-paper-plane',
            'arialabel' => 'releaseselected',
            'id' => -1,
            'methodname' => 'releaseselected',
            'nomodal' => false,
            'selectionmandatory' => true,
            'data' => [
                'id' => -1,
                'titlestring' => 'bulkcheckreleasetitle',
                'bodystring' => 'bulkcheckreleasebody',
                'submitbuttonstring' => 'bulkcheckreleasesubmit',
                'component' => 'local_taskflow',
                'labelcolumn' => 'recipient',
            ],
        ];

        $table->actionbuttons[] = [
            'label' => taskflow_stringmanager::get_string('bulkcheckdismissselected'),
            'class' => 'btn btn-danger btn-sm mr-2',
            'href' => '#',
            'iclass' => 'fa fa-ban',
            'arialabel' => 'dismissselected',
            'id' => -1,
            'methodname' => 'dismissselected',
            'nomodal' => false,
            'selectionmandatory' => true,
            'data' => [
                'id' => -1,
                'titlestring' => 'bulkcheckdismisstitle',
                'bodystring' => 'bulkcheckdismissbody',
                'submitbuttonstring' => 'bulkcheckdismisssubmit',
                'component' => 'local_taskflow',
                'labelcolumn' => 'recipient',
            ],
        ];

        $table->actionbuttons[] = [
            'label' => taskflow_stringmanager::get_string('bulkcheckreleaseall', $parked),
            'class' => 'btn btn-outline-success btn-sm mr-2',
            'href' => '#',
            'iclass' => 'fa fa-paper-plane',
            'arialabel' => 'releaseall',
            'id' => -1,
            'methodname' => 'releaseall',
            'nomodal' => false,
            'selectionmandatory' => false,
            'data' => [
                'id' => -1,
                'messageid' => $messageid,
                'titlestring' => 'bulkcheckreleasetitle',
                'bodystring' => 'bulkcheckreleaseallbody',
                'submitbuttonstring' => 'bulkcheckreleasesubmit',
                'component' => 'local_taskflow',
            ],
        ];

        $table->actionbuttons[] = [
            'label' => taskflow_stringmanager::get_string('bulkcheckdismissall', $parked),
            'class' => 'btn btn-outline-danger btn-sm',
            'href' => '#',
            'iclass' => 'fa fa-ban',
            'arialabel' => 'dismissall',
            'id' => -1,
            'methodname' => 'dismissall',
            'nomodal' => false,
            'selectionmandatory' => false,
            'data' => [
                'id' => -1,
                'messageid' => $messageid,
                'titlestring' => 'bulkcheckdismisstitle',
                'bodystring' => 'bulkcheckdismissallbody',
                'submitbuttonstring' => 'bulkcheckdismisssubmit',
                'component' => 'local_taskflow',
            ],
        ];
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
