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
 * Service to persist a rule.
 *
 * @package local_taskflow
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\local\rules;

use cache_helper;
use local_taskflow\event\rule_created_updated;
use local_taskflow\local\changemanager\changemanager;

/**
 * Writes a rule row, fires the rule_created_updated event and invalidates the caches.
 *
 * Extracted from local_taskflow\multistepform\editrulesmanager::persist(), which now
 * delegates to this service, so that agent skills persist rules exactly like the form.
 *
 * @package local_taskflow
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rule_persistence_service {
    /**
     * Inserts or updates a rule row.
     *
     * $ruledata is the array produced by local_taskflow\form\rules\types\unit_rule::get_data()
     * (id, unitid, userid, rulename, isactive, rulejson). An existing id triggers an update.
     *
     * @param array $ruledata
     * @param int $actorid
     * @return int The id of the rule.
     */
    public function persist(array $ruledata, int $actorid): int {
        global $DB;

        $ruleid = $ruledata['id'] ?? null;
        $changemanager = new changemanager($ruleid, $ruledata);
        $ruledata['changemanagement'] = $changemanager->get_change_management_data();

        if (!empty($ruleid)) {
            $DB->update_record('local_taskflow_rules', $ruledata);
        } else {
            $ruledata['id'] = $DB->insert_record('local_taskflow_rules', $ruledata);
        }

        $event = rule_created_updated::create([
            'objectid' => $ruledata['id'],
            'context'  => \context_system::instance(),
            'userid'   => $actorid,
            'other'    => [
                'ruledata' => $ruledata,
            ],
        ]);
        $event->trigger();

        rules::reset_instances();
        unit_rules::reset_instances();
        cache_helper::purge_by_event('changesinruleslist');
        cache_helper::purge_by_event('changesinassignmentslist');

        return (int)$ruledata['id'];
    }

    /**
     * Builds the rule data out of the steps of the multistep form.
     *
     * The first step knows how to transform all steps into the ruledata / rulejson
     * structure (local_taskflow\form\rules\rule::get_data_to_persist(), which calls
     * local_taskflow\form\rules\types\unit_rule::get_data()).
     *
     * @param array $steps
     * @return array
     */
    public static function build_from_steps(array $steps): array {
        $classname = str_replace('\\\\', '\\', $steps[1]['formclass']);
        $class = new $classname();
        return $class->get_data_to_persist($steps);
    }
}
