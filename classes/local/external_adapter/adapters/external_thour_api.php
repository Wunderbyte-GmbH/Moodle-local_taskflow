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

namespace local_taskflow\local\external_adapter\adapters;

use local_taskflow\event\unit_member_updated;
use local_taskflow\event\unit_relation_updated;
use local_taskflow\local\external_adapter\external_api_base;
use local_taskflow\local\external_adapter\external_api_interface;
use local_taskflow\local\personas\unit_member;
use local_taskflow\local\units\organisational_unit_factory;
use local_taskflow\local\units\unit_relations;
use local_taskflow\local\personas\moodle_user;
use stdClass;
/**
 * Class unit
 *
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class external_thour_api extends external_api_base implements external_api_interface {
    /** @var string|null Stores the external user data. */
    private stdClass $externaldata;

    /**
     * Private constructor to prevent direct instantiation.
     * @param string $data
     */
    public function __construct($data) {
        $this->externaldata = (object) json_decode($data);
    }

    /**
     * Private constructor to prevent direct instantiation.
     */
    public function process_incoming_data() {
        $translateduserdata = [];
        foreach ($this->externaldata as $user) {
            $translateduserdata[] = $this->translate_incoming_data($user);
            $translateduserdata['units'] = $this->generate_units_data($user);
        }
        $updatedentities = [
            'relationupdate' => [],
            'unitmember' => [],
        ];

        foreach ($translateduserdata as $persondata) {
            $moodleuser = new moodle_user($persondata);
            $user = $moodleuser->update_or_create();

            foreach ($persondata['units'] as $unit) {
                $unitinstance = organisational_unit_factory::create_unit($unit);
                $unitid = $unitinstance->get_id();
                if ($unitinstance instanceof unit_relations) {
                    $updatedentities['relationupdate'][$unitinstance->get_id()][] = [
                        'child' => $unitinstance->get_childid(),
                        'parent' => $unitinstance->get_parentid(),
                    ];
                    $unitid = $unitinstance->get_childid();
                }
                $unitmemberinstance =
                    unit_member::update_or_create($user, $unitid);
                if ($unitmemberinstance instanceof unit_member) {
                    $updatedentities['unitmember'][$unitmemberinstance->get_userid()][] = [
                        'unit' => $unitmemberinstance->get_unitid(),
                    ];
                }
            }
        }
        self::trigger_unit_relation_updated_events($updatedentities['relationupdate']);
        self::trigger_unit_member_updated_events($updatedentities['unitmember']);
    }

    /**
     * Private constructor to prevent direct instantiation.
     * @param stdClass $user
     * @return array
     */
    public function generate_units_data($user) {
        $organisations = explode("\\", $user->Organisation);
        $unit = null;
        $parent = null;
        foreach ($organisations as $organisation) {
            $unit = (object) [
                'name' => $organisation,
                'parent' => $parent,
            ];
            organisational_unit_factory::create_unit($unit);
            $parent = $unit->name;
        }

        return [
            'userid' => $user->userID,
            'since' => $user->EntryDate,
            'exit' => $user->ExitDate,
            'unit' => $unit->name ?? null,
            'role' => $unit->KisimRolle1 ?? null,
            'manager' => 'todo',
        ];
    }

    /**
     * Private constructor to prevent direct instantiation.
     * @param array $relationupdate
     * @return void
     */
    public function trigger_unit_relation_updated_events($relationupdate) {
        foreach ($relationupdate as $relationupdates) {
            foreach ($relationupdates as $relationupdate) {
                $event = unit_relation_updated::create([
                    'objectid' => $relationupdate['child'],
                    'context'  => \context_system::instance(),
                    'userid'   => $relationupdate['child'],
                    'other'    => [
                        'parent' => (int) $relationupdate['parent'],
                        'child' => (int) $relationupdate['child'],
                    ],
                ]);
                \local_taskflow\observer::call_event_handler($event);
            }
        }
    }

    /**
     * Private constructor to prevent direct instantiation.
     * @param array $unitmembers
     * @return void
     */
    public function trigger_unit_member_updated_events($unitmembers) {
        foreach ($unitmembers as $unitmemberid => $unitmember) {
            foreach ($unitmember as $unit) {
                $event = unit_member_updated::create([
                    'objectid' => $unitmemberid,
                    'context'  => \context_system::instance(),
                    'userid'   => $unitmemberid,
                    'other'    => [
                        'unitid' => $unit['unit'],
                        'unitmemberid' => $unitmemberid,
                    ],
                ]);
                \local_taskflow\observer::call_event_handler($event);
            }
        }
    }

    /**
     * Private constructor to prevent direct instantiation.
     */
    public function get_external_data() {
        return $this->externaldata;
    }
}
