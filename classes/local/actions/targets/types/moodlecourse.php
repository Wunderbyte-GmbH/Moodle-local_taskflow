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
use stdClass;

/**
 * Class unit
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodlecourse extends targets_base implements targets_interface {
    /** @var array The instances of the class. */
    private static $instances = [];

    /** @var string Event name for user updated. */
    private const TABLE = 'course';

    /** @var array Form identifiers */
    public static array $formidentifiers = [
        'targettype',
        'bookingoptions',
        'moodlecourses',
        'completebeforenext',
        'duedatetype',
        'duration',
        'fixeddate',
        'target_repeats',
    ];

    /**
     * Private constructor to prevent direct instantiation.
     * @param stdClass $data The record from the database.
     */
    private function __construct(stdClass $data) {
        global $DB;
        $this->id = $data->id;

        $this->name = $DB->get_field('course', 'fullname', ['id' => $this->id]);
    }

    /**
     * Implement get data function to return data from the form.
     *
     * @param array $step
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
        if (!isset(self::$instances[$targetid])) {
            $data = $DB->get_record(
                self::TABLE,
                [ 'id' => $targetid],
                'id, fullname'
            );
            self::$instances[$targetid] = new self($data);
        }
        return self::$instances[$targetid];
    }
}
