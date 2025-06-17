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

use local_taskflow\local\completion_process\types\moodlecourse;

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
            $testing = 'tesint';
        }
        return;
    }

    /**
     * Update the current unit.
     * @return array
     */
    private function get_all_affected_assignments() {
        $assignments = [];
        $classname = self::PREFIX . $this->targettype;
        if (class_exists($classname)) {
            $instance = new $classname($this->targetid, $this->userid);
            $assignments = $instance->get_all_active_assignemnts($this->targetid, $this->userid);
        }

        return $assignments;
    }
}
