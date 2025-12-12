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

namespace local_taskflow\local\requests\request_receivers\receivers;

use core_user;
use local_taskflow\local\requests\request_receivers\receiver_base;
use stdClass;

/**
 * Class requests
 *
 * @package    local_taskflow
 * @copyright  2025 Georg Maißer <georg.maißer@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hr_receiver extends receiver_base {
    /** @var int The target ID. */
    public const ID = 1;

    /** @var string The name of the target. */
    public const SETTINGKEY = 'hrreceiver';

    /**
     * Set all request types.
     * @param stdClass $assignment
     * @return array
     */
    public function get_users($assignment): array {
        $recipients = [];
        $hrusersconfig = get_config('local_taskflow', 'hrusers');
        $hrusersids = explode(',', $hrusersconfig);
        foreach ($hrusersids as $userid) {
            $recipients[] = core_user::get_user($userid);
        }
        return $recipients;
    }
}
