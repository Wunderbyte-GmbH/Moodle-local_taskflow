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

use local_taskflow\local\assignment_status\types\assigned;
use local_taskflow\local\assignment_status\types\planned;
use stdClass;

/**
 * Class unit
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignment_status_facade {
    /**
     * Factory for the organisational units.
     * @param \local_taskflow\local\assignments\assignment $oldassignment
     * @param array $newassignment
     * @return void
     */
    public static function execute($oldassignment, $newassignment): void {
        $allstatus = self::get_all();
        $typekey = $allstatus[$newassignment['status']]['label'];
        $statustypeclass = 'local_taskflow\\local\\assignment_status\\types\\' . $typekey;
        $factory = $statustypeclass::get_instance();
        $factory->execute($newassignment);
        return;
    }

    /**
     * Factory for the organisational units.
     * @param object $assignment
     * @param string $status
     * @return void
     */
    public static function change_status(&$assignment, $status): void {
        $allstatus = self::get_all();
        $typekey = $allstatus[$status]['label'];
        $statustypeclass = 'local_taskflow\\local\\assignment_status\\types\\' . $typekey;
        $factory = $statustypeclass::get_instance();
        $factory->change_status($assignment);
        return;
    }

    /**
     * Factory for the organisational units.
     * @return array
     */
    public static function get_all(): array {
        $allstatus = [];
        $folder = __DIR__ . '/types';
        foreach (glob($folder . '/*.php') as $file) {
            $typekey = basename($file, '.php');
            $statustypeclass = 'local_taskflow\\local\\assignment_status\\types\\' . $typekey;
            $factory = $statustypeclass::get_instance();
            $allstatus[$factory->get_identifier()] = [
                'name' => $factory->get_name(),
                'label' => $factory->get_label(),
            ];
        }
        return $allstatus;
    }

    /**
     * Factory for the organisational units.
     * @param array $record
     * @param stdClass $rulejson
     * @return array
     */
    public static function set_initial_status($record, $rulejson): array {
        if (
            isset($rulejson->rulejson->rule->activationdelay) &&
            $rulejson->rulejson->rule->activationdelay > 0
        ) {
            $statusmanager = planned::get_instance();
        } else {
            $statusmanager = assigned::get_instance();
        }
        $assignment = (object)$record;
        $statusmanager->change_status($assignment);
        return (array)$assignment;
    }
}
