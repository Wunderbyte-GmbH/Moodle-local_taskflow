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

namespace local_taskflow\local\personas\unit_members;

use local_taskflow\local\personas\unit_members\types\unit_member;

/**
 * Contract for dependecy injection
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface unit_member_repository_interface {
    /**
     * Private constructor to prevent direct instantiation.
     * @param mixed $user
     * @param int $unitid
     * @return unit_member
     */
    public function update_or_create(mixed $user, int $unitid): ?unit_member;

    /**
     * Private constructor to prevent direct instantiation.
     * @param int $userid
     * @param int $unitid
     * @return bool
     */
    public function remove($userid, $unitid);
}
