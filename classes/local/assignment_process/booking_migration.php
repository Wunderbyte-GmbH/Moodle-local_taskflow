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

use local_taskflow\local\assignments\types\standard_assignment;
use local_taskflow\local\history\history;
use local_taskflow\task\check_assignment_status;
use local_taskflow\task\reset_cyclic_assignment;
use mod_booking\singleton_service;
use core\task\manager;
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
    protected array $answers;

    /** @var int Stores the external user data. */
    protected int $userid;

    /** @var stdClass Stores the external user data. */
    protected stdClass $rulejson;

    /** @var int Stores the external user data. */
    protected int $ruleid;

    /**
     * Private constructor to prevent direct instantiation.
     * @param string $userid
     * @param object $rule
     */
    public function __construct($userid, $rule) {
        $this->answers = [];
        $this->userid = $userid;
        $this->rulejson = json_decode($rule->get_rulesjson());
        $this->ruleid = $rule->get_id();
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
                    } else if ($this->has_no_user_answer($target)) {
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
     * @param array $assignment
     * @return void
     */
    public function log_old_completion($assignment): void {
        $lastcompleted = $this->get_last_answer_date();
        history::log(
            $assignment['id'],
            $assignment['userid'],
            history::TYPE_STATUS_CHANGED,
            [
                'action' => 'updated',
                'data' => [
                    'comment' => 'Assignment was completed on ' .
                        userdate($lastcompleted, get_string('strftimedatetime', 'langconfig')),
                ],
            ],
            $assignment['userid']
        );
        return;
    }

    /**
     * React on the triggered event.
     * @return bool
     */
    public function has_no_exsisting_assignment(): bool {
        $object = (object)[
            'userid' => $this->userid,
            'ruleid' => $this->ruleid,
        ];
        $assignment = standard_assignment::get_assignment_by_userid_ruleid($object);
        return $assignment == false;
    }

    /**
     * React on the triggered event.
     * @param object $target
     * @return bool
     */
    private function has_no_user_answer($target): bool {
        global $DB;
        $settings = singleton_service::get_instance_of_booking_option_settings($target->targetid);
        if (!isset($settings->bookingid)) {
            return true;
        }

        $ba = singleton_service::get_instance_of_booking_answers($settings);
        $this->answers[$settings->id] = $ba->return_last_completion($this->userid);
        return empty($this->answers[$settings->id]->id);
    }

    /**
     * React on the triggered event.
     * @return bool
     */
    public function is_still_running(): bool {
        $cyclicduration = $this->rulejson->rulejson->rule->cyclicduration ?? null;
        $lastanswer = $this->get_last_answer_date();
        return ($lastanswer + $cyclicduration) > time();
    }

    /**
     * React on the triggered event.
     * @return int
     */
    private function get_last_answer_date(): int {
        $lastanswer = 0;
        foreach ($this->answers as $answer) {
            if (
                isset($answer->timemodified) &&
                $lastanswer < $answer->timemodified
            ) {
                $lastanswer = $answer->timemodified;
            }
        }
        return $lastanswer;
    }

    /**
     * React on the triggered event.
     * @return void
     */
    private function open_assignemnt(): void {
        $duration = $this->rulejson->rulejson->rule->duration ?? null;
        $lastanswer = $this->get_last_answer_date();

        $task = new check_assignment_status();
        $customdata = [
            'userid' => (string) $this->userid,
            'ruleid' => (string) 1,
            'assignmentid' => (string) 1,
        ];
        $task->set_custom_data($customdata);
        $task->set_next_run_time($lastanswer + $duration);
        manager::reschedule_or_queue_adhoc_task($task);
        return;
    }

    /**
     * React on the triggered event.
     * @param array $assignment
     * @return void
     */
    public function schedule_cyclic_reopening($assignment): void {
        $cyclicduration = $this->rulejson->rulejson->rule->cyclicduration ?? null;
        $lastanswer = $this->get_last_answer_date();
        $task = new reset_cyclic_assignment();
        $customdata = [
            'userid' => (string) $this->userid,
            'assignmentid' => $assignment['id'],
        ];
        $task->set_custom_data($customdata);
        $task->set_next_run_time($lastanswer + $cyclicduration);
        manager::reschedule_or_queue_adhoc_task($task);
        return;
    }
}
