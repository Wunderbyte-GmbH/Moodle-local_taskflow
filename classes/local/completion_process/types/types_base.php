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

namespace local_taskflow\local\completion_process\types;

use local_taskflow\local\assignments\status\assignment_status;
use local_taskflow\singleton_service;

/**
 * Class unit
 *
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class types_base {
    /** @var string Stores the external user data. */
    protected string $targetid;

    /** @var string Stores the external user data. */
    protected string $userid;

    /** @var string Stores the external user data. */
    protected string $type;

    /**
     * Update the current unit.
     * @param int $targetid
     * @param int $userid
     * @param int $type
     * @return bool
     */
    public function __construct($targetid, $userid, $type) {
        $this->targetid = $targetid;
        $this->userid = $userid;
        $this->type = $type;
    }

    /**
     * Update the current unit.
     * @return array
     */
    public function get_all_active_assignemnts() {
        global $DB;
        $sql = "
            SELECT *
            FROM {local_taskflow_assignment}
            WHERE userid = :userid
            AND active = :active
            AND status != :status
        ";

        $params = [
            'userid' => $this->userid,
            'active' => 1,
            'status' => assignment_status::STATUS_COMPLETED,
        ];

        $allassignments = $DB->get_records_sql($sql, $params);
        $assignments = $this->filter_affected_assignments($allassignments);
        return $assignments;
    }

    /**
     * Update the current unit.
     * @param array $allassignments
     * @return array
     */
    private function filter_affected_assignments($allassignments) {
        $assignments = [];

        foreach ($allassignments as $assignment) {
            $targets = json_decode($assignment->targets);
            foreach ($targets as $target) {
                // First, look for an assignment that matches the targetid and type.
                if (
                    $target->targetid == $this->targetid &&
                    $target->targettype == $this->type
                ) {
                    $assignments[] = $assignment;
                    continue;
                }
                // If there is no match, we still check for competency.
                if (
                    $target->targettype == 'competency'
                    && $this->type == 'bookingoption'
                    && class_exists('mod_booking\\singleton_service')
                ) {
                    $settings = \mod_booking\singleton_service::get_instance_of_booking_option_settings($this->targetid);
                    $competencies = explode(',', $settings->competencies ?? '');
                    if (in_array($target->targetid, $competencies)) {
                        $assignments[] = $assignment;
                        continue;
                    }
                }
            }
        }





        return $assignments;
    }
}
