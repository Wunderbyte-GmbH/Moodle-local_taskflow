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

namespace local_taskflow\local\completion_process;

use local_taskflow\event\assignment_completed;
use local_taskflow\local\assignments\assignments_facade;
use local_taskflow\local\assignments\status\assignment_status;

/**
 * Class unit
 *
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class completion_operator {
    /** @var string Stores the external user data. */
    protected string $targetid;

    /** @var string Stores the external user data. */
    protected string $userid;

    /** @var string Stores the external user data. */
    protected string $targettype;

    /** @var string Event name for user updated. */
    private const PREFIX = 'local_taskflow\\local\\completion_process\\types\\';

    /**
     * Update the current unit.
     * @param int $targetid
     * @param int $userid
     * @param int $targettype
     * @return void
     */
    public function __construct(
        $targetid,
        $userid,
        $targettype
    ) {
        $this->targetid = $targetid;
        $this->userid = $userid;
        $this->targettype = $targettype;
    }

    /**
     * Update the current unit.
     * @return void
     */
    public function handle_completion_process() {
        $affectedassignments = $this->get_all_affected_assignments();
        foreach ($affectedassignments as $affectedassignment) {
            $targets = json_decode($affectedassignment->targets);
            $newstatus = $this->get_assignment_status($targets, $affectedassignment);
            if ($newstatus != $affectedassignment->status) {
                $affectedassignment->status = $newstatus;
                assignments_facade::update_or_create_assignment($affectedassignment);
            }
        }
        return;
    }

    /**
     * Update the current unit.
     * @param object $targets
     * @param object $affectedassignment
     * @return string
     */
    public function get_assignment_status($targets, $affectedassignment) {
        $completedtargets = 0;
        foreach ($targets as $target) {
            $classname = self::PREFIX . $target->targettype;
            if (class_exists($classname)) {
                $instance = new $classname($target->targetid, $this->userid, $target->targettype);
                if ($instance->is_completed()) {
                    $completedtargets++;
                }
            }
        }
        $targetsnumber = count($targets);
        return $this->set_stauts(
            $completedtargets,
            $targetsnumber,
            $affectedassignment
        );
    }

    /**
     * Update the current unit.
     * @return array
     */
    private function get_all_affected_assignments() {
        $assignments = [];
        $classname = self::PREFIX . $this->targettype;
        if (class_exists($classname)) {
            $instance = new $classname($this->targetid, $this->userid, $this->targettype);
            $assignments = $instance->get_all_active_assignemnts(
                $this->targetid,
                $this->userid
            );
        }

        return $assignments;
    }

    /**
     * Update the current unit.
     * @return int $completedtargets
     * @return int $targetsnumber
     * @return object $affectedassignment
     * @return string
     */
    private function set_stauts($completedtargets, $targetsnumber, $affectedassignment) {
        $status = $affectedassignment->status;
        if ($completedtargets == $targetsnumber) {
            $status = assignment_status::STATUS_COMPLETED;
            $event = assignment_completed::create([
                'objectid' => $affectedassignment->id,
                'context'  => \context_system::instance(),
                'other'    => [
                    'assignmentid' => $affectedassignment->id,
                ],
            ]);
            \local_taskflow\observer::call_event_handler($event);
        } else if ($completedtargets > 0) {
            $status = assignment_status::STATUS_PARTIALLY_COMPLETED;
        }
        return $status;
    }
}
