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

namespace local_taskflow\local\filters\types;

use core_user;
use local_taskflow\local\filters\filter_interface;
use MoodleQuickForm;
use stdClass;

/**
 * Class unit
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class user_profile_field implements filter_interface {
    /** @var mixed $data */
    public mixed $data;

    /** @var array Form identifiers */
    public static array $formidentifiers = [
        'user_profile_field_userprofilefield',
        'user_profile_field_operator',
        'user_profile_field_value',
    ];


    /**
     * Factory for the organisational units
     * @param stdClass $data
     */
    public function __construct($data) {
        $this->data = $data;
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

        $repeatarray = [];

        // User profile field select.
        $options = self::get_userprofilefields(); // Replace this with your own method or static array.
        $repeatarray[] =
            $mform->createElement(
                'select',
                'user_profile_field_userprofilefield',
                get_string('userprofilefield', 'local_taskflow'),
                $options
            );

        // Operator select.
        $operators = self::get_operators(); // Replace with your actual method.
        $repeatarray[] =
            $mform->createElement(
                'select',
                'user_profile_field_operator',
                get_string('operator', 'local_taskflow'),
                $operators
            );

        // Value input.
        $repeatarray[] = $mform->createElement('text', 'user_profile_field_value', get_string('value', 'local_taskflow'));
        $mform->setType('value', PARAM_TEXT);

        // Number of initial filter sets.
        $repeatcount = 1;
        $repeateloptions = [
            'user_profile_field_userprofilefield' => ['type' => PARAM_TEXT],
            'user_profile_field_operator' => ['type' => PARAM_TEXT],
            'user_profile_field_value' => ['type' => PARAM_TEXT],
        ];

        $form->repeat_elements(
            $repeatarray,
            $repeatcount,
            $repeateloptions,
            'filter_repeats',
            'filter_add',
            1,
            get_string('addfilter', 'local_taskflow'),
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

        // We just need the filter data values.

        $filterdata = [];
        foreach (self::$formidentifiers as $key => $value) {
            if (isset($step[$value])) {
                $filterdata[$value] = $step[$value];
            }
        }

        return $filterdata;
    }

    /**
     * Factory for the organisational units
     * @param array $rule
     * @param int $userid
     * @return bool
     */
    public function is_valid($rule, $userid) {
        $fieldvalues = $this->get_user_profil_field_value($userid);
        if ($fieldvalues == '') {
            return false;
        }
        return $this->check_field_compatibility($fieldvalues);
    }

    /**
     * Factory for the organisational units
     * @param int $userid
     * @return string
     */
    protected function get_user_profil_field_value($userid) {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/profile/lib.php');
        $user = core_user::get_user($userid);
        $userprofile = profile_user_record($userid, false);
        $profilefield = $this->data->userprofilefield;
        return $userprofile->$profilefield ?? '';
    }

    /**
     * Factory for the organisational units
     * @param string $fieldvalues
     * @return bool
     */
    private function check_field_compatibility($fieldvalues) {
        $fieldvalues = json_decode($fieldvalues);
        foreach ($fieldvalues as $fieldvalue) {
            $key = $this->data->key ?? '';
            $profilevalue = $fieldvalue->$key ?? '';
            if ($this->is_timestamp()) {
                return $this->check_date_operation($profilevalue);
            } else if ($this->is_valid_comparions()) {
                return $this->check_string_operation($profilevalue);
            }
        }
        return false;
    }

    /**
     * Factory for the organisational units
     * @return bool
     */
    private function is_timestamp(): bool {
        if (!is_numeric($this->data->value)) {
            return false;
        }
        $timestamp = (int)$this->data->value;
        if ($timestamp < 946684800 || $timestamp > 32503680000) {
            return false;
        }
        return (bool)date('Y-m-d', $timestamp);
    }

    /**
     * Factory for the organisational units
     * @return bool
     */
    private function is_valid_comparions(): bool {
        $validcomparisons = ['equals', 'not_equals', 'contains' ];
        return in_array($this->data->operator, $validcomparisons);
    }

    /**
     * Factory for the organisational units
     * @param string $profilevalue
     * @return bool
     */
    private function check_date_operation($profilevalue): bool {
        $rulevalue = $this->data->value;
        $operator = $this->data->operator;
        return match ($operator) {
            'equals' => $profilevalue == $rulevalue,
            'bigger' => $profilevalue > $rulevalue,
            'smaller' => $profilevalue < $rulevalue,
            default => false
        };
    }

    /**
     * Factory for the organisational units
     * @param string $profilevalue
     * @return bool
     */
    private function check_string_operation($profilevalue): bool {
        $rulevalue = $this->data->value;
        $operator = $this->data->operator;
        return match ($operator) {
            'equals' => $profilevalue === $rulevalue,
            'not_equals' => $profilevalue !== $rulevalue,
            'contains' => str_contains($profilevalue, $rulevalue),
            default => false
        };
    }

    /**
     * Get the user profile files to use in mform select elements.
     *
     * @return array
     *
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
     *
     * @return array
     *
     */
    public static function get_operators() {
        $operators = [
            '=' => get_string('operator:equals', 'local_taskflow'),
            '!=' => get_string('operator:equalsnot', 'local_taskflow'),
            '<' => get_string('operator:lowerthan', 'local_taskflow'),
            '>' => get_string('operator:biggerthan', 'local_taskflow'),
            '~' => get_string('operator:contains', 'local_taskflow'),
            '!~' => get_string('operator:containsnot', 'local_taskflow'),
            '[]' => get_string('operator:inarray', 'local_taskflow'),
            '[!]' => get_string('operator:notinarray', 'local_taskflow'),
            '[~]' => get_string('operator:containsinarray', 'local_taskflow'),
            '[!~]' => get_string('operator:containsnotinarray', 'local_taskflow'),
            '()' => get_string('operator:isempty', 'local_taskflow'),
            '(!)' => get_string('operator:isnotempty', 'local_taskflow'),
        ];
        return $operators;
    }
}
