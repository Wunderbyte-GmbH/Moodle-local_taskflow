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

namespace local_taskflow\local\assignments;

use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\external_adapter\external_api_base;
use local_taskflow\task\check_assignment_status;
use local_taskflow\plugininfo\taskflowadapter;
use local_taskflow\local\history\history;
use core\task\manager;
use cache_helper;
use stdClass;

/**
 * Class to handle last seen time of assignments.
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignment_seen {
    /** @var int $userid Unique identifier for the assignment, automatically managed by the database. */
    private $userid;

    /** @var int $assignmentid Contains target-related data, possibly stored as JSON or serialized string. */
    private $assignmentid;

    /**
     * Constructor for the assignment class.
     *
     * @param int $assignmentid
     *
     */
    public function __construct(int $userid, int $assignmentid) {
        $this->userid = $userid;
        $this->assignmentid = $assignmentid;
    }

    /**
     * Create or update last seen time for an assignment.
     * @return void
     *
     */
    public function update_or_create_last_seen(): void {
        global $DB;

        $now = time();

        // Try to get existing record.
        $record = $DB->get_record(
            'local_taskflow_last_seen',
            [
                'userid' => $this->userid,
                'assignmentid' => $this->assignmentid,
            ],
            '*',
            IGNORE_MISSING
        );

        if ($record) {
            // Update existing entry.
            $record->lastseen = $now;
            $DB->update_record('local_taskflow_last_seen', $record);
            return;
        }

        // Create new entry.
        $newrecord = (object)[
            'userid' => $this->userid,
            'assignmentid' => $this->assignmentid,
            'lastseen' => $now,
        ];

        $DB->insert_record('local_taskflow_last_seen', $newrecord);
        return;
    }
}
