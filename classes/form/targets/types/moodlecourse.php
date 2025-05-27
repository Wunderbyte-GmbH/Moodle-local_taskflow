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

namespace local_taskflow\form\targets\types;

use MoodleQuickForm;

/**
 * Class unit
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodlecourse {
    /** @var array Form identifiers */
    public static array $formidentifiers = [
        'moodlecourse_targetid',
        'targetduedatetype',
        'fixeddate',
        'duration',
    ];

    /**
     * This class passes on the fields for the mform.
     * @param mixed $form
     * @param MoodleQuickForm $mform
     * @param array $data
     */
    public static function definition(&$repeatarray, $mform) {
        global $DB;

        $sql = "SELECT id, fullname FROM {course}";
        $courses = $DB->get_records_sql($sql);
        $coursesarray = [];
        foreach ($courses as $c) {
            $coursesarray[$c->id] = $c->fullname . " ($c->id)";
        }

        $repeatarray[] = $mform->createElement(
            'autocomplete',
            'moodlecourse_targetid',
            get_string('targettype:moodlecourse', 'local_taskflow'),
            $coursesarray,
            []
        );

        return;
    }

    /**
     * This class passes on the fields for the mform.
     * @param MoodleQuickForm $mform
     * @param int $elementcounter
     */
    public static function hide_and_disable(&$mform, $elementcounter) {
        $elements = [
            "moodlecourse_targetid",
        ];
        foreach ($elements as $element) {
            $mform->hideIf(
                $element . "[$elementcounter]",
                "targettype[$elementcounter]",
                'neq',
                'moodlecourse'
            );
            $mform->disabledIf(
                $element . "[$elementcounter]",
                "targettype[$elementcounter]",
                'neq',
                'moodlecourse'
            );
        }
    }

    /**
     * Implement get data function to return data from the form.
     * @param array $step
     * @return array
     */
    public static function get_data(array $step): array {
        $targetdata = [
            'targettype' => array_shift($step['targettype']),
            'targetid' => array_shift($step['moodlecourse_targetid']),
        ];
        foreach (self::$formidentifiers as $key => $value) {
            if (isset($step[$value])) {
                $targetdata[$value] = $step[$value];
            }
        }
        foreach ($step as $key => &$value) {
            if (in_array($key, self::$formidentifiers)) {
                $targetdata[$key] = array_shift($value);
            }
        }
        return $targetdata;
    }
}
