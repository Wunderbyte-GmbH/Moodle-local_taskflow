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

use local_taskflow\local\rules\unit_rules;
use local_taskflow\local\units\organisational_unit_factory;
use local_taskflow\local\units\unit_relations;


/**
 * Class user_updated event handler.
 *
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class unit_relation_updated {
    /**
     * @var string Event name for user updated.
     */
    public string $eventname = 'local_taskflow\event\unit_relation_updated';

    /**
     * React on the triggered event.
     * @param \core\event\base $event
     * @return void
     */
    public function handle(\core\event\base $event): void {
        $data = $event->get_data();
        $parentunit = json_decode($data['other']['parent']);
        $childunit = json_decode($data['other']['child']);
        $inheritanceunits = [$childunit, $parentunit];
        // Go down the path and apply rules.
        $inheritancesetting = get_config('local_taskflow', 'inheritance_option');
        if ($inheritancesetting !== 'noinheritance') {
            if ($inheritancesetting == 'allaboveinheritance') {
                $inheritanceunits = array_merge($inheritanceunits, self::get_inheritance_units($parentunit));
            }
            foreach ($inheritanceunits as $unitid) {
                $unitinstance = organisational_unit_factory::instance($unitid);
                $unitmembers = $unitinstance->get_members();
                // $unitrules = unit_rules::instance($unitid);
                // foreach ($unitrules as $rule) {
                //     $filter = 'doing some filtering' . $unitmembers;
                //     $actions = 'doing some filtering' . $unitmembers;
                //     $when = 'doing some filtering' . $unitmembers;
                //     $messages = 'doing some filtering' . $unitmembers;
                //     $assign = 'doing some filtering' . $unitmembers;
                // }
            }
        }
    }

    /**
     * React on the triggered event.
     * @param string $unitid
     * @return array
     */
    private function get_inheritance_units($unitid): array {
        $inheritanceunits = [];
        while ($unitid) {
            $unitrelationinstance = unit_relations::instance($unitid);
            $unitid = $unitrelationinstance->get_parentid();
            if ($unitid) {
                $inheritanceunits[] = $unitid;
            }
        }
        return $inheritanceunits;
    }
}
