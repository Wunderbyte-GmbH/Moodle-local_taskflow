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

use mod_booking\bo_availability\bo_info;
use mod_booking\booking_bookit;
use mod_booking\singleton_service;

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
     * Factory for the organisational units.
     * @param stdClass $target
     * @param int $userid
     */
    public function __construct($target, $userid) {
        $this->target = $target;
        $this->userid = $userid;
        $this->manualinstance = null;
    }

    /**
     * Factory for the organisational units.
     * @return bool
     */
    public function is_active() {
        global $DB;
        switch ($this->target->targettype) {
            case 'course':
            case 'moodlecourse':
                // Check if the course exists.
                return $this->is_active_course();
            case 'bookingoption':
                // Check if the course exists.
                return $this->is_active_bookingoption();
            default:
                return false;
        }
    }

    /**
     * Factory for the organisational units.
     * Enrols the user to a course or booking option based on the target type.
     * @return bool
     */
    public function execute() {
        switch ($this->target->targettype) {
            case 'course':
            case 'moodlecourse':
                // Check if the course exists.
                return $this->enrol_to_course();
            case 'bookingoption':
                // Check if the course exists.
                return $this->enrol_to_bookingoption();
            default:
                return false;
        }
    }

    /**
     * Enrols the user to the course
     *
     * @return bool
     *
     */
    private function enrol_to_course() {
        $enrol = enrol_get_plugin('manual');
        if ($this->manualinstance !== null) {
            global $DB;
            // Get the student role ID.
            $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
            $enrol->enrol_user(
                $this->manualinstance,
                $this->userid,
                $studentrole->id,
                time(),
            );
        } else {
            throw new moodle_exception('No manual enrolment method found for course.');
        }
        return true;
    }

    /**
     * Enrols the user to the course
     *
     * @return bool
     *
     */
    private function enrol_to_bookingoption() {

        // We check if booking is installed and available at all.
        if (!class_exists('mod_booking\singleton_service')) {
            return false;
        }

        $optionid = $this->target->targetid;

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        // Check if the booking option exists.
        if (empty($settings) || empty($settings->id)) {
            return false;
        }

        $boinfo = new bo_info($settings);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $this->userid, true);

        if (
            !in_array(
                $id,
                [
                    MOD_BOOKING_BO_COND_BOOKITBUTTON,
                    MOD_BOOKING_BO_COND_CONFIRMBOOKIT,
                ]
            )
        ) {
            return false;
        }

        // We always run this twice, because we need to confirm that the user is actually enrolled.
        $result = booking_bookit::bookit('option', $settings->id, $this->userid);
        $result = booking_bookit::bookit('option', $settings->id, $this->userid);

        return true;
    }

    /**
     * Checks if the course is active and has a manual enrolment instance.
     *
     * @return bool
     *
     */
    private function is_active_course(): bool {
        global $DB;

        $courseid = $this->target->targetid;

        // Check if the course exists.
        if (!$DB->record_exists('course', ['id' => $this->target->targetid])) {
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
     * Checks if the booking option is active and available for enrolment.
     *
     * @return bool
     *
     */
    private function is_active_bookingoption(): bool {
        global $DB;

        // We check if booking is installed and available at all.
        if (!class_exists('mod_booking\singleton_service')) {
            return false;
        }

        $optionid = $this->target->targetid;

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        // Check if the booking option exists.
        if (empty($settings) || empty($settings->id)) {
            return false;
        }

        // First check if the user can actually book this option.
        $boinfo = new bo_info($settings);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $this->userid, true);

        if (
            !in_array(
                $id,
                [
                    MOD_BOOKING_BO_COND_BOOKITBUTTON,
                    MOD_BOOKING_BO_COND_CONFIRMBOOKIT,
                ]
            )
        ) {
            return false;
        }

        return true;
    }
}
