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

namespace local_taskflow\local\assignment_operators;

use local_taskflow\local\filters\filter_factory;

/**
 * Class unit
 *
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class filter_operator {
    /** @var string Event name for user updated. */
    public int $userid;
    /**
     * Get the instance of the class for a specific ID.
     * @param int $userid
     */
    public function __construct($userid) {
        $this->userid = $userid;
    }
    /**
     * Get the instance of the class for a specific ID.
     * @param unit_rules $rule
     * @return bool
     */
    public function is_rule_active_for_user($rule) {
        $active = $rule->get_isactive();
        if (
            $active != '1'
        ) {
            return false;
        }
        $rulejson = json_decode($rule->get_rulesjson());
        $rulejson = $rulejson->rulejson ?? null;
        if ($rulejson == null) {
            return false;
        }

        if (
            !isset($rulejson->rule->filter)
            || !is_array($rulejson->rule->filter)
            || empty($rulejson->rule->filter)
        ) {
            return true;
        }

        foreach ($rulejson->rule->filter as $filter) {
            $filterbaseinstance = new filter_factory();
            $filterinstance = $filterbaseinstance->instance($filter);
            if ($filterinstance) {
                if (!$filterinstance->is_valid($rule, $this->userid)) {
                    return false;
                }
            }
        }
        return true;
    }
}
