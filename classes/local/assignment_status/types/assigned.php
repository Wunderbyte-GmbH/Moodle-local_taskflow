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

namespace local_taskflow\local\assignment_status\types;

use local_taskflow\local\assignment_status\assignment_status_base;

/**
 * Class unit
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assigned extends assignment_status_base {
    /** @var assigned */
    private static ?assigned $instance = null;

    /**
     * Constructor
     */
    private function __construct() {
        $this->identifier = 0;
        $this->name = get_string('statusassigned', 'local_taskflow');
        $this->label = 'assigned';
    }

    /**
     * Instanciator
     * @return assigned
     */
    public static function get_instance(): assigned {
        if (self::$instance === null) {
            self::$instance = new assigned();
        }
        return self::$instance;
    }

    /**
     * Factory for the organisational units.
     * @param object $assignment
     * @return void
     */
    public function change_status(&$assignment): void {
        $assignment->status = $this->identifier;
        $assignment->active = 1;
        return;
    }
}
