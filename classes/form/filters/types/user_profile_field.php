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
class user_profile_field implements filter_types_interface {
    /**
     * This class passes on the fields for the mform.
     * @param array $repeatarray
     * @param MoodleQuickForm $mform
     */
    public static function definition(&$repeatarray, $mform) {
        // User profile field select.
        $options = self::get_userprofilefields();
        $repeatarray[] =
            $mform->createElement(
                'select',
                'user_profile_field_userprofilefield',
                taskflow_stringmanager::get_string('userprofilefield'),
                $options
            );
        $operators = self::get_operators();
        $repeatarray[] =
            $mform->createElement(
                'select',
                'user_profile_field_operator',
                taskflow_stringmanager::get_string('operator'),
                $operators
            );
        $repeatarray[] = $mform->createElement(
            'text',
            'user_profile_field_value',
            taskflow_stringmanager::get_string('value'),
            ['size' => 100, 'maxlength' => 500]
        );

        $repeatarray[] = $mform->createElement(
            'date_selector',
            'user_profile_field_date',
            taskflow_stringmanager::get_string('value'),
        );

        $mform->setType('value', PARAM_TEXT);
        return;
    }

    /**
     * This class passes on the fields for the mform.
     * @param MoodleQuickForm $mform
     * @param string $elementcounter
     */
    public function hide_and_disable(&$mform, $elementcounter) {
        $elements = [
            "user_profile_field_userprofilefield",
            "user_profile_field_operator",
            "user_profile_field_value",
        ];
        foreach ($elements as $element) {
            $mform->hideIf(
                $element . "[$elementcounter]",
                "filtertype[$elementcounter]",
                'neq',
                'user_profile_field'
            );
            $mform->disabledIf(
                $element . "[$elementcounter]",
                "filtertype[$elementcounter]",
                'neq',
                'user_profile_field'
            );
        }
        $mform->hideIf(
            "user_profile_field_date[$elementcounter]",
            "user_profile_field_operator[$elementcounter]",
            'neq',
            'since'
        );
        $mform->disabledIf(
            "user_profile_field_date[$elementcounter]",
            "user_profile_field_operator[$elementcounter]",
            'neq',
            'since'
        );
        $mform->hideIf(
            "user_profile_field_value[$elementcounter]",
            "user_profile_field_operator[$elementcounter]",
            'eq',
            'since'
        );
        $mform->disabledIf(
            "user_profile_field_value[$elementcounter]",
            "user_profile_field_operator[$elementcounter]",
            'eq',
            'since'
        );
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

        $testedata = [];
        $prefix = 'user_profile_field_';
        $firstkey = null;
        foreach ($step as $key => &$value) {
            // For a correct matching, we need the key of the first array element.
            if (str_contains($key, $prefix)) {
                if (empty($firstkey)) {
                    $firstkey = array_key_first($value);
                }
                $filterkey = str_replace($prefix, '', $key);
                $filterdata[$filterkey] = $value[$firstkey];
                unset($value[$firstkey]);
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
    public static function get_userprofilefields() {
        global $DB;
        $fields = [];
        $sql = "SELECT * FROM {user_info_field} WHERE shortname != 'idnumber'";
        $profilefields = $DB->get_records_sql($sql);
        foreach ($profilefields as $field) {
            $fields[$field->shortname] = $field->name;
        }
        return $fields;
    }

    /**
     * Get the operators to use in mform select elements.
     * @return array
     */
    public static function get_options() {
        return [
            'user_profile_field_userprofilefield' => ['type' => PARAM_TEXT],
            'user_profile_field_operator' => ['type' => PARAM_TEXT],
            'user_profile_field_value' => ['type' => PARAM_TEXT],
        ];
    }
}
