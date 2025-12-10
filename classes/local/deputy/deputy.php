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

namespace local_taskflow\local\deputy;

use core_user;
use local_taskflow\local\external_adapter\external_api_base;
use local_taskflow\plugininfo\taskflowadapter;
use stdClass;

/**
 * Class unit
 *
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class deputy {
    /** @var stdClass $user */
    private $user;

    /**
     * Deputy class.
     * @param stdClass $user The record from the database.
     */
    public function __construct(stdClass $user) {
        $this->user = $user;
        profile_load_custom_fields($this->user);
    }

    /**
     * Return all deputies of user.
     * @return array
     */
    public function get_deputies_of_user() {
        $deputies = [];
        $deputyfield = external_api_base::return_shortname_for_functionname(taskflowadapter::TRANSLATOR_USER_DEPUTY);
        $deputycustomfield = $this->user->profile[$deputyfield];
        $deputyids = explode(',', $deputycustomfield);
        foreach ($deputyids as $deputyid) {
            $deputies[] = core_user::get_user($deputyid, '*', MUST_EXIST);
        }
        return $deputies;
    }
}
