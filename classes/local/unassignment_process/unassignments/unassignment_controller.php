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

 namespace local_taskflow\local\unassignment_process\unassignments;

 use local_taskflow\local\assignments\assignments_facade;
 use local_taskflow\local\personas\unit_members\moodle_unit_member_facade;

/**
 * Repository for dependecy injection
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class unassignment_controller {
    /** @var array Stores the external user data. */
    protected array $allaffectedunits;

    /** @var array Stores the external user data. */
    protected array $allaffectedrules;

    /** @var array Stores the external user data. */
    protected array $allaffectedusers;

    /** @var moodle_unit_member_facade Stores the external user data. */
    protected moodle_unit_member_facade $unitmemberrepository;

    /**
     * Private constructor to prevent direct instantiation.
     * @param array $allaffectedunits
     * @param array $allaffectedrules
     * @param array $allaffectedusers
     */
    public function __construct(
        array $allaffectedunits,
        array $allaffectedrules,
        array $allaffectedusers
    ) {
        $this->allaffectedunits = $allaffectedunits;
        $this->allaffectedrules = $this->get_rule_ids($allaffectedrules);
        $this->allaffectedusers = $allaffectedusers;
        $this->unitmemberrepository = new moodle_unit_member_facade();
    }

    /**
     * Updates or creates unit member
     * @param array $allaffectedrules
     * @return array
     */
    private function get_rule_ids($allaffectedrules): array {
        $ruleids = [];
        foreach ($allaffectedrules as $rule) {
            $ruleids[] = $rule->get_id();
        }
        return $ruleids;
    }

    /**
     * Updates or creates unit member
     * @return void
     */
    public function process_unassignments(): void {
        foreach ($this->allaffectedunits as $unitid) {
            foreach ($this->allaffectedusers as $userid) {
                $this->unitmemberrepository->remove($userid, $unitid);
            }
            assignments_facade::delete_assignments($this->allaffectedrules, $userid);
        }
    }

    /**
     * Updates or creates unit member
     * @param string $ruleid
     * @return void
     */
    public function process_ruledeletion($ruleid): void {
        global $DB;
        $DB->delete_records(
            'local_taskflow_assignment',
            [
                'ruleid' => $ruleid,
            ]
        );
        $DB->delete_records(
            'local_taskflow_rules',
            [
                'id' => $ruleid,
            ]
        );
    }
}
