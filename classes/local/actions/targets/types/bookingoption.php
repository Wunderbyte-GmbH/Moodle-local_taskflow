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

namespace local_taskflow\local\actions\targets\types;

use local_taskflow\local\actions\targets\targets_base;
use local_taskflow\local\actions\targets\targets_interface;
use MoodleQuickForm;
use stdClass;

/**
 * Class unit
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bookingoption extends targets_base implements targets_interface {
    /** @var array The instances of the class. */
    private static $instances = [];

    /** @var string Event name for user updated. */
    private const TABLE = 'booking_options';

    /** @var array Form identifiers */
    public static array $formidentifiers = [
        'targettype',
        'bookingoptions',
        'moodlecourses',
        'completebeforenext',
        'targetduedatetype',
        'targetduration',
        'targetfixeddate',
        'target_repeats',
    ];

    /**
     * Private constructor to prevent direct instantiation.
     * @param stdClass $data The record from the database.
     */
    private function __construct(stdClass $data) {
        $this->id = $data->id;
        $this->name = $data->text;
    }

    /**
     * This class passes on the fields for the mform.
     * @param mixed $form
     * @param MoodleQuickForm $mform
     * @param array $data
     *
     * @return [type]
     *
     */
    public static function definition($form, MoodleQuickForm &$mform, array &$data) {

        global $DB;
        $typeoptions = [
            'bookingoption' => get_string('targettype:bookingoption', 'local_taskflow'),
            'moodlecourse' => get_string('targettype:moodlecourse', 'local_taskflow'),
        ];

        $dateoptions = [
            'duration' => get_string('duration', 'local_taskflow'),
            'fixeddate' => get_string('fixeddate', 'local_taskflow'),
        ];

        $sql = "SELECT id, text FROM {booking_options}";
        $bookingoptions = $DB->get_records_sql($sql);
        $bookingoptionsarray = [];
        foreach ($bookingoptions as $bo) {
            $bookingoptionsarray[$bo->id] = $bo->text . " ($bo->id)";
        }

        $sql = "SELECT id, fullname FROM {course}";
        $courses = $DB->get_records_sql($sql);
        $coursesarray = [];
        foreach ($courses as $c) {
            $coursesarray[$c->id] = $c->fullname . " ($c->id)";
        }

        $repeatarray = [];

        $repeatarray[] = $mform->createElement(
            'select',
            'targettype',
            get_string('targettype', 'local_taskflow'),
            $typeoptions
        );

        $repeatarray[] = $mform->createElement(
            'select',
            'bookingoptions',
            get_string('targettype:bookingoption', 'local_taskflow'),
            $bookingoptionsarray,
            []
        );

        $repeatarray[] = $mform->createElement(
            'select',
            'moodlecourses',
            get_string('targettype:moodlecourse', 'local_taskflow'),
            $coursesarray,
            []
        );

        $repeatarray[] = $mform->createElement(
            'advcheckbox',
            'completebeforenext',
            get_string('target:completebeforenext', 'local_taskflow'),
            0
        );

        $repeatarray[] = $mform->createElement(
            'select',
            'targetduedatetype',
            get_string('duedatetype', 'local_taskflow'),
            $dateoptions
        );

        // Due Date - Fixed Date.
        $repeatarray[] = $mform->createElement('date_time_selector', 'targetfixeddate', get_string('fixeddate', 'local_taskflow'));
        $repeatarray[] = $mform->createElement('duration', 'targetdateduration', get_string('duration', 'local_taskflow'), ['optional' => true]);

        // Number of initial target sets.
        $repeatcount = 1;
        $repeateloptions = [
            'bookingoptions' => [
                'type' => PARAM_TEXT,
                'hideif' => [
                    'targettype',
                    'neq',
                    'bookingoption',
                ],
            ],
            'moodlecourses' => [
                'type' => PARAM_TEXT,
                'hideif' => [
                    'targettype',
                    'neq',
                    'moodlecourse',
                ],
            ],
            'targetduedatetype' => [
                'default' => 'duration',
            ],
            'targetfixeddate' => [
                'default' => 0,
                'hideif' => [
                    'targetduedatetype',
                    'neq',
                    'fixeddate',
                ],
            ],
            'targetdateduration' => [
                'default' => 0,
                'hideif' => [
                    'targetduedatetype',
                    'neq',
                    'duration',
                ],
            ],
        ];

        $form->repeat_elements(
            $repeatarray,
            $repeatcount,
            $repeateloptions,
            'target_repeats',
            'target_add',
            1,
            get_string('target:addtarget', 'local_taskflow'),
            true
        );
    }

    /**
     * Implement get data function to return data from the form.
     *
     * @param array $step
     *
     * @return array
     *
     */
    public static function get_data(array $step): array {

        // We just need the target data values.
        $targetdata = [];
        foreach (self::$formidentifiers as $key => $value) {
            if (isset($step[$value])) {
                $targetdata[$value] = $step[$value];
            }
        }

        return $targetdata;
    }

    /**
     * Factory for the organisational units
     * @param int $targetid
     * @return mixed
     */
    public static function instance($targetid) {
        global $DB;
        if (
            !isset(self::$instances[$targetid]) &&
            $DB->get_manager()->table_exists('booking_options')
        ) {
            $data = $DB->get_record(
                self::TABLE,
                [ 'id' => $targetid],
                'id, text'
            );
            self::$instances[$targetid] = new self($data);
        }
        return self::$instances[$targetid];
    }
}
