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

namespace local_taskflow\local\eventhandlers;

use local_taskflow\local\actions\actions_factory;
use local_taskflow\local\rules\assignment_action;
use local_taskflow\local\rules\assignment_filter;
use local_taskflow\local\rules\unit_rules;
use stdClass;

/**
 * Class user_updated event handler.
 *
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class unit_updated {
    /**
     * @var string Event name for user updated.
     */
    public string $eventname = 'local_taskflow\event\unit_updated';

    /**
     * React on the triggered event.
     * @param \core\event\base $event
     * @return void
     */
    public function handle(\core\event\base $event): void {
        $data = $event->get_data();
        $unitids = [$data['other']['unitid']];
        $allaffectedusers = self::get_all_affected_users($unitids);
        $allaffectedrules = self::get_all_affected_rules($unitids);
        foreach ($allaffectedusers as $userid) {
            foreach ($allaffectedrules as $unitid => $unitrule) {
                $assignmentfilterinstance = new assignment_filter($userid);
                $assignmentactioninstance = new assignment_action($userid);
                foreach ($unitrule as $rule) {
                    if ($assignmentfilterinstance->is_rule_active_for_user($rule)) {
                        $assignmentactioninstance->check_and_trigger_actions($rule);
                    }
                }
            }
        }
    }

    /**
     * React on the triggered event.
     * @param array $unitids
     * @return array
     */
    private function get_all_affected_users($unitids): array {
        global $DB;
        [$insql, $inparams] = $DB->get_in_or_equal($unitids, SQL_PARAMS_NAMED);

        $sql = "SELECT DISTINCT userid
                  FROM {local_taskflow_unit_members}
                 WHERE unitid $insql";

        $userrecords = $DB->get_records_sql($sql, $inparams);
        return array_keys($userrecords);
    }

    /**
     * React on the triggered event.
     * @param array $unitids
     * @return array
     */
    private function get_all_affected_rules($unitids): array {
        global $DB;
        $rules = [];
        foreach ($unitids as $unit) {
            $rules[] = unit_rules::instance($unit);
        }
        return $rules;
    }
}
