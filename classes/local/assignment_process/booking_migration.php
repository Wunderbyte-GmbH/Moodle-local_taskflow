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

namespace local_taskflow\local\assignment_process;

use mod_booking\singleton_service;
use stdClass;

/**
 * Class user_updated event handler.
 *
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_migration {
    /** @var array Stores the external user data. */
    protected array $answer;

    /** @var int Stores the external user data. */
    protected int $userid;

    /** @var stdClass Stores the external user data. */
    protected stdClass $rulejson;

    /**
     * Private constructor to prevent direct instantiation.
     * @param string $userid
     * @param array $rule
     */
    public function __construct($userid, $rule) {
        $this->answer = [];
        $this->userid = $userid;
        $this->rulejson = json_decode($rule->get_rulesjson());
    }

    /**
     * React on the triggered event.
     * @return bool
     */
    public function was_already_finished(): bool {
        foreach ($this->rulejson->rulejson->rule->actions as $assignments) {
            if (!empty($assignments->targets)) {
                foreach ($assignments->targets as $target) {
                    if ($target->targettype != 'bookingoption') {
                        return false;
                    } else if (!empty($this->has_no_user_answer($target))) {
                        return false;
                    }
                }
                return true;
            }
        }
        return false;
    }

    /**
     * React on the triggered event.
     * @return bool
     */
    private function has_no_user_answer($target): bool {
        global $DB;
        $settings = singleton_service::get_instance_of_booking_option_settings($target->targetid);
        if (!isset($settings->bookingid)) {
            return true;
        }

        $ba = singleton_service::get_instance_of_booking_answers($settings);
        $this->answer[$settings->id] = $ba->return_last_completion($this->userid);
        return empty($this->answer[$settings->id]->id);
    }

    /**
     * React on the triggered event.
     * @return bool
     */
    public function is_still_running(): bool {
        return false;
    }

    /**
     * React on the triggered event.
     * @return void
     */
    public function open_old_and_reschedule_check(): void {
        return;
    }

    /**
     * React on the triggered event.
     * @return void
     */
    public function log_old_open_new_and_reschedule_check(): void {
        return;
    }
}
