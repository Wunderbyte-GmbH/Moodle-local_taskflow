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

use core_user;
use local_taskflow\local\assignment_process\assignment_preprocessor;
use local_taskflow\local\personas\moodle_users\moodle_user_factory;
use local_taskflow\local\personas\unit_members\moodle_unit_member_facade;
use local_taskflow\local\units\organisational_unit_factory;
use local_taskflow\plugininfo\taskflowadapter;

/**
 * Class user_updated event handler.
 *
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class unit_member_updated extends base_event_handler {
    /**
     * @var string Event name for user updated.
     */
    public string $eventname = 'local_taskflow\event\unit_member_updated';

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
        $preprocessor = new assignment_preprocessor($data);
        if ($this->not_on_longleave_or_inactive($data['other']['unitmemberid'])) {
            $preprocessor->set_this_user($data['other']['unitmemberid']);
            $preprocessor->set_all_inheritance_unit_rules();
            $preprocessor->process_assignemnts();
        }
    }

    /**
     * React on the triggered event.
     * @param string $userid
     * @return bool
     */
    public function not_on_longleave_or_inactive($userid): bool {
        $userrepo = new moodle_user_factory();
        $unitrepo = new organisational_unit_factory();
        $unitmemberrepo = new moodle_unit_member_facade();
        $type = get_config('local_taskflow', name: 'external_api_option');
        $class = "\\taskflowadapter_{$type}\\adapter";
        $adapter = new $class("", $userrepo, $unitmemberrepo, $unitrepo);
        $user = core_user::get_user($userid, '*', MUST_EXIST);
        profile_load_custom_fields($user);
        $onlongleave = $adapter->return_value_for_functionname(taskflowadapter::TRANSLATOR_USER_LONG_LEAVE, $user) ?? 0;
        if (
            $user->suspended == '1' ||
            $onlongleave == '1'
        ) {
            return false;
        }
        return true;
    }
}
