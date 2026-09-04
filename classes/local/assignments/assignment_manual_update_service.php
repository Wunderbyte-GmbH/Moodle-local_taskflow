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
 * Service for manual (human triggered) changes on a single assignment.
 *
 * @package local_taskflow
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\local\assignments;

use cache_helper;
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\history\history;
use local_taskflow\taskflow_stringmanager;
use moodle_exception;
use stdClass;

/**
 * Encapsulates every manual change on an assignment.
 *
 * The adapter forms (taskflowadapter_standard\form\editassignment,
 * taskflowadapter_tuines\form\editassignment_admin and
 * taskflowadapter_tuines\form\editassignment_supervisor) are thin wrappers around
 * this service, so that agent skills and the UI run exactly the same code.
 *
 * @package local_taskflow
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignment_manual_update_service {
    /**
     * Applies a manual change (status, due date, keepchanges, comment) to an assignment.
     *
     * Supported keys in $changes:
     * - status (int) target status identifier.
     * - respectexcluded (bool) when true, a status that the adapter setting excludestatus
     *   lists is not applied at all. Off by default, because the forms only offer
     *   assignment_status_facade::get_all_wanted_stati() anyway.
     * - duedate (int) new due date timestamp.
     * - keepchanges (int|bool) protect the manual change against imports.
     * - change_reason (int) one of assignment_status::CHANGEREASON_*.
     * - userid (int) written back with the record (the forms submit it as a hidden field).
     *   Defaults to the userid of the assignment.
     * - runstatustransition (bool) when true, the status type class transition
     *   (assignment_status_facade::change_status) is applied on the record before
     *   it is written. Used by the tuines admin form for prolonged / overdue.
     * - annotation (string) overrides the history annotation.
     *
     * @param int $assignmentid
     * @param array $changes
     * @param int $actorid
     * @param string|null $comment
     * @return stdClass The updated assignment data.
     */
    public function apply(int $assignmentid, array $changes, int $actorid, ?string $comment = null): stdClass {
        $assignment = $this->get_assignment($assignmentid);
        $data = $this->build_base_record($assignment, $actorid);

        if ($comment !== null) {
            $data->comment = $comment;
        }
        if (isset($changes['change_reason'])) {
            $data->change_reason = $changes['change_reason'];
        }
        if (isset($changes['userid'])) {
            // Legacy pass-through: the adapter forms submit the userid of the assignment
            // as a hidden field and it has always been written back with the record.
            $data->userid = (int)$changes['userid'];
        }
        if (array_key_exists('keepchanges', $changes)) {
            $data->keepchanges = (int)(bool)$changes['keepchanges'];
        }
        if (array_key_exists('duedate', $changes) && $changes['duedate'] !== null) {
            $data->duedate = (int)$changes['duedate'];
        }

        $currentstatus = (int)$assignment->status;

        if (array_key_exists('status', $changes) && $changes['status'] !== null && $changes['status'] !== '') {
            $targetstatus = (int)$changes['status'];
            $excluded = !empty($changes['respectexcluded'])
                && assignment_status_facade::check_excluded((string)$targetstatus);
            if (!$excluded) {
                // The status is set first, because the status type classes decide on the
                // basis of the new status (this is what the form data looked like before).
                $data->status = $targetstatus;
                if (!empty($changes['runstatustransition'])) {
                    assignment_status_facade::change_status($data, $targetstatus);
                }
            }
        }

        $this->apply_prolonged_state_on_duedate_change($data, $currentstatus);

        $annotation = $changes['annotation'] ?? null;
        if ($annotation === null) {
            $historytype = history::TYPE_MANUAL_CHANGE;
            $annotation = taskflow_stringmanager::get_string("status:$historytype") . ': ' . ($comment ?? '');
        }

        return $this->write($assignment, $data, $actorid, (string)$annotation);
    }

    /**
     * Grants an extension of the due date (supervisor decision on an extension request).
     *
     * Mirrors taskflowadapter_tuines\form\editassignment_supervisor, extension branch:
     * the assignment goes to the status prolonged (which increases the prolongedcounter
     * when the due date really moves into the future) and the history annotation carries
     * the request_confirmed label.
     *
     * @param int $assignmentid
     * @param int $newduedate
     * @param int $actorid
     * @param string|null $comment
     * @param int|null $changereason One of assignment_status::CHANGEREASON_*.
     * @return stdClass The updated assignment data.
     */
    public function grant_extension(
        int $assignmentid,
        int $newduedate,
        int $actorid,
        ?string $comment = null,
        ?int $changereason = null
    ): stdClass {
        $assignment = $this->get_assignment($assignmentid);
        $data = $this->build_base_record($assignment, $actorid);
        $data->duedate = $newduedate;
        $data->comment = (string)$comment;
        if ($changereason !== null) {
            $data->change_reason = $changereason;
        }

        assignment_status_facade::change_status(
            $data,
            assignment_status_facade::get_status_identifier('prolonged')
        );

        $historytype = history::TYPE_REQUEST_CONFIRMED;
        $annotation = taskflow_stringmanager::get_string("status:$historytype") . ': ' . (string)$comment;

        cache_helper::purge_by_event('changesinrequestslist');

        return $this->write($assignment, $data, $actorid, $annotation);
    }

    /**
     * Denies an extension request. The due date and the status stay untouched, but the
     * prolongedcounter is increased, so that the limit of granted extensions is tracked.
     *
     * Mirrors taskflowadapter_tuines\form\editassignment_supervisor, declined branch.
     *
     * @param int $assignmentid
     * @param int $actorid
     * @param string|null $comment
     * @return stdClass The updated assignment data.
     */
    public function deny_extension(int $assignmentid, int $actorid, ?string $comment = null): stdClass {
        $assignment = $this->get_assignment($assignmentid);
        $data = $this->build_base_record($assignment, $actorid);
        $data->duedate = $assignment->duedate;
        $data->status = (int)$assignment->status;
        $data->active = $assignment->active;
        $data->prolongedcounter = (int)$assignment->prolongedcounter + 1;
        $data->comment = (string)$comment;

        $historytype = history::TYPE_REQUEST_DECLINED;
        $annotation = taskflow_stringmanager::get_string("status:$historytype") . ': ' . (string)$comment;

        cache_helper::purge_by_event('changesinrequestslist');

        return $this->write($assignment, $data, $actorid, $annotation);
    }

    /**
     * Returns the assignment instance and makes sure it really exists.
     *
     * @param int $assignmentid
     * @return assignment
     */
    private function get_assignment(int $assignmentid): assignment {
        $assignment = assignment::get_instance($assignmentid);
        if (empty($assignment->id)) {
            throw new moodle_exception('assignmentnotfound', 'local_taskflow', '', $assignmentid);
        }
        return $assignment;
    }

    /**
     * Builds the record that is handed over to assignment::add_or_update_assignment().
     *
     * It contains exactly the fields the adapter forms submit today, so that the
     * behaviour of the forms does not change.
     *
     * @param assignment $assignment
     * @param int $actorid
     * @return stdClass
     */
    private function build_base_record(assignment $assignment, int $actorid): stdClass {
        return (object)[
            'id' => (int)$assignment->id,
            'userid' => (int)$assignment->userid,
            'ruleid' => (int)$assignment->ruleid,
            'status' => (int)$assignment->status,
            'duedate' => $assignment->duedate,
            'keepchanges' => (int)$assignment->keepchanges,
            'overduecounter' => (int)$assignment->overduecounter,
            'prolongedcounter' => (int)$assignment->prolongedcounter,
            'usermodified' => $actorid,
            'useridmodified' => $actorid,
        ];
    }

    /**
     * An overdue assignment whose due date is moved into the future becomes prolonged.
     *
     * This is the intended behaviour of assignment::set_prolonged_state_on_change(),
     * which cannot work there because it hands a status label to a method that expects
     * a status identifier (and an array to a method that expects an object).
     *
     * @param stdClass $data
     * @param int $currentstatus
     * @return void
     */
    private function apply_prolonged_state_on_duedate_change(stdClass $data, int $currentstatus): void {
        $prolonged = assignment_status_facade::get_status_identifier('prolonged');
        if (
            $currentstatus !== assignment_status_facade::get_status_identifier('overdue')
            || (int)$data->status === $prolonged
            || empty($data->duedate)
            || $data->duedate <= time()
        ) {
            return;
        }
        assignment_status_facade::change_status($data, $prolonged);
    }

    /**
     * Logs the history entry, persists the record and invalidates the caches.
     *
     * @param assignment $assignment
     * @param stdClass $data
     * @param int $actorid
     * @param string $annotation
     * @return stdClass
     */
    private function write(assignment $assignment, stdClass $data, int $actorid, string $annotation): stdClass {
        history::log(
            $assignment->id,
            $assignment->userid,
            history::TYPE_MANUAL_CHANGE,
            [
                'action' => 'updated',
                'data' => (array)$data,
            ],
            $actorid,
            $annotation
        );

        $result = $assignment->add_or_update_assignment((array)$data, history::TYPE_MANUAL_CHANGE, true);
        assignment::destroy_instance((int)$data->id);
        return $result;
    }
}
