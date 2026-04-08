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
 * Interface for myassignment data providers used in the dashboard table.
 *
 * @package    local_taskflow
 * @copyright  2025 Wunderbyte Gmbh <info@wunderbyte.at>
 * @author     Georg Maißer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 **/

namespace local_taskflow\output\assignmentsdashboard;

use local_taskflow\local\assignments\assignment;
use local_taskflow\output\assignmentsdashboard\assignmentdataprovider;

/**
 * Data provider for the "My Assignments" dashboard table.
 * @package local_taskflow
 */
class myassignmentsprovider implements assignmentdataprovider {
    /**
     * User ID of the user whose assignments are to be shown.
     * @var int
     */
    private int $userid;

    /**
     * Filter arguments passed to the dashboard.
     * @var array
     */
    private array $arguments;

    /**
     * Constructor.
     * @param int $userid
     * @param array $arguments
     */
    public function __construct(int $userid, array $arguments) {
        $this->userid = $userid;
        $this->arguments = $arguments;
    }

    /**
     * Get SQL-Parameters for table data.
     * @return array An array containing 'select', 'from', 'where', and 'params'
     */
    public function get_table_data(): array {
        $assignments = assignment::get_instance();
        $status = $this->arguments['status'] ?? [];
        [$select, $from, $where, $params] = $assignments->return_user_assignments_sql(
            $this->userid,
            $this->arguments['active'],
            is_array($status) ? $status : explode(',', $status),
            $this->arguments,
        );
        return compact('select', 'from', 'where', 'params');
    }
}
