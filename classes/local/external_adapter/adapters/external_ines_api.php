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

use DateTime;
use local_taskflow\event\unit_updated;
use local_taskflow\local\assignments\assignments_facade;
use local_taskflow\local\supervisor\supervisor;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/cohort/lib.php');

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
    /** @var array Stores the external user data. */
    protected array $issidmatching = [];

    /**
     * Private constructor to prevent direct instantiation.
     */
    public function process_incoming_data() {
        $this->create_or_update_units();
        $this->create_or_update_users();
        $this->create_or_update_supervisor();

        // Trigger unit update.
        foreach ($this->unitmapping as $unitid) {
            $event = unit_updated::create([
                'objectid' => $unitid,
                'context'  => \context_system::instance(),
                'userid'   => $unitid,
                'other'    => [
                    'unitid' => $unitid,
                ],
            ]);
            \local_taskflow\observer::call_event_handler($event);
        }
    }

    /**
     * Private constructor to prevent direct instantiation.
     */
    private function create_or_update_supervisor() {
        foreach ($this->externaldata->persons as $user) {
            $translateduser = $this->translate_incoming_data($user);
            $supervisorid = $this->issidmatching[$translateduser['supervisor']] ?? null;
            $userid = $this->issidmatching[$translateduser['tissid']] ?? null;
            if (
                $supervisorid &&
                $userid
            ) {
                $supervisorinstance = new supervisor($supervisorid, $userid);
                $supervisorinstance->set_supervisor_for_user();
            }
        }
    }

    /**
     * Private constructor to prevent direct instantiation.
     */
    private function create_or_update_units() {
        foreach ($this->externaldata->targetGroups as $targetgroup) {
            $translatedtargetgroup = $this->translate_incoming_target_grous($targetgroup);
            $unit = $this->unitrepo->create_unit((object)$translatedtargetgroup);
            $this->unitmapping[$translatedtargetgroup['unitid']] = $unit->get_id();
        }
    }

    /**
     * Private constructor to prevent direct instantiation.
     */
    private function create_or_update_users() {
        foreach ($this->externaldata->persons as $user) {
            $translateduser = $this->translate_incoming_data($user);
            $user = $this->userrepo->update_or_create($translateduser);
            $this->issidmatching[$translateduser['tissid']] = $user->id;
            if ($this->contract_ended($translateduser)) {
                assignments_facade::set_all_assignments_inactive($user->id);
            } else {
                self::create_or_update_unit_members($translateduser, $user);
            }
        }
    }

    /**
     * Private constructor to prevent direct instantiation.
     * @param array $translateduser
     * @return bool
     */
    private function contract_ended($translateduser) {
        $enddate = DateTime::createFromFormat('Y-m-d', $translateduser['end'] ?? '');
        $now = new DateTime();
        if (
            $enddate &&
            $enddate < $now
        ) {
            return true;
        }
        return false;
    }

    /**
     * Private constructor to prevent direct instantiation.
     * @param array $translateduser
     * @param stdClass $user
     */
    private function create_or_update_unit_members($translateduser, $user) {
        foreach ($translateduser['units'] as $unitid) {
            if (!empty($this->unitmapping[$unitid])) {
                $unitmemberinstance =
                    $this->unitmemberrepo->update_or_create($user, $this->unitmapping[$unitid]);
                if (get_config('local_taskflow', 'organisational_unit_option') == 'cohort') {
                    cohort_add_member($this->unitmapping[$unitid], $user->id);
                }
            }
        }
    }
}
