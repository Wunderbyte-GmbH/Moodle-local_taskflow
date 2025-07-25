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

use local_taskflow\local\assignment_process\filters\filters_controller;
use local_taskflow\local\assignment_process\assignments\assignments_controller;

/**
 * Class user_updated event handler.
 *
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignment_controller {
    /** @var array Stores the external user data. */
    protected array $allaffectedusers;

    /** @var array Stores the external user data. */
    protected array $allaffectedrules;

    /** @var filters_controller Stores the external user data. */
    protected filters_controller $filter;

    /** @var assignments_controller Stores the external user data. */
    protected assignments_controller $assignment;

    /**
     * Private constructor to prevent direct instantiation.
     * @param array $allaffectedusers
     * @param array $allaffectedrules
     * @param filters_controller $filter
     * @param assignments_controller $assignment
     */
    public function __construct(
        array $allaffectedusers,
        array $allaffectedrules,
        filters_controller $filter,
        assignments_controller $assignment
    ) {
        $this->allaffectedusers = $allaffectedusers;
        $this->allaffectedrules = $allaffectedrules;
        $this->filter = $filter;
        $this->assignment = $assignment;
    }

    /**
     * React on the triggered event.
     * @return void
     */
    public function process_assignments(): void {
        foreach ($this->allaffectedusers as $userid) {
            foreach ($this->allaffectedrules as $unitrule) {
                foreach ($unitrule as $rule) {
                    if ($this->filter->check_if_user_passes_filter($userid, $rule)) {
                        $this->assignment->construct_and_process_assignment($userid, $rule);
                    } else {
                        $this->assignment->inactivate_existing_assignment($userid, $rule);
                    }
                }
            }
        }
    }
}
