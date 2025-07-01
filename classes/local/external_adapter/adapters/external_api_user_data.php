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

use local_taskflow\local\external_adapter\external_api_interface;
use local_taskflow\local\external_adapter\external_api_base;
use local_taskflow\local\personas\unit_members\types\unit_member;
use local_taskflow\local\units\organisational_unit_factory;
use local_taskflow\local\units\unit_relations;
/**
 * Class unit
 *
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class external_api_user_data extends external_api_base implements external_api_interface {
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
            $user = $this->userrepo->update_or_create($persondata);
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
                    $this->unitmemberrepo->update_or_create($user, $unitid);
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

    public function return_value_for_functionfield(int $functionid, int $userid) {

        $selectedadapter = get_config('local_taskflow', 'external_api_option');

        $subpluginconfig = get_config($selectedadapter);

        $configsflip = array_flip((array)$subpluginconfig);

        $shortname = $configsflip[$functionid];


    }


    /**
     * Returns the corresponding json key.
     *
     * @param string $functionname
     *
     * @return string
     *
     */
    public function return_json_key_for_function(string $functionname) {
        $selectedadapter = get_config('local_taskflow', 'external_api_option');
        $subpluginconfig = get_config($selectedadapter);
        $configsflip = array_flip((array)$subpluginconfig);
        $shortname = $configsflip[$functionname];
        $jsonkey = get_config($shortname, $selectedadapter);
        return $jsonkey;
    }
}
