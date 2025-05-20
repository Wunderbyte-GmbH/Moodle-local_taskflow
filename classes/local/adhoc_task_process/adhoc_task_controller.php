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

namespace local_taskflow\local\adhoc_task_process;

use local_taskflow\local\assignment_process\assignments\assignments_controller;
use local_taskflow\local\assignments\assignments_facade;
use local_taskflow\local\assignment_process\filters\filters_controller;
use local_taskflow\local\messages\messages_factory;
use local_taskflow\local\assignment_operators\action_operator;
use local_taskflow\local\rules\rules;

/**
 * Class user_updated event handler.
 *
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class adhoc_task_controller {
    /** @var assignments_facade Stores the external user data. */
    protected assignments_controller $fassignment;

    /** @var assignments_controller Stores the external user data. */
    protected filters_controller $ffilter;

    /** @var messages_factory Stores the external user data. */
    protected messages_factory $fmessages;

    /**
     * Private constructor to prevent direct instantiation.
     * @param assignments_controller $fassignment
     * @param filters_controller $ffilter
     * @param messages_factory $fmessages
     */
    public function __construct(
        assignments_controller $fassignment,
        filters_controller $ffilter,
        messages_factory $fmessages,
    ) {
        $this->fassignment = $fassignment;
        $this->ffilter = $ffilter;
        $this->fmessages = $fmessages;
    }

    /**
     * React on the triggered event.
     * @return void
     */
    public function process_assignments(): void {
        global $DB;
        $assignments = $this->fassignment->get_open_and_active_assignments();
        foreach ($assignments as $assignment) {
            $assignment->rulejson = json_decode($assignment->rulejson);
            $rule = rules::instance($assignment->ruleid);
            if ($this->ffilter->check_if_user_passes_filter($assignment->userid, $rule)) {
                $assignmentaction = new action_operator($assignment->userid);
                $assignmentaction->check_and_trigger_actions($rule);
            }
        }
    }
}
