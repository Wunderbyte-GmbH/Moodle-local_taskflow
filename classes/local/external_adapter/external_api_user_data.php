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

namespace local_taskflow\local\external_adapter;

use local_taskflow\event\unit_member_updated;
use local_taskflow\event\unit_relation_updated;
use local_taskflow\local\personas\unit_member;
use local_taskflow\local\units\unit_relations;
use local_taskflow\local\personas\moodle_user;
use local_taskflow\local\units\unit;
use stdClass;
/**
 * Class unit
 *
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class external_api_user_data extends external_api_base {
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
        }
        $updatedentities = [
            'relationupdate' => [],
            'unitmember' => [],
        ];
        foreach ($translateduserdata as $persondata) {
            $moodleuser = new moodle_user($persondata);
            $user = $moodleuser->update_or_create();

            foreach ($persondata['units'] as $unit) {
                $unitinstance = unit::create_unit($unit);
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
        foreach ($updatedentities['relationupdate'] as $relationupdates) {
            foreach ($relationupdates as $unitrelationid => $relationupdate) {
                $event = unit_relation_updated::create([
                    'objectid' => $relationupdate['child'],
                    'context'  => \context_system::instance(),
                    'userid'   => $relationupdate['child'],
                    'other'    => [
                        'parent' => json_encode($relationupdate['parent']),
                        'child' => json_encode($relationupdate['child']),
                        'unitrelationid' => json_encode($unitrelationid),
                    ],
                ]);
                \local_taskflow\observer::call_event_handler($event);
            }
        }
        foreach ($updatedentities['unitmember'] as $unitmemberid => $unitmember) {
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
