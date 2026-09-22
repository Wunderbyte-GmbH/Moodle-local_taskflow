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
 * Keeps the bulk check table small.
 *
 * @package local_taskflow
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\task;

use local_taskflow\local\messages\bulk_check\bulk_check;
use local_taskflow\taskflow_stringmanager;

/**
 * Removes the rows of the bulk check that can no longer matter.
 *
 * Most rows leave the table the moment they stop mattering. The one kind that cannot is
 * the sent row: it keeps counting towards the window for a while after the mail went out,
 * so that a slow flood is caught as well as a burst. This is what takes those away once
 * their window has closed, and it tidies up the two things that can only be found by
 * looking, a pending row whose task died and a release that nobody is working on.
 *
 * It runs whether or not the check is switched on: the rows of a check that was switched
 * off are exactly the ones that nobody will look at again.
 *
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bulk_check_cleanup extends \core\task\scheduled_task {
    /**
     * Get's the name.
     *
     * @return string
     */
    public function get_name() {
        return taskflow_stringmanager::get_string('bulkcheckcleanup');
    }

    /**
     * Runs one round of the cleanup.
     *
     * @return void
     */
    public function execute() {
        $result = bulk_check::cleanup();
        mtrace(sprintf(
            'Bulk check cleanup: %d sent rows removed, %d orphaned rows removed, %d releases rescued.',
            $result['sent'],
            $result['orphaned'],
            $result['rescued']
        ));
    }
}
