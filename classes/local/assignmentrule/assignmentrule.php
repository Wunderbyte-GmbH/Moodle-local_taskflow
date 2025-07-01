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

namespace local_taskflow\local\assignmentrule;

use stdClass;

/**
 * Class unit
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignmentrule {
    /** @var stdClass */
    private stdClass $rule;

    /**
     * Constructor for the assignment class.
     * @param int $rule
     */
    public function __construct(int $assignmentid = 0) {
        global $DB;
        $sql = "SELECT ta.id AS assignmentid, ta.userid, tr.rulejson, tr.id AS ruleid
            FROM {local_taskflow_assignment} ta
            JOIN {local_taskflow_rules} tr ON ta.ruleid = tr.id
            WHERE ta.id = :assignmentid";

        $params = ['assignmentid' => $assignmentid];
        $record = $DB->get_record_sql($sql, $params, MUST_EXIST);
        $record->rulejson = json_decode($record->rulejson);
        $this->rule = $record;
    }

    /**
     * Constructor for the assignment class.
     * @return stdClass
     */
    public function get_rule() {
        return $this->rule;
    }

    /**
     * Constructor for the assignment class.
     * @return bool
     */
    public function is_cyclic() {
        $cyclicvalidation = $this->rule->rulejson->rulejson->rule->cyclicvalidation ?? '';
        if ($cyclicvalidation == '1') {
            return true;
        }
        return false;
    }
}
