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
 * Form to create rules.
 *
 * @package   local_taskflow
 * @copyright 2025
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\local\operators;

/**
 * Demo step 1 form.
 */
class string_compare_operators extends operators_base {
    /**
     * Definition.
     * @return array
     */
    public function get_operator_keys(): array {
        return ['equals', 'not_equals', 'contains', 'containsnot'];
    }

    /**
     * This class passes on the fields for the mform.
     * @return array
     */
    public function get_operator_keys_and_values(): array {
        return [
            'equals' => get_string('operator:equals', 'local_taskflow'),
            'not_equals' => get_string('operator:equalsnot', 'local_taskflow'),
            'contains' => get_string('operator:contains', 'local_taskflow'),
            'containsnot' => get_string('operator:containsnot', 'local_taskflow'),
            'since' => get_string('operator:since', 'local_taskflow'),
        ];
    }

    /**
     * This class passes on the fields for the mform.
     * @param string $profilevalue
     * @param string $rulevalue
     * @param string $operator
     * @return bool
     */
    public function validate($profilevalue, $rulevalue, $operator): bool {
        return match ($operator) {
            'equals' => $profilevalue === $rulevalue,
            'not_equals' => $profilevalue !== $rulevalue,
            'contains' => str_contains($profilevalue, $rulevalue),
            'containsnot' => !str_contains($profilevalue, $rulevalue),
            'since' => $rulevalue < $profilevalue,
            default => false
        };
    }
}
