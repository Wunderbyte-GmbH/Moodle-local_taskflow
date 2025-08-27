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
use local_taskflow\local\assignment_operators\action_operator;
use local_taskflow\local\assignments\assignments_facade;
use local_taskflow\local\assignments\status\assignment_status;
use local_taskflow\local\history\types\typesfactory;
use local_taskflow\local\rules\rules;

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
     * @param string $targettype
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
     * @param array $eventdata
     * @return void
     */
    public function handle_completion_process($eventdata = null) {
        $affectedassignments = $this->get_all_affected_assignments();
        foreach ($affectedassignments as $affectedassignment) {
            $targets = json_decode($affectedassignment->targets);
            // Here we apply the following logic.
            // The completion check is only the last resort.
            // First we check if the event is enrollment.
            // If so, we set the status to enrollment, if the current status is lower than enrollment.
            if (
                $eventdata
                && "\\mod_booking\\event\\bookingoption_booked" === $eventdata['eventname']
            ) {
                // If the current status is lower than enrollment, we set it to enrollment.
                if ($affectedassignment->status < assignment_status::STATUS_ENROLLED) {
                    $affectedassignment->status = assignment_status::STATUS_ENROLLED;
                    $affectedassignment->completeddate = time();
                    assignments_facade::update_or_create_assignment($affectedassignment);
                }
            } else {
                [$newstatus, $targetstatuschange] = $this->get_assignment_status($targets, $affectedassignment);
                $affectedassignment->targets = json_encode($targets);
                if (
                    $newstatus != $affectedassignment->status ||
                    $targetstatuschange
                ) {
                    $affectedassignment->status = $newstatus;
                    if ($newstatus == assignment_status::STATUS_COMPLETED) {
                        $affectedassignment->completeddate = time();
                    }
                    assignments_facade::update_or_create_assignment($affectedassignment);
                }
            }
            // Check if any event was connected which needs to be logged.
            if ($eventdata && $eventdata['other'] && $eventdata['other']['targettype']) {
                $historytype = typesfactory::create($eventdata['other']['targettype'], json_encode($eventdata));
                $historytype->log($affectedassignment);
            }
            $assignmentaction = new action_operator($affectedassignment->userid);
            $assignmentaction->check_and_trigger_targets($affectedassignment);
        }
        return;
    }

    /**
     * Update the current unit.
     * @param object $targets
     * @param object $affectedassignment
     * @return array
     */
    public function get_assignment_status(&$targets, $affectedassignment) {
        $completedtargets = 0;
        $targetstatuschange = false;
        foreach ($targets as $target) {
            $classname = self::PREFIX . $target->targettype;
            $oldtargetstatus = $target->completionstatus ?? 0;
            if (class_exists($classname)) {
                $instance = new $classname($target->targetid, $this->userid, $target->targettype);
                if ($instance->is_completed()) {
                    $completedtargets++;
                    $target->completionstatus = 1;
                } else {
                    $target->completionstatus = 0;
                }
            }
            if (
                !empty($target->completionstatus) &&
                $oldtargetstatus != $target->completionstatus
            ) {
                $targetstatuschange = true;
            }
        }
        $targetsnumber = count($targets);
        return [
            $this->set_stauts(
                $completedtargets,
                $targetsnumber,
                $affectedassignment
            ),
            $targetstatuschange,
        ];
    }

    /**
     * Get single target status.
     * This function checks if a single target is completed.
     * @return bool
     */
    public function is_target_completed(): bool {
        $classname = self::PREFIX . $this->targettype;
        if (class_exists($classname)) {
            $instance = new $classname($this->targetid, $this->userid, $this->targettype);
            if ($instance->is_completed()) {
                return true;
            }
        }
        return false;
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
     * @param int $completedtargets
     * @param int $targetsnumber
     * @param object $affectedassignment
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
            $event->trigger();
        } else if ($completedtargets > 0) {
            $status = assignment_status::STATUS_PARTIALLY_COMPLETED;
        } else if ($completedtargets == 0) {
            $status = assignment_status::STATUS_ASSIGNED;
        }
        return $status;
    }
}
