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
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\local\assignment_operators;

use local_taskflow\local\actions\actions_factory;
use local_taskflow\local\messages\messages_factory;

/**
 * Class unit
 *
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class action_operator {
    /** @var string Event name for user updated. */
    public int $userid;
    /**
     * Get the instance of the class for a specific ID.
     * @param int $userid
     */
    public function __construct($userid) {
        $this->userid = $userid;
    }
    /**
     * Get the instance of the class for a specific ID.
     * @param mixed $rule
     * @return void
     */
    public function check_and_trigger_actions($rule) {
        if ($rule->get_isactive() != '1') {
            return;
        }
        $rulejson = json_decode($rule->get_rulesjson());
        $rulejson = $rulejson->rulejson ?? null;
        if ($rulejson == null) {
            return;
        }

        foreach ($rulejson->rule->actions as $action) {
            $shedulemessages = false;
            foreach ($action->targets as $target) {
                $actioninstance = actions_factory::instance($target, $this->userid);
                if ($actioninstance) {
                    if ($actioninstance->is_active()) {
                        $actioninstance->execute($rule, $this->userid);
                        $shedulemessages = true;
                    }
                }
            }

            if ($shedulemessages) {
                foreach ($action->messages as $message) {
                    $assignmentmessageinstance = messages_factory::instance(
                        $message,
                        $this->userid,
                        $rule->get_id()
                    );
                    if (
                        $assignmentmessageinstance != null &&
                        !$assignmentmessageinstance->was_already_send()
                    ) {
                        $assignmentmessageinstance->shedule_message();
                    }
                }
            }
        }
    }
}
