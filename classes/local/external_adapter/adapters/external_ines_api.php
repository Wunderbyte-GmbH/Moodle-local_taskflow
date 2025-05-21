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
use stdClass;

/**
 * Class unit
 *
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class external_ines_api extends external_api_base implements external_api_interface {
    /**
     * Private constructor to prevent direct instantiation.
     */
    public function process_incoming_data() {
        self::create_or_update_units();
        self::create_or_update_users();
        // Create unituser check if something changed add/remove if so update user_member.
    }

    /**
     * Private constructor to prevent direct instantiation.
     */
    private function create_or_update_units() {
        // Check if tissid exists!
        foreach ($this->externaldata->targetGroups as $targetgroup) {
            $translatedtargetgroup = $this->translate_incoming_target_grous($targetgroup);
            $unit = $this->unitrepo->create_unit((object)$translatedtargetgroup);
            $this->unitmapping[$translatedtargetgroup['tissid']] = $unit->get_id();
        }
    }

    /**
     * Private constructor to prevent direct instantiation.
     */
    private function create_or_update_users() {
        foreach ($this->externaldata->persons as $user) {
            $translateduser = $this->translate_incoming_data($user);
            $user = $this->userrepo->update_or_create($translateduser);
            self::create_or_update_unit_members($translateduser, $user);
        }
    }

    /**
     * Private constructor to prevent direct instantiation.
     * @param array $translateduser
     * @param stdClass $user
     * @param array
     */
    private function create_or_update_unit_members($translateduser, $user) {
        $unitids = [];
        foreach ($translateduser['units'] as $unitid) {
            $unitids[] = $this->unitmapping[$unitid];
        }

        foreach ($translateduser['units'] as $unitid) {
            if (!empty($this->unitmapping[$unitid])) {
                $unitmemberinstance =
                    $this->unitmemberrepo->update_or_create($user, $this->unitmapping[$unitid]);
            }
        }
    }
}
