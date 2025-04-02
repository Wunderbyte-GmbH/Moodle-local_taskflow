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
use stdClass;

/**
 * Class unit
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class user_profile_field implements filter_interface {
    /** @var mixed Event name for user updated. */
    public mixed $data;

    /**
     * Factory for the organisational units
     * @param stdClass $data
     */
    public function __construct($data) {
        $this->data = $data;
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
        $profilefield = $this->data->userprofilefiled;
        return $userprofile->$profilefield ?? '';
    }

    /**
     * Factory for the organisational units
     * @param string $fieldvalue
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
}
