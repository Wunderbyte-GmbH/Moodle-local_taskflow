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

namespace taskflowadapter_standard\output;

use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\output\adapter_view_interface;
use renderer_base;
use stdClass;

/**
 * Assignment detail page of the Standard adapter: the core page plus a prominent status badge.
 *
 * Only used while the Standard adapter is active (resolved by local_taskflow\output\adapter_view_resolver),
 * so the KSW and TU Wien pages stay unchanged.
 *
 * @package    taskflowadapter_standard
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class singleassignment extends \local_taskflow\output\singleassignment implements adapter_view_interface {
    /**
     * The template extends local_taskflow/singleassignment and only fills its status block.
     *
     * @return string
     */
    public function get_template(): string {
        return 'taskflowadapter_standard/singleassignment';
    }

    /**
     * Core data plus the status badge.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $data = parent::export_for_template($output);
        $data['assignmentstatus'] = self::get_status_display($this->get_assignmentdata());
        return $data;
    }

    /**
     * Label and CSS class of the assignment status, worded like the status column of the assignment tables.
     *
     * @param stdClass $assignmentdata Needs status, overduecounter and prolongedcounter.
     * @return array With keys name and statusclass.
     */
    public static function get_status_display(stdClass $assignmentdata): array {
        $status = (int)$assignmentdata->status;
        $name = assignment_status_facade::get_specific_names($status);
        if ($status === assignment_status_facade::get_status_identifier('overdue')) {
            $name .= ' (' . (int)($assignmentdata->overduecounter ?? 0) . ')';
        } else if ($status === assignment_status_facade::get_status_identifier('prolonged')) {
            $name .= ' (' . (int)($assignmentdata->prolongedcounter ?? 0) . ')';
        }
        return [
            'name' => $name,
            'statusclass' => 'local-taskflow-status-' . ($status < 0 ? 'm' . abs($status) : $status),
        ];
    }
}
