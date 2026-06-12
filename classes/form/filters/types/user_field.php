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

namespace local_taskflow\form\filters\types;

use local_taskflow\form\filters\filter_types_interface;
use local_taskflow\local\operators\string_compare_operators;
use MoodleQuickForm;
use local_taskflow\taskflow_stringmanager;

/**
 * Class unit
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class user_field implements filter_types_interface {
    /**
     * This class passes on the fields for the mform.
     * @param array $repeatarray
     * @param MoodleQuickForm $mform
     */
    public static function definition(&$repeatarray, $mform) {
        // User profile field select.
        $options = self::get_userfields();
        $repeatarray[] =
            $mform->createElement(
                'select',
                'user_field_userfield',
                taskflow_stringmanager::get_string('userfield'),
                $options
            );
        // Operator select.
        $operators = self::get_operators();
        $repeatarray[] =
            $mform->createElement(
                'select',
                'user_field_operator',
                taskflow_stringmanager::get_string('operator'),
                $operators
            );

        // Value input.
        $repeatarray[] = $mform->createElement(
            'text',
            'user_field_value',
            taskflow_stringmanager::get_string('value'),
            ['size' => 100, 'maxlength' => 500]
        );
        $mform->setType('value', PARAM_TEXT);
    }

    /**
     * This class passes on the fields for the mform.
     * @param MoodleQuickForm $mform
     * @param string $elementcounter
     */
    public function hide_and_disable(&$mform, $elementcounter) {
        $elements = [
            "user_field_userfield",
            "user_field_operator",
            "user_field_value",
        ];
        foreach ($elements as $element) {
            $mform->hideIf(
                $element . "[$elementcounter]",
                "filtertype[$elementcounter]",
                'neq',
                'user_field'
            );
            $mform->disabledIf(
                $element . "[$elementcounter]",
                "filtertype[$elementcounter]",
                'neq',
                'user_field'
            );
        }
    }

    /**
     * Implement get data function to return data from the form.
     * @param array $step
     * @return array
     */
    public static function get_data(array &$step): array {
        // We just need the filter data values.
        $filterdata = [
            'filtertype' => array_shift($step['filtertype']),
        ];
        $prefix = 'user_field_';
        foreach ($step as $key => &$value) {
            if (str_contains($key, $prefix)) {
                $filterkey = str_replace($prefix, '', $key);
                if (is_array($value)) {
                    $filterdata[$filterkey] = array_shift($value);
                } else {
                    $filterdata[$filterkey] = $value;
                }
            }
        }
        return $filterdata;
    }

    /**
     * Get the operators to use in mform select elements.
     * @return array
     */
    public static function get_operators() {
        $operatorsinstance = new string_compare_operators();
        return $operatorsinstance->get_operator_keys_and_values();
    }

    /**
     * Get the user profile files to use in mform select elements.
     * @return array
     */
    public static function get_userfields() {
        global $DB;
        $fields = [
            'firstaccess' => taskflow_stringmanager::get_string('filteruserfieldfirstaccess'),
            'lastaccess' => taskflow_stringmanager::get_string('filteruserfieldlastaccess'),
        ];
        return $fields;
    }

    /**
     * Get the operators to use in mform select elements.
     * @return array
     */
    public static function get_options() {
        return [
            'user_field_userfield' => ['type' => PARAM_TEXT],
            'user_field_operator' => ['type' => PARAM_TEXT],
            'user_field_value' => ['type' => PARAM_TEXT],
        ];
    }
}
