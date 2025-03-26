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
use local_taskflow\local\units\unit;
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
        $inheritageunits = [$childunit, $parentunit];
        // Go down the path and apply rules.
        $inheritagesetting = get_config('local_taskflow', 'inheritage_option');
        if ($inheritagesetting !== 'noinheritage') {
            if ($inheritagesetting == 'allaboveinheritage') {
                $inheritageunits = array_merge($inheritageunits, self::get_inheritage_units($parentunit));
            }
            foreach ($inheritageunits as $unitid) {
                $unitinstance = unit::instance($unitid);
                $unitmembers = $unitinstance->get_members();
                $unitrules = unit_rules::instance($unitid);
                foreach ($unitrules as $rule) {
                    $filter = 'doing some filtering' . $unitmembers;
                    $actions = 'doing some filtering' . $unitmembers;
                    $when = 'doing some filtering' . $unitmembers;
                    $messages = 'doing some filtering' . $unitmembers;
                    $assign = 'doing some filtering' . $unitmembers;
                }
            }
        }
    }

    /**
     * React on the triggered event.
     * @param string $unitid
     * @return array
     */
    private function get_inheritage_units($unitid): array {
        $inheritageunits = [];
        while ($unitid) {
            $unitrelationinstance = unit_relations::instance($unitid);
            $unitid = $unitrelationinstance->get_parentid();
            if ($unitid) {
                $inheritageunits[] = $unitid;
            }
        }
        return $inheritageunits;
    }
}
