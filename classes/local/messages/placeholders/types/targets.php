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

namespace local_taskflow\local\messages\placeholders\types;

use local_taskflow\local\actions\targets\targets_factory;
use local_taskflow\local\messages\placeholders\placeholders_interface;
use local_taskflow\local\rules\rules;
use stdClass;

/**
 * Class unit
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class targets implements placeholders_interface {
    /** @var mixed Event name for user updated. */
    public mixed $rule;

    /** @var mixed Event name for user updated. */
    public mixed $user;

    /** @var stdClass Event name for user updated. */
    public stdClass $assignment;

    /**
     * Factory for the organisational units
     * @param int $ruleid
     * @param int $userid
     * @param stdClass $assignment
     */
    public function __construct($ruleid, $userid, $assignment) {
        $this->rule = $this->get_rule($ruleid);
        $this->user = \core_user::get_user($userid);
        $this->assignment = $assignment;
    }

    /**
     * Factory for the organisational units
     * @param stdClass $message
     */
    public function render(&$message) {
        $placeholdertarget = "<targets>";
        $placeholderreplace = $this->get_replacement($message->id);
        foreach ($message->message as &$messagepart) {
            $messagepart = str_replace(
                $placeholdertarget,
                $placeholderreplace,
                $messagepart
            );
        }
    }

    /**
     * Factory for the organisational units
     * @param int $ruleid
     * @return \local_taskflow\local\rules\rules
     */
    private function get_rule($ruleid) {
        return rules::instance($ruleid);
    }

    /**
     * Factory for the organisational units
     * @param int $messageid
     * @return string
     */
    private function get_replacement($messageid) {
        $targets = [];
        $rulejson = json_decode($this->rule->get_rulesjson());
        $actions = $rulejson->rulejson->rule->actions ?? [];
        foreach ($actions as $action) {
            if ($this->is_messageid_inside($action, $messageid)) {
                foreach ($action->targets as $target) {
                    $targets[] = targets_factory::get_name_with_link(
                        $target->targettype,
                        $target->targetid,
                        $this->assignment->id
                    );
                }
            }
        }
        return implode(', ', $targets);
    }

    /**
     * Factory for the organisational units
     * @param stdClass $action
     * @param int $messageid
     * @return bool
     */
    private function is_messageid_inside($action, $messageid) {
        foreach ($action->messages as $message) {
            if ($message->messageid == $messageid) {
                return true;
            }
        }
        return false;
    }
}
