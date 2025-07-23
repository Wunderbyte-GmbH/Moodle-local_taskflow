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

namespace local_taskflow\local\assignment_process;

use local_taskflow\local\assignment_process\assignments\assignments_controller;
use local_taskflow\local\assignment_process\filters\filters_controller;
use local_taskflow\local\rules\rules;
use local_taskflow\local\rules\unit_rules;
use local_taskflow\local\unassignment_process\unassignments\unassignment_controller;

/**
 * Class user_updated event handler.
 *
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignment_preprocessor {
    /** @var array Stores the external user data. */
    protected array $data;
    /** @var array Stores the external user data. */
    protected array $allaffectedusers;
    /** @var array Stores the external user data. */
    protected array $allaffectedrules;
    /** @var array Stores the external user data. */
    protected array $allaffectedunits;

    /**
     * Private constructor to prevent direct instantiation.
     * @param array $data
     */
    public function __construct(
        array $data
    ) {
        $this->data = $data;
        $this->allaffectedusers = [];
        $this->allaffectedrules = [];
        $this->allaffectedunits = [];
    }

    /**
     * React on the triggered event.
     * @return void
     */
    public function set_all_affected_users(): void {
        if ($this->data['unitid']) {
            $this->allaffectedusers = $this->get_unit_users();
            return;
        }
        $this->allaffectedusers = [$this->data['userid']];
        return;
    }

    /**
     * React on the triggered event.
     * @return void
     */
    public function set_all_inheritance_unit_rules(): void {
        $this->data['unitid'] = $this->get_inheritance_units();
        $this->data['relateduserid'] = $this->data['other']['unitmemberid'];
        $this->set_all_user_affected_rules();
        return;
    }

    /**
     * React on the triggered event.
     * @return array
     */
    private function get_inheritance_units(): array {
        // TODO MDL-123: Calculate all inheritaged rules.
        return [$this->data['other']['unitid']];
    }

    /**
     * React on the triggered event.
     * @return array
     */
    public function set_inheritance_units(): void {
        // TODO MDL-123: Calculate all inheritaged rules.
        $this->allaffectedunits = [$this->data['other']['unitid']];
        return;
    }

    /**
     * React on the triggered event.
     * @return void
     */
    public function set_all_user_affected_rules(): void {
        $rules = [];
        $unitids = $this->get_all_units_of_user();
        foreach ($unitids as $unit) {
            $rules[] = unit_rules::instance($unit);
        }
        $this->allaffectedrules = $rules;
        return;
    }

    /**
     * React on the triggered event.
     * @return void
     */
    public function set_all_affected_rules(): void {
        $rules = [];
        foreach ($this->data['unitid'] as $unit) {
            $rules[] = unit_rules::instance($unit);
        }
        $this->allaffectedrules = $rules;
        return;
    }

    /**
     * React on the triggered event.
     * @return array
     */
    private function get_all_units_of_user(): array {
        global $DB;
        return array_keys(
            $DB->get_records(
                'local_taskflow_unit_members',
                [
                    'userid' => $this->data['relateduserid'],
                ],
                null,
                'unitid'
            )
        );
    }

    /**
     * React on the triggered event.
     * @return void
     */
    public function set_this_rules(): void {
        $this->allaffectedrules = [[rules::instance($this->data['id'])]];
        return;
    }

    /**
     * React on the triggered event.
     * @return void
     */
    public function set_this_user($userid): void {
        $this->allaffectedusers = [$userid];
        return;
    }

    /**
     * React on the triggered event.
     * @return array
     */
    private function get_unit_users(): array {
        global $DB;
        $unitids = $this->data['unitid'];
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
     * @return void
     */
    public function process_assignemnts(): void {
        $assignment = new assignments_controller();
        $filter = new filters_controller();

        $controller = new assignment_controller(
            $this->allaffectedusers,
            $this->allaffectedrules,
            $filter,
            $assignment
        );

        $controller->process_assignments();
    }

    /**
     * React on the triggered event.
     * @return void
     */
    public function process_unassignemnts(): void {
        $controller = new unassignment_controller(
            $this->allaffectedunits,
            $this->allaffectedusers
        );
        $controller->process_unassignments();
    }
}
