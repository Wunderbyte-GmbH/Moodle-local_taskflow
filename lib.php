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
 * Plugin callbacks.
 *
 * @package     local_taskflow
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Adds the Taskflow person page to the user profile.
 *
 * @param \core_user\output\myprofile\tree $tree
 * @param stdClass $user
 * @param bool $iscurrentuser
 * @param stdClass|null $course
 * @return void
 */
function local_taskflow_myprofile_navigation(\core_user\output\myprofile\tree $tree, $user, $iscurrentuser, $course): void {
    global $USER;
    if (!\local_taskflow\output\personpage::can_view((int)$USER->id, (int)$user->id)) {
        return;
    }
    $url = new moodle_url('/local/taskflow/person.php', ['id' => $user->id]);
    $node = new \core_user\output\myprofile\node(
        'miscellaneous',
        'local_taskflow_personpage',
        \local_taskflow\taskflow_stringmanager::get_string('personpage'),
        null,
        $url
    );
    $tree->add_node($node);
}
