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
 * Queues the sends of a released burst.
 *
 * @package local_taskflow
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\task;

use local_taskflow\local\messages\bulk_check\bulk_check;

/**
 * Turns the releasing rows of one message back into send tasks.
 *
 * A burst can hold thousands of sends, so the admin only moves them to releasing and this
 * picks the work up afterwards, a batch at a time. Anything left over is picked up by the
 * next run, so a burst of any size gets through without a single long request.
 *
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class release_bulk_check extends \core\task\adhoc_task {
    /** @var int How many sends are queued per run. */
    private const BATCHSIZE = 500;

    /** @var int How many batches one run gets through before it hands back to cron. */
    private const MAXBATCHES = 10;

    /**
     * Queues the send tasks of everything waiting to be released.
     *
     * @return void
     */
    public function execute() {
        $data = (object) $this->get_custom_data();
        $messageid = (int) ($data->messageid ?? 0);
        if (empty($messageid)) {
            return;
        }

        for ($batch = 0; $batch < self::MAXBATCHES; $batch++) {
            $queued = bulk_check::queue_released_batch($messageid, self::BATCHSIZE);
            if ($queued < self::BATCHSIZE) {
                // The last batch was short, so there is nothing left to release.
                return;
            }
        }

        // Still more to do. Hand the rest to a fresh task rather than run on.
        $task = new self();
        $task->set_custom_data(['messageid' => $messageid]);
        $task->set_next_run_time(time());
        \core\task\manager::queue_adhoc_task($task);
    }
}
