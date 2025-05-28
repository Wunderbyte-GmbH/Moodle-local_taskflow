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

use local_taskflow\form\targets\targets_base;
use MoodleQuickForm;

/**
 * Class unit
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodlecourse extends targets_base {
    /**
     * This class passes on the fields for the mform.
     * @param array $repeatarray
     * @param MoodleQuickForm $mform
     */
    public function definition(&$repeatarray, $mform) {
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
    public function hide_and_disable(&$mform, $elementcounter) {
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
     * Get the operators to use in mform select elements.
     * @return array
     */
    public function get_options() {
        return [
            'moodlecourse_targetid' => ['type' => PARAM_INT],
        ];
    }
}
