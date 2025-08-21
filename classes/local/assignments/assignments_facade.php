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

namespace local_taskflow\local\assignments;

use cache_helper;
use local_taskflow\local\actions\types\unenroll;
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\assignments\status\assignment_status;
use local_taskflow\local\assignments\types\standard_assignment;
use local_taskflow\local\personas\unit_members\types\unit_member;

/**
 * Class unit
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignments_facade {
    /**
     * Factory for the organisational units
     * @param mixed $record
     * @return int
     */
    public static function update_or_create_assignment(mixed $record) {
        return standard_assignment::update_or_create_assignment((object) $record);
    }

    /**
     * Factory for the organisational units
     * @param array $ruleids
     * @param int $userid
     * @return bool
     */
    public static function delete_assignments($ruleids, $userid) {
        return standard_assignment::delete_assignments($ruleids, $userid);
    }

    /**
     * Factory for the organisational units
     * @param int $userid
     * @return void
     */
    public static function set_all_assignments_inactive($userid) {
        $assignments = standard_assignment::get_all_active_user_assignments($userid);
        foreach ($assignments as $assignment) {
            assignment_status_facade::change_status($assignment, assignment_status::STATUS_PAUSED);
            $assignment->timemodified = time();
            standard_assignment::update_or_create_assignment((object) $assignment);
        }
        unit_member::inactivate_all_active_units_of_user($userid);
        return;
    }

    /**
     * Factory for the organisational units
     * @param int $userid
     * @return void
     */
    public static function set_all_assignments_of_user_to_status($userid, $status) {
        $assignments = standard_assignment::get_all_user_assignments_except_state(
            $userid,
            $status
        );
        foreach ($assignments as $assignment) {
            assignment_status_facade::change_status($assignment, $status);
            $assignment->timemodified = time();
            standard_assignment::update_or_create_assignment((object) $assignment);
        }
        unit_member::inactivate_all_active_units_of_user($userid);
        return;
    }

    /**
     * Factory for the organisational units
     * @param int $userid
     * @return void
     */
    public static function set_all_assignments_active($userid) {
        $assignments = standard_assignment::get_all_inactive_user_assignments($userid);
        foreach ($assignments as $assignment) {
            assignment_status_facade::change_status($assignment, assignment_status::STATUS_ASSIGNED);
            $assignment->duedate = null;
            standard_assignment::update_or_create_assignment((object) $assignment);
        }
        unit_member::inactivate_all_active_units_of_user($userid);
        return;
    }

    /**
     * Factory for the organisational units
     * @param int $assignmentid
     * @return int
     */
    public static function toggle_assignment_active($assignmentid) {
        $assignment = standard_assignment::get_assignment_record_by_assignmentid($assignmentid);
        $assignment->active = $assignment->active < 1 ? 1 : 0;
        standard_assignment::update_or_create_assignment((object)$assignment);
        cache_helper::purge_by_event('changesinassignmentslist');
        return $assignment->active;
    }

    /**
     * Factory for the organisational units
     * @param int $userid
     * @param array $invalidunits
     * @return void
     */
    public static function set_user_units_assignments_inactive($userid, $invalidunits) {
        $assignments = standard_assignment::get_all_invalid_unit_user_assignments($userid, $invalidunits);
        foreach ($assignments as $assignment) {
            $assignment->active = 0;
            $assignment->timemodified = time();
            standard_assignment::update_or_create_assignment((object) $assignment);
        }
        unit_member::inactivate_invalid_units_of_user($userid, $invalidunits);
        return;
    }

    /**
     * Factory for the organisational units
     * @param object $assignment
     * @return void
     */
    public static function reopen_assignment($assignment) {
        $unenrollmanagement = new unenroll($assignment);
        $unenrollmanagement->execute();
        assignment_status_facade::change_status($assignment, assignment_status::STATUS_ASSIGNED);
        standard_assignment::update_or_create_assignment((object)$assignment);
        return;
    }

    /**
     * Checks a given assignemnt and sets the status to overdue, if necessary.
     * Returns the new status of the assigment.
     * @param int $assignmentid
     * @return void
     */
    public static function check_and_update_overdue_assignment(int $assignmentid) {

        // Mitdenken Sanktion und andere stati.
        $assignment = standard_assignment::get_assignment_record_by_assignmentid($assignmentid);
        if (
            $assignment &&
            $assignment->status < assignment_status::STATUS_COMPLETED &&
            $assignment->duedate < time()
        ) {
            assignment_status_facade::change_status($assignment, assignment_status::STATUS_OVERDUE);
            $assignment->timemodified = time();
            standard_assignment::update_or_create_assignment((object)$assignment);
        }
    }
}
