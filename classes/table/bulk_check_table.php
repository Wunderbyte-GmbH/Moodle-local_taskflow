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
 * Table of messages whose sending was parked by the bulk check.
 *
 * @package local_taskflow
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\table;

use cache_helper;
use context_system;
use html_writer;
use local_taskflow\local\messages\bulk_check\bulk_check;
use local_taskflow\taskflow_stringmanager;
use local_wunderbyte_table\wunderbyte_table;

/**
 * One row per mail that the bulk check stopped.
 *
 * Every row carries a checkbox, so that a burst can be let through for the people who should
 * get their mail after all while the rest is given up on. The two buttons that act on the
 * whole list are still there, because a burst can hold thousands of rows and ticking them one
 * page at a time is no way to deal with that.
 *
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bulk_check_table extends wunderbyte_table {
    /** @var bool Every row can be picked out of the list. */
    public $addcheckboxes = true;

    /**
     * Name of the message, or a placeholder when the message itself was deleted.
     *
     * @param object $values
     * @return string
     */
    public function col_messagename($values): string {
        if (empty($values->messagename)) {
            return taskflow_stringmanager::get_string('bulkcheckdeletedmessage', $values->messageid);
        }
        return format_string($values->messagename);
    }

    /**
     * Name of the rule that scheduled the mail, or a placeholder when the rule was deleted.
     *
     * @param object $values
     * @return string
     */
    public function col_rulename($values): string {
        if (empty($values->rulename)) {
            return taskflow_stringmanager::get_string('bulkcheckdeletedrule', $values->ruleid);
        }
        return format_string($values->rulename);
    }

    /**
     * Who the mail was meant for.
     *
     * The column is sorted and searched on the concatenation built in the sql, but shown with
     * the name order of the site.
     *
     * @param object $values
     * @return string
     */
    public function col_recipient($values): string {
        return fullname($values);
    }

    /**
     * When the mail was due to go out.
     *
     * @param object $values
     * @return string
     */
    public function col_scheduledtime($values): string {
        return userdate((int) $values->scheduledtime, get_string('strftimedatetimeshort', 'core_langconfig'));
    }

    /**
     * How long this mail has been waiting for a decision.
     *
     * @param object $values
     * @return string
     */
    public function col_waiting($values): string {
        return html_writer::tag(
            'span',
            taskflow_stringmanager::get_string('bulkcheckwaiting', format_time(time() - (int) $values->timemodified)),
            ['class' => 'text-muted small']
        );
    }

    /**
     * Lets the ticked mails go out after all.
     *
     * @param int $id Always -1, the buttons of this table work on the selection.
     * @param string $data
     * @return array
     */
    public function action_releaseselected(int $id, string $data): array {
        $count = bulk_check::release_rows($this->return_checked_ids($data));
        $this->purge_list();

        return [
            'success' => 1,
            'message' => taskflow_stringmanager::get_string('bulkcheckreleasequeued', $count),
            // The library only reloads the table, but the buttons carry the totals as well.
            'reload' => 1,
        ];
    }

    /**
     * Gives up on the ticked mails.
     *
     * @param int $id Always -1, the buttons of this table work on the selection.
     * @param string $data
     * @return array
     */
    public function action_dismissselected(int $id, string $data): array {
        $count = bulk_check::dismiss_rows($this->return_checked_ids($data));
        $this->purge_list();

        return [
            'success' => 1,
            'message' => taskflow_stringmanager::get_string('bulkcheckdismissed', $count),
            'reload' => 1,
        ];
    }

    /**
     * Lets every parked mail of the list go out after all.
     *
     * The scope is the one the page was built with, not what the list happens to show: the
     * action web service is told neither the filter nor the search, so a button that claimed
     * to act on what is on screen would be lying. The confirmation says so.
     *
     * @param int $id Always -1, the scope travels in the data.
     * @param string $data
     * @return array
     */
    public function action_releaseall(int $id, string $data): array {
        $messageid = $this->return_scope($data);
        $count = empty($messageid) ? bulk_check::release_all() : bulk_check::release_message($messageid);
        $this->purge_list();

        return [
            'success' => 1,
            'message' => taskflow_stringmanager::get_string('bulkcheckreleasequeued', $count),
            'reload' => 1,
        ];
    }

    /**
     * Gives up on every parked mail of the list.
     *
     * @param int $id Always -1, the scope travels in the data.
     * @param string $data
     * @return array
     */
    public function action_dismissall(int $id, string $data): array {
        $messageid = $this->return_scope($data);
        $count = empty($messageid) ? bulk_check::dismiss_all() : bulk_check::dismiss_message($messageid);
        $this->purge_list();

        return [
            'success' => 1,
            'message' => taskflow_stringmanager::get_string('bulkcheckdismissed', $count),
            'reload' => 1,
        ];
    }

    /**
     * The ids of the ticked rows, as far as they can be believed.
     *
     * The payload comes off the client raw, so it is only ever read for its numeric values.
     * Which of those name a row that may still be dealt with is decided in the database.
     *
     * @param string $data
     * @return array
     */
    private function return_checked_ids(string $data): array {
        $this->require_edit_messages();

        $decoded = json_decode($data);
        $ids = $decoded->checkedids ?? [];
        if (!is_array($ids)) {
            return [];
        }
        return $ids;
    }

    /**
     * The message the buttons that act on everything are limited to, zero for all of them.
     *
     * @param string $data
     * @return int
     */
    private function return_scope(string $data): int {
        $this->require_edit_messages();

        $decoded = json_decode($data);
        return (int) ($decoded->messageid ?? 0);
    }

    /**
     * Every action of this table decides over mails, so every one of them is gated.
     *
     * @return void
     */
    private function require_edit_messages(): void {
        require_capability('local/taskflow:editmessages', context_system::instance());
    }

    /**
     * Drops the cached list, so that what was just decided is gone from it.
     *
     * @return void
     */
    private function purge_list(): void {
        cache_helper::purge_by_event('changesinbulkcheckmails');
    }
}
