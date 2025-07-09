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

namespace local_taskflow\local\users_profile\types;

use local_taskflow\local\users_profile\users_profile_interface;
use stdClass;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/user/lib.php');

/**
 * Class unit_member
 *
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ines implements users_profile_interface {
    /** @var array $userprofiledata The unique ID of the unit. */
    public $userprofiledata;

    /**
     * Update the current unit.
     * @param array $userprofiledata
     * @return stdClass
     */
    public function __construct($userprofiledata) {
        $this->userprofiledata = $userprofiledata;
    }
    /**
     * Update the current unit.
     */
    public function update_or_create() {
        global $DB;
        /*
         $moodeluser = \core_user::get_user_by_email($this->userprofiledata['email']);
         if ($moodeluser) {
             $moodeluser->profile_field_internalid_info = json_encode($this->userprofiledata['internalid'] ?? '');
             $moodeluser->profile_field_unit_info = json_encode($this->userprofiledata['units'] ?? '');
             $moodeluser->profile_field_organisational_unit_info = json_encode($this->userprofiledata['orgunit'] ?? '');
             $moodeluser->profile_field_end_info = json_encode($this->userprofiledata['end'] ?? '');
             profile_save_data($moodeluser);
        }
        */
    }
}
