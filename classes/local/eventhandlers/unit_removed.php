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

namespace local_taskflow\local\eventhandlers;

use local_taskflow\event\unit_member_removed;
use local_taskflow\local\rules\unit_rules;
use local_taskflow\local\units\organisational_unit_factory;
use local_taskflow\local\units\unit_relations;

/**
 * Class user_updated event handler.
 *
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class unit_removed extends base_event_handler {
    /**
     * @var string Event name for user updated.
     */
    public string $eventname = 'local_taskflow\event\unit_removed';
    /**
     * React on the triggered event.
     *
     * @param \core\event\base $event
     *
     * @return void
     *
     */
    public function handle(\core\event\base $event): void {
        $data = $event->get_data();
        $unitinstance = organisational_unit_factory::instance($data['other']['unitid']);
        $unitrelationsinstance = unit_relations::instance($data['other']['unitid']);
        $unitrulesinstances = unit_rules::instance($data['other']['unitid']);
        if ($unitinstance) {
            $unitusers = $unitinstance->get_members();
            $unitmemberevent = unit_member_removed::create([
                'objectid' => $data['objectid'],
                'context'  => \context_system::instance(),
                'userid'   => $data['objectid'],
                'other'    => [
                    'unitid' => $data['objectid'],
                    'unitmemberid' => $unitusers,
                ],
            ]);
            $unitmemberevent->trigger();
            foreach ($unitrulesinstances as $unitrulesinstance) {
                $unitrulesinstance->delete_rule();
            }
            $unitrelationsinstance->delete_all_relations();
            $unitinstance->delete();
        }
    }
}
