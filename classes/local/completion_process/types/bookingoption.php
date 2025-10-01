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

namespace local_taskflow\local\completion_process\types;

use local_taskflow\local\rules\rules;
use mod_booking\singleton_service;

/**
 * Class unit
 *
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bookingoption extends types_base implements types_interface {
    /**
     * Update the current unit.
     * @param object $affectedassignment
     * @return bool
     */
    public function is_completed($affectedassignment) {
        // First, retrieve the booking option.
        $settings = singleton_service::get_instance_of_booking_option_settings($this->targetid);
        if (!isset($settings->bookingid)) {
            return false;
        }
        $ba = singleton_service::get_instance_of_booking_answers($settings);
        $rule = rules::instance($affectedassignment->ruleid);
        $rulejson = json_decode($rule->get_rulesjson() ?? '');
        if (
            isset($rulejson->rulejson->rule->cyclicvalidation) &&
            $rulejson->rulejson->rule->cyclicvalidation == '1' &&
            method_exists('\mod_booking\booking_answers\booking_answers', 'return_last_completion')
        ) {
            $lastcompletion = $ba->return_last_completion($this->userid);
            return $lastcompletion->timemodified > (time() - $rulejson->rulejson->rule->cyclicduration);
        }
        return $ba->is_activity_completed($this->userid);
    }
}
