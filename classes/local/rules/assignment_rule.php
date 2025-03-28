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
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\local\rules;

/**
 * Class unit
 *
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignment_rule {
    /**
     * Get the instance of the class for a specific ID.
     * @param \stdClass $rule
     * @param \stdClass $user
     * @return bool
     */
    public static function is_rule_active_for_user($rule, $user) {
        if (empty($rule->isactive) || $rule->isactive !== '1') {
            return false;
        }
        $rulepath = '\\local_taskflow\\local\\taskflow_rules\\rules\\' . $rule->rulename;
        if (class_exists($rulepath)) {
            $ruleinstance = new $rulepath();
            $ruleinstance->set_ruledata($rule);
            return $ruleinstance->check_if_rule_still_applies(
                0,
                $user->id,
                0
            );
        }
        return false;
    }
}
