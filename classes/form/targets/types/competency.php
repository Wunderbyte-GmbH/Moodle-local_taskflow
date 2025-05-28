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
class competency {
    /** @var array Form identifiers */
    public static array $formidentifiers = [
        'targetduedatetype',
        'fixeddate',
        'duration',
    ];
    /**
     * This class passes on the fields for the mform.
     * @param array $repeatarray
     * @param MoodleQuickForm $mform
     */
    public static function definition(&$repeatarray, $mform) {
        global $DB;
        $competencies = $DB->get_records('competency');
        $competencyoptions = [];
        foreach ($competencies as $c) {
            $competencyoptions[$c->id] = $c->shortname . " ($c->id)";
        }

        $repeatarray[] = $mform->createElement(
            'autocomplete',
            'competency_targetid',
            get_string('targettype:competency', 'local_taskflow'),
            $competencyoptions,
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
            "competency_targetid",
        ];
        foreach ($elements as $element) {
            $mform->hideIf(
                $element . "[$elementcounter]",
                "targettype[$elementcounter]",
                'neq',
                'competency'
            );
            $mform->disabledIf(
                $element . "[$elementcounter]",
                "targettype[$elementcounter]",
                'neq',
                'competency'
            );
        }
    }
}
