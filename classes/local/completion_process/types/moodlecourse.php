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

/**
 * Class unit
 *
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodlecourse extends types_base implements types_interface {
    /**
     * Update the current unit.
     * @return bool
     */
    public function is_completed() {
        global $DB, $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        if (!$DB->record_exists('course', ['id' => $this->targetid])) {
            return false;
        }
        $course = get_course($this->targetid);
        $completion = new \completion_info($course);

        if ($completion->is_enabled()) {
            $iscomplete = $completion->is_course_complete($this->userid);
            if ($iscomplete) {
                return true;
            }
        }
        return false;
    }
}
