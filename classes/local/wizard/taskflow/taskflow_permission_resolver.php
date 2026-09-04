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

namespace local_taskflow\local\wizard\taskflow;

use context_system;
use local_taskflow\local\assignments\assignment;
use local_taskflow\local\supervisor\supervisor;

/**
 * Resolves the business scope (admin > supervisor > self > none) of a user on taskflow data.
 *
 * Rules mirror assignment.php / editassignment.php exactly:
 * - admin: has 'local/taskflow:viewassignment' in the system context (edit: 'editassignment');
 * - supervisor: supervisor::get_supervisor_for_user($target)->id == $userid, or $target is in
 *   supervisor::get_visible_subordinate_ids($userid) (direct subordinates + deputy delegation);
 * - self: the target user is the acting user.
 * Scope capabilities are deliberately NOT declared as native skill capabilities; skills call
 * this resolver in run_preflight() and answer SCOPE_NONE with TASKFLOW_SCOPE_DENIED.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class taskflow_permission_resolver {
    /** Full visibility granted by a taskflow admin capability. */
    public const SCOPE_ADMIN = 'admin';
    /** Visibility as (deputy) supervisor of the target user. */
    public const SCOPE_SUPERVISOR = 'supervisor';
    /** Visibility on the acting user's own data. */
    public const SCOPE_SELF = 'self';
    /** No visibility. */
    public const SCOPE_NONE = 'none';

    /** Capability granting admin read scope. */
    public const CAP_VIEW = 'local/taskflow:viewassignment';
    /** Capability granting admin edit scope. */
    public const CAP_EDIT = 'local/taskflow:editassignment';

    /** @var array<int,int[]> Per-request memo of visible subordinate ids by supervisor. */
    private array $subordinates = [];

    /**
     * Scope of $userid on the assignment.
     *
     * @param int $assignmentid
     * @param int $userid Acting user.
     * @return string One of the SCOPE_* constants; SCOPE_NONE for unknown assignments.
     */
    public function scope_for_assignment(int $assignmentid, int $userid): string {
        if ($this->is_admin($userid, self::CAP_VIEW)) {
            return self::SCOPE_ADMIN;
        }
        $assigneeid = $this->assignment_userid($assignmentid);
        if ($assigneeid <= 0) {
            return self::SCOPE_NONE;
        }
        return $this->scope_for_user($assigneeid, $userid);
    }

    /**
     * Scope of $userid on data of the target user.
     *
     * @param int $targetuserid
     * @param int $userid Acting user.
     * @return string One of the SCOPE_* constants.
     */
    public function scope_for_user(int $targetuserid, int $userid): string {
        if ($this->is_admin($userid, self::CAP_VIEW)) {
            return self::SCOPE_ADMIN;
        }
        if ($targetuserid > 0 && $userid > 0 && $this->is_supervisor_of($targetuserid, $userid)) {
            return self::SCOPE_SUPERVISOR;
        }
        if ($targetuserid > 0 && $targetuserid === $userid) {
            return self::SCOPE_SELF;
        }
        return self::SCOPE_NONE;
    }

    /**
     * User ids whose taskflow data $userid may read.
     *
     * @param int $userid Acting user.
     * @return int[]|null Null = unrestricted (admin); otherwise subordinates plus the user itself.
     */
    public function visible_userids(int $userid): ?array {
        if ($this->is_admin($userid, self::CAP_VIEW)) {
            return null;
        }
        $ids = $this->subordinate_ids($userid);
        if ($userid > 0 && !in_array($userid, $ids, true)) {
            $ids[] = $userid;
        }
        return array_values($ids);
    }

    /**
     * Whether $userid may edit the assignment (admin edit capability or supervisor).
     *
     * @param int $assignmentid
     * @param int $userid Acting user.
     * @return bool
     */
    public function can_edit_assignment(int $assignmentid, int $userid): bool {
        if ($this->is_admin($userid, self::CAP_EDIT)) {
            return true;
        }
        $assigneeid = $this->assignment_userid($assignmentid);
        return $assigneeid > 0 && $this->is_supervisor_of($assigneeid, $userid);
    }

    /**
     * Whether $userid holds the given taskflow capability in the system context.
     *
     * @param int $userid
     * @param string $capability
     * @return bool
     */
    public function is_admin(int $userid, string $capability = self::CAP_VIEW): bool {
        if ($userid <= 0) {
            return false;
        }
        return has_capability($capability, context_system::instance(), $userid);
    }

    /**
     * Whether $userid is the (deputy) supervisor of the target user.
     *
     * @param int $targetuserid
     * @param int $userid
     * @return bool
     */
    public function is_supervisor_of(int $targetuserid, int $userid): bool {
        if ($targetuserid <= 0 || $userid <= 0 || $targetuserid === $userid) {
            return false;
        }
        try {
            $supervisor = supervisor::get_supervisor_for_user($targetuserid);
        } catch (\Throwable $e) {
            $supervisor = null;
        }
        if (is_object($supervisor) && (int)($supervisor->id ?? 0) === $userid) {
            return true;
        }
        return in_array($targetuserid, $this->subordinate_ids($userid), true);
    }

    /**
     * Whether $userid is listed in the 'hrusers' setting.
     *
     * HR membership does not widen the read scope on its own; it is exposed for skills
     * that need to name the HR role (e.g. diagnostics) deterministically.
     *
     * @param int $userid
     * @return bool
     */
    public function is_hr_user(int $userid): bool {
        $setting = trim((string)get_config('local_taskflow', 'hrusers'));
        if ($userid <= 0 || $setting === '') {
            return false;
        }
        $ids = array_map('intval', array_filter(array_map('trim', explode(',', $setting)), 'is_numeric'));
        return in_array($userid, $ids, true);
    }

    /**
     * Subordinate ids visible to a supervisor (memoized per request).
     *
     * @param int $userid
     * @return int[]
     */
    private function subordinate_ids(int $userid): array {
        if ($userid <= 0) {
            return [];
        }
        if (!isset($this->subordinates[$userid])) {
            try {
                $this->subordinates[$userid] = array_values(array_map(
                    'intval',
                    supervisor::get_visible_subordinate_ids($userid)
                ));
            } catch (\Throwable $e) {
                $this->subordinates[$userid] = [];
            }
        }
        return $this->subordinates[$userid];
    }

    /**
     * Assignee id of an assignment, 0 when it does not exist.
     *
     * @param int $assignmentid
     * @return int
     */
    private function assignment_userid(int $assignmentid): int {
        if ($assignmentid <= 0) {
            return 0;
        }
        try {
            $instance = assignment::get_instance($assignmentid);
        } catch (\Throwable $e) {
            return 0;
        }
        if (empty($instance->id)) {
            return 0;
        }
        return (int)($instance->userid ?? 0);
    }
}
