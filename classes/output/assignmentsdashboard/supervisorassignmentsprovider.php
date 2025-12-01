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
 * @package    local_taskflow
 * @copyright  2025 Wunderbyte Gmbh <info@wunderbyte.at>
 * @author     Georg Maißer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 **/

namespace local_taskflow\output\assignmentsdashboard;

use local_taskflow\local\assignments\assignment;
use local_taskflow\output\assignmentsdashboard\assignmentdataprovider;

/**
 * Display this element
 * @package local_taskflow
 *
 */
class supervisorassignmentsprovider implements assignmentdataprovider {
    /**
     * Constructor.
     *
     * @param int $userid
     * @param array $arguments
     *
     */
    public function __construct(private int $userid, private array $arguments) {}

    /**
     * get_assignmentsdashboard.
     */
    public function get_table_data(): array {
        $assignments = new assignment();
        [$select, $from, $where, $params] = $assignments->return_supervisor_assignments_sql(
            $this->userid,
            $this->arguments
        );
        return compact('select', 'from', 'where', 'params');
    }
}
