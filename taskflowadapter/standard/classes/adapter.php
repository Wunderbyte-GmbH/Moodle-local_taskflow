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
 * @package taskflowadapter_standard
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace taskflowadapter_standard;

use DateTime;
use local_taskflow\event\unit_updated;
use local_taskflow\local\assignments\assignments_facade;
use local_taskflow\local\supervisor\supervisor;
use local_taskflow\plugininfo\taskflowadapter;

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
class adapter extends external_api_base implements external_api_interface {
    /** @var array Stores the external user data. */
    protected array $issidmatching = [];

    /**
     * Private constructor to prevent direct instantiation.
     */
    public function process_incoming_data() {
        $this->create_or_update_units();
        $this->create_or_update_users();
        $this->create_or_update_supervisor();
        $this->save_all_user_infos($this->users);

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
            $event->trigger();
        }
    }

    /**
     * Creates Supervisor with internalid in customfield.
     */
    private function create_or_update_supervisor() {
        foreach ($this->users as $user) {
            $shortname = $this->return_shortname_for_functionname(taskflowadapter::TRANSLATOR_USER_SUPERVISOR);
            $supervisorid = $user->profile[$shortname] ?? null;
            if ($supervisorid) {
                $supervisorinstance = new supervisor($supervisorid, $user->id);
                $supervisorinstance->set_supervisor_for_user($supervisorid, $shortname, $user, $this->users);
            }
        }
    }

    /**
     * Private constructor to prevent direct instantiation.
     */
    private function create_or_update_units() {
        foreach ($this->externaldata as $personrecord) {
            $jsonkey = $this->return_jsonkey_for_functionname(taskflowadapter::TRANSLATOR_USER_TARGETGROUP);
            if (empty($personrecord->$jsonkey)) {
                continue; // No target groups to process.
            }

            if (!is_array($personrecord->$jsonkey)) {
                $targetgroups = [$personrecord->$jsonkey];
            } else {
                $targetgroups = $personrecord->$jsonkey; // Ensure we have an array of target groups.
            }

            foreach ($targetgroups as $targetgroup) {
                $translatedtargetgroup = $this->translate_incoming_target_groups($targetgroup);
                if (
                    empty($translatedtargetgroup)
                ) {
                    continue; // Skip if no valid target group data.
                }
                $unit = $this->unitrepo->create_unit((object)$translatedtargetgroup);
                // Use 'unitid' if available, otherwise fallback to the unit's name.
                $unitid = !empty($translatedtargetgroup['unitid'])
                    ? $translatedtargetgroup['unitid']
                    : ($translatedtargetgroup['name'] ?? $unit->get_id());
                $this->unitmapping[$unitid] = $unit->get_id();
            }
        }
    }

    /**
     * Private constructor to prevent direct instantiation.
     */
    private function create_or_update_users() {
        foreach ($this->externaldata as $newuser) {
            $translateduser = $this->translate_incoming_data($newuser);

            $olduserunits = $this->userrepo->get_user_targetgroups($translateduser, $this);
            $newuser = $this->userrepo->update_or_create($translateduser);
            $externalidfieldname = $this->return_shortname_for_functionname(taskflowadapter::TRANSLATOR_USER_EXTERNALID);
            $this->create_user_with_customfields($newuser, $translateduser, $externalidfieldname);
            $externalid = $this->return_value_for_functionname(taskflowadapter::TRANSLATOR_USER_EXTERNALID, $newuser);

            if (empty($externalid)) {
                $externalid = $newuser->id; // Fallback to internal ID if no external ID is provided.
            }

            $this->issidmatching[$externalid] = $newuser->id;
            if (
                is_array($olduserunits)
            ) {
                $this->invalidate_units_on_change(
                    $olduserunits,
                    $this->return_value_for_functionname(taskflowadapter::TRANSLATOR_USER_TARGETGROUP, $newuser),
                    $newuser->id
                );
            }
            $onlongleave = $this->return_value_for_functionname(taskflowadapter::TRANSLATOR_USER_LONG_LEAVE, $newuser) ?? 0;
            if (
                $this->contract_ended($newuser) ||
                $onlongleave
            ) {
                assignments_facade::set_all_assignments_inactive($newuser->id);
            } else {
                self::create_or_update_unit_members($translateduser, $newuser);
            }
        }
    }

    /**
     * Private constructor to prevent direct instantiation.
     * @param array $olduserunits
     * @param array $newuserunits
     * @param int $userid
     * @return void
     */
    private function invalidate_units_on_change(
        $olduserunits,
        $newuserunits,
        $userid
    ) {
        $invalidunits = array_diff($olduserunits, $newuserunits);
        if (count($invalidunits) >= 1) {
            $invalidmoodleunitids = [];
            foreach ($invalidunits as $invalidunit) {
                $invalidmoodleunitids[] = $this->unitmapping[$invalidunit];
            }
            assignments_facade::set_user_units_assignments_inactive(
                $userid,
                $invalidmoodleunitids
            );
        }
    }

    /**
     * Private constructor to prevent direct instantiation.
     * @param array $translateduser
     * @return bool
     */
    private function contract_ended($user) {
        $enddate = DateTime::createFromFormat(
            'Y-m-d',
            $this->return_value_for_functionname(
                taskflowadapter::TRANSLATOR_USER_CONTRACTEND,
                $user
            ) ?? ''
        );
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
        $unitsshortname = $this->return_shortname_for_functionname(taskflowadapter::TRANSLATOR_USER_TARGETGROUP);
        $unitidarray = $user->profile[$unitsshortname];

        $unitidkey = external_api_base::return_jsonkey_for_functionname(
            taskflowadapter::TRANSLATOR_TARGET_GROUP_UNITID
        );

        $unitnamekey = external_api_base::return_jsonkey_for_functionname(
            taskflowadapter::TRANSLATOR_TARGET_GROUP_NAME
        );

        foreach ($unitidarray as $unit) {
            $unitid = $unit->{$unitidkey} ?? $unit->{$unitnamekey} ?? null;
            if (empty($unitid)) {
                continue; // Skip if 'id' is not set.
            }

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
