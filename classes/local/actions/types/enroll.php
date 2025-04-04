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

namespace local_taskflow\local\actions\types;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/enrol/externallib.php');
require_once($CFG->libdir . '/enrollib.php');

use context_course;
use local_taskflow\local\actions\actions_interface;
use moodle_exception;
use stdClass;

/**
 * Class unit
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enroll implements actions_interface {
    /** @var stdClass Event name for user updated. */
    public stdClass $target;

    /** @var int Event name for user updated. */
    public int $userid;

    /** @var mixed Event name for user updated. */
    public mixed $manualinstance;

    /**
     * Factory for the organisational units
     * @param stdClass $target
     * @param int $userid
     */
    public function __construct($target, $userid) {
        $this->target = $target;
        $this->userid = $userid;
        $this->manualinstance = null;
    }

    /**
     * Factory for the organisational units
     * @return bool
     */
    public function is_active() {
        global $DB;
        $courseid = $this->target->targetid;

        if (!$DB->record_exists('course', ['id' => $courseid])) {
            return false;
        }

        $context = context_course::instance($courseid);
        $isenrolled = is_enrolled($context, $this->userid);
        if ($isenrolled) {
            return false;
        }

        $instances = enrol_get_instances($courseid, true);
        foreach ($instances as $instance) {
            if ($instance->enrol === 'manual') {
                $this->manualinstance = $instance;
                return true;
            }
        }
        return false;
    }

    /**
     * Factory for the organisational units
     * @return void
     */
    public function execute() {
        $enrol = enrol_get_plugin('manual');
        if ($this->manualinstance !== null) {
            $enrol->enrol_user(
                $this->manualinstance,
                $this->userid
            );
        } else {
            throw new moodle_exception('No manual enrolment method found for course.');
        }
    }
}
