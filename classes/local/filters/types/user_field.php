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

namespace local_taskflow\local\filters\types;

use core_user;
use local_taskflow\local\filters\filter_interface;
use local_taskflow\local\operators\string_compare_operators;

/**
 * Runtime filter on a Moodle core user field (firstaccess | lastaccess).
 *
 * Counterpart of form\filters\types\user_field: the form persists
 * {filtertype: user_field, userfield, operator, value, date?}; this class evaluates it with
 * the same operator semantics as user_profile_field (string_compare_operators::validate(),
 * 'since'/'before' take their right-hand side from 'date' when present).
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class user_field implements filter_interface {
    /** @var string[] Core user fields this filter may read. */
    public const ALLOWED_FIELDS = ['firstaccess', 'lastaccess'];

    /** @var mixed Filter definition from the rule JSON. */
    public mixed $data;

    /**
     * Constructor.
     *
     * @param mixed $data Filter definition (object with userfield, operator, value, date).
     */
    public function __construct($data) {
        $this->data = $data;
    }

    /**
     * Whether the user matches the filter condition.
     *
     * Unknown fields or a missing user never match.
     *
     * @param mixed $rule
     * @param int $userid
     * @return bool
     */
    public function is_valid($rule, $userid) {
        $field = (string)($this->data->userfield ?? '');
        if (!in_array($field, self::ALLOWED_FIELDS, true)) {
            return false;
        }
        $user = core_user::get_user($userid, 'id, ' . $field, IGNORE_MISSING);
        if (!$user) {
            return false;
        }
        return $this->check_operation((string)($user->$field ?? ''));
    }

    /**
     * Compare the user field value with the rule value using the shared operator semantics.
     *
     * @param string $fieldvalue
     * @return bool
     */
    private function check_operation(string $fieldvalue): bool {
        $operator = (string)($this->data->operator ?? '');
        $operators = new string_compare_operators();
        if (!in_array($operator, $operators->get_operator_keys(), true)) {
            return false;
        }
        if ($operator === 'since' || $operator === 'before') {
            $rulevalue = $this->data->date ?? ($this->data->value ?? 0);
        } else {
            $rulevalue = $this->data->value ?? '';
        }
        return $operators->validate($fieldvalue, (string)$rulevalue, $operator);
    }
}
