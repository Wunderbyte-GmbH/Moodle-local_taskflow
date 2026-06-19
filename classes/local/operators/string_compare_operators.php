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

use local_taskflow\taskflow_stringmanager;

/**
 * Demo step 1 form.
 */
class string_compare_operators extends operators_base {
    /**
     * Definition.
     * @return array
     */
    public function get_operator_keys(): array {
        return ['equals', 'not_equals', 'contains', 'containsnot', 'isin', 'isnotin', 'before', 'nowminusdays', 'nowplusdays'];
    }

    /**
     * This class passes on the fields for the mform.
     * @return array
     */
    public function get_operator_keys_and_values(): array {
        return [
            'equals' => taskflow_stringmanager::get_string('operator:equals'),
            'not_equals' => taskflow_stringmanager::get_string('operator:equalsnot'),
            'contains' => taskflow_stringmanager::get_string('operator:contains'),
            'containsnot' => taskflow_stringmanager::get_string('operator:containsnot'),
            'since' => taskflow_stringmanager::get_string('operator:since'),
            'before' => taskflow_stringmanager::get_string('operator:before'),
            'isin' => taskflow_stringmanager::get_string('operator:containsinarray'),
            'isnotin' => taskflow_stringmanager::get_string('operator:containsnotinarray'),
            "nowminusdays" => taskflow_stringmanager::get_string('operator:nowminusdays'),
            "nowplusdays" => taskflow_stringmanager::get_string('operator:nowplusdays'),
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
            'isin' => in_array($profilevalue, explode(';', $rulevalue)),
            'isnotin' => !in_array($profilevalue, explode(';', $rulevalue)),
            'since' => $rulevalue <= $profilevalue,
            'before' => $rulevalue >= $profilevalue,
            'nowminusdays' => time() - ((int) $rulevalue * 86400) >= $profilevalue,
            'nowplusdays' => time() + ((int) $rulevalue * 86400) >= $profilevalue,
            default => false
        };
    }
}
