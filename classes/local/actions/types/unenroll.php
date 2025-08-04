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

use core_competency\user_competency;
use mod_booking\bo_availability\bo_info;
use mod_booking\singleton_service;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/enrol/externallib.php');
require_once($CFG->libdir . '/enrollib.php');

use context_course;
use moodle_exception;
use stdClass;

/**
 * Class unit
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class unenroll {
    /** @var array Event name for user updated. */
    public array $targets;

    /** @var string Event name for user updated. */
    public string $userid;

    /**
     * Factory for the organisational units.
     * @param stdClass $assignment
     */
    public function __construct($assignment) {
        $this->targets = json_decode($assignment->targets);
        $this->userid = $assignment->userid;
    }

    /**
     * Factory for the organisational units.
     * @return void
     */
    public function execute() {
        global $DB;
        foreach ($this->targets as $target) {
            $methodname = 'unenrol_from_' . $target->targettype;
            if (method_exists($this, $methodname)) {
                $this->$methodname($target);
            }
        }
    }

    /**
     * Enrols the user to the course
     * @param stdClass $target
     * @return bool
     */
    private function unenrol_from_moodlecourse($target) {
        global $DB;
        $context = context_course::instance($target->targetid);
        if (!is_enrolled($context, $this->userid)) {
            return false;
        }
        $enrol = enrol_get_plugin('manual');
        if (!$enrol) {
            throw new moodle_exception('No manual enrolment method found for course.');
        }
        $instances = enrol_get_instances($target->targetid, true);
        $unenrolled = false;
        foreach ($instances as $instance) {
            if ($instance->enrol === 'manual') {
                $enrol->unenrol_user($instance, $this->userid);
                $unenrolled = true;
                break;
            }
        }
        if (!$unenrolled) {
            throw new moodle_exception('No manual enrolment instance found for course.');
        }
        $DB->delete_records('course_completions', [
            'course' => $target->targetid,
            'userid' => $this->userid,
        ]);
        $DB->delete_records_select(
            'course_modules_completion',
            'coursemoduleid IN (
                SELECT id FROM {course_modules} WHERE course = ?
            ) AND userid = ?',
            [$target->targetid, $this->userid]
        );
        return true;
    }

    /**
     * Enrols the user to the course
     * @param stdClass $target
     * @return bool
     */
    private function unenrol_from_bookingoption($target) {
        if (
            !class_exists('mod_booking\\singleton_service') ||
            empty($target->targetid)
        ) {
            return false;
        }

        $optionid = $target->targetid;
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        if (empty($settings->cmid)) {
            return false;
        }
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $optionid);
        $option->user_delete_response($this->userid);
        return true;
    }

    /**
     * Enrols the user to the course
     * @param stdClass $target
     * @return bool
     */
    private function unenrol_from_competency($target) {
        global $DB;
        $userid = $this->userid;
        $competencyid = $target->targetid;

        $record = $DB->get_record(
            'competency_usercomp',
            [
                'userid' => $userid,
                'competencyid' => $competencyid,
            ],
            'id'
        );

        if (!$record) {
            return false;
        }

        $usercompetency = new user_competency($record->id);
        $usercompetency->delete();
        $DB->delete_records_select(
            'competency_userevidencecomp',
            'competencyid = ? AND userevidenceid IN (
                SELECT id FROM {competency_userevidence} WHERE userid = ?
            )',
            [$competencyid, $userid]
        );

        return true;
    }
}
