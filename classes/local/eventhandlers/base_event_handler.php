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
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\local\eventhandlers;

use local_taskflow\local\assignment_process\filters\filters_controller;
use local_taskflow\local\assignment_process\assignments\assignments_controller;
use local_taskflow\local\assignment_process\assignment_controller;
use local_taskflow\local\rules\unit_rules;
use local_taskflow\local\unassignment_process\unassignments\unassignment_controller;
use local_taskflow\local\units\unit_relations;


/**
 * Class user_updated event handler.
 *
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class base_event_handler {
    /**
     * React on the triggered event.
     * @param int $unitid
     * @return array
     */
    protected function get_inheritance_units($unitid): array {
        return [$unitid];
        $inheritanceunits = [];
        while ($unitid) {
            $unitrelationinstance = unit_relations::instance($unitid);
            $unitid = $unitrelationinstance->get_parentid();
            if ($unitid) {
                $inheritanceunits[] = $unitid;
            }
        }
        return $inheritanceunits;
    }

    /**
     * React on the triggered event.
     * @param array $unitids
     * @return array
     */
    protected function get_all_affected_users($unitids): array {
        global $DB;
        [$insql, $inparams] = $DB->get_in_or_equal($unitids, SQL_PARAMS_NAMED);

        $sql = "SELECT DISTINCT userid
                  FROM {local_taskflow_unit_members}
                 WHERE unitid $insql
                 AND active = '1'";

        $userrecords = $DB->get_records_sql($sql, $inparams);
        return array_keys($userrecords);
    }

    /**
     * React on the triggered event.
     * @param array $unitids
     * @return array
     */
    protected function get_all_affected_rules($unitids): array {
        global $DB;
        $rules = [];
        foreach ($unitids as $unit) {
            $rules[] = unit_rules::instance($unit);
        }
        return $rules;
    }

    /**
     * React on the triggered event.
     * @param array $allaffectedusers
     * @param array $allaffectedrules
     * @return void
     */
    protected function process_assignemnts($allaffectedusers, $allaffectedrules): void {
        $assignment = new assignments_controller();
        $filter = new filters_controller();

        $controller = new assignment_controller(
            $allaffectedusers,
            $allaffectedrules,
            $filter,
            $assignment
        );

        $controller->process_assignments();
    }

    /**
     * React on the triggered event.
     * @param array $allaffectedunits
     * @param array $allaffectedusers
     * @return void
     */
    protected function process_unassignemnts($allaffectedunits, $allaffectedusers): void {
        $controller = new unassignment_controller(
            $allaffectedunits,
            $allaffectedusers
        );
        $controller->process_unassignments();
    }
}
