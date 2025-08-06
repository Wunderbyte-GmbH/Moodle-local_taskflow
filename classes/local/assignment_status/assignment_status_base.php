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

namespace local_taskflow\local\assignment_status;

use local_taskflow\local\assignments\status\assignment_status;
use local_taskflow\local\history\history;

/**
 * Class unit
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class assignment_status_base implements assignment_status_interface {
    /** @var string $identifier The state of the assignment. */
    protected $identifier;

    /** @var string $name The status of the assignment. */
    protected $name;

    /** @var string $label The time of last modification. */
    protected $label;

    /**
     * Factory for the organisational units.
     * @param string $assignment
     * @return void
     */
    public function execute($assignment): void {
        history::log(
            $assignment['id'],
            $assignment['userid'],
            history::TYPE_STATUS_CHANGED,
            [
                'action' => 'updated',
                'data' => [
                    'comment' => 'Status changed to ' . $this->label,
                ],
            ],
            $data['usermodified'] ?? null
        );
        return;
    }

    /**
     * Factory for the organisational units.
     * @return string
     */
    public function get_identifier(): string {
        return $this->identifier;
    }

    /**
     * Factory for the organisational units.
     * @return string
     */
    public function get_name(): string {
        return $this->name;
    }

    /**
     * Factory for the organisational units.
     * @return string
     */
    public function get_label(): string {
        return $this->label;
    }
}
