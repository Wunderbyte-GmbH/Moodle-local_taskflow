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
 * Unit class to manage users.
 *
 * @package local_taskflow
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\local\assignment_operators;

/**
 * Class unit
 *
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignment_operator {
    /** @var string Event name for user updated. */
    private const TABLE = 'local_taskflow_assignment';

    /**
     * Update the current unit.
     * @return array
     */
    public function get_open_and_active_assignments() {
        global $DB;
        $sql = "
            SELECT assign.id, assign.userid, assign.ruleid, rules.rulejson
            FROM {" . self::TABLE . "} assign
            JOIN {local_taskflow_rules} rules ON rules.id = assign.ruleid
            WHERE rules.isactive = :isactive
                AND assign.assigneddate IS NULL
                AND assign.active = :active
        ";
        $params = [
            'isactive' => '1',
            'active' => '1',
        ];
        return $DB->get_records_sql($sql, $params);
    }
}
