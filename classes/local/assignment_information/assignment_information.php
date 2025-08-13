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

namespace local_taskflow\local\assignment_information;

use local_taskflow\local\actions\targets\targets_factory;
use local_taskflow\local\assignments\types\standard_assignment;

/**
 * Class unit
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignment_information {
    /** @var array */
    private array $activeassignments;

    /** @var array */
    private array $inactiveassignments;

    /**
     * Constructor
     * @param int $userid
     */
    public function __construct($userid) {
        $this->activeassignments = standard_assignment::get_all_active_user_assignments($userid);
        $this->inactiveassignments = standard_assignment::get_all_inactive_user_assignments($userid);
    }

    /**
     * Instanciator
     * @return string
     */
    public function render_information(): string {
        $activetargets = $this->get_assignment_targets($this->activeassignments);
        $inactivetargets = $this->get_assignment_targets($this->inactiveassignments);
        $nolongermandatorytargets = $this->get_not_active_targets(
            $activetargets,
            $inactivetargets
        );
        $enrollednotactivetargets = $this->get_enrolled_and_not_active_targets($nolongermandatorytargets);
        if (empty($enrollednotactivetargets)) {
            return '';
        }
        return get_string('myassignmentinformation', 'local_taskflow', implode(', ', $enrollednotactivetargets));
    }

    /**
     * Instanciator
     * @param array $assignments
     * @return array
     */
    private function get_assignment_targets($assignments): array {
        $sortedtargets = [];
        foreach ($assignments as $assignment) {
            $targets = json_decode($assignment->targets);
            foreach ($targets as $target) {
                if (!in_array($target->targetid, $sortedtargets[$target->targettype] ?? [])) {
                    $sortedtargets[$target->targettype][] = $target->targetid;
                }
            }
        }
        return $sortedtargets;
    }

    /**
     * Instanciator
     * @param array $activetargets
     * @param array $inactivetargets
     * @return array
     */
    private function get_not_active_targets($activetargets, $inactivetargets): array {
        $onlyinactivetargets = [];
        foreach ($inactivetargets as $key => $inactivetarget) {
            $onlyinactivetargets[$key] = array_diff($inactivetarget, $activetargets[$key] ?? []);
        }
        return $onlyinactivetargets;
    }

    /**
     * Instanciator
     * @param array $nolongermandatorytargets
     * @return array
     */
    private function get_enrolled_and_not_active_targets($nolongermandatorytargets): array {
        $enrolledinactivetargets = [];
        foreach ($nolongermandatorytargets as $type => $targetids) {
            foreach ($targetids as $targetid) {
                $enrolledinactivetargets[] = targets_factory::get_name($type, $targetid);
            }
        }
        return  $enrolledinactivetargets;
    }
}
