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

use context_system;
use html_writer;
use local_taskflow\local\messages\bulk_check\bulk_check;
use local_taskflow\taskflow_stringmanager;
use local_wunderbyte_table\output\table;
use local_wunderbyte_table\wunderbyte_table;

/**
 * One row per message that still has parked sends.
 *
 * The row summarises the burst and the panel below it lists who is affected, together with
 * the two buttons. Both buttons act on the whole message, across every rule that blocked
 * something, which is why the summary and the button labels both carry the real total.
 *
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bulk_check_table extends wunderbyte_table {
    /** @var int How many affected users are listed inside a panel. */
    public const SHOWNUSERS = 50;

    /**
     * Name of the message, or a placeholder when the message itself was deleted.
     *
     * @param object $values
     * @return string
     */
    public function col_messagename($values): string {
        $name = empty($values->messagename)
            ? taskflow_stringmanager::get_string('bulkcheckdeletedmessage', $values->messageid)
            : format_string($values->messagename);

        // The confirmation dialogue of both buttons quotes this cell, so it has to carry
        // the full scope of what they are about to do, not just the name.
        $scope = taskflow_stringmanager::get_string('bulkchecksummary', (object) [
            'parked' => (int) $values->parked,
            'rules' => (int) $values->rules,
        ]);

        return html_writer::tag('strong', $name)
            . ' ' . html_writer::tag('span', $scope, ['class' => 'text-muted small']);
    }

    /**
     * How many sends are parked for this message.
     *
     * @param object $values
     * @return string
     */
    public function col_parked($values): string {
        return html_writer::tag(
            'span',
            (int) $values->parked,
            ['class' => 'badge bg-warning text-dark']
        );
    }

    /**
     * How many rules contributed to the burst.
     *
     * @param object $values
     * @return string
     */
    public function col_rules($values): string {
        return taskflow_stringmanager::get_string('bulkcheckrulecount', (int) $values->rules);
    }

    /**
     * How long the oldest of these sends has been waiting.
     *
     * @param object $values
     * @return string
     */
    public function col_oldest($values): string {
        return taskflow_stringmanager::get_string(
            'bulkcheckwaiting',
            format_time(time() - (int) $values->oldest)
        );
    }

    /**
     * The affected users and the two buttons, shown when the row is expanded.
     *
     * Only a sample of the users is rendered, because a burst can hold thousands of them.
     * The buttons always act on all of them, so the panel says how many are not shown.
     *
     * @param object $values
     * @return string
     */
    public function col_users($values): string {
        global $OUTPUT;

        $messageid = (int) $values->messageid;
        $parked = (int) $values->parked;
        $rows = bulk_check::get_parked_rows($messageid, self::SHOWNUSERS + 1);

        $items = [];
        $shown = 0;
        foreach ($rows as $row) {
            if ($shown >= self::SHOWNUSERS) {
                break;
            }
            $rulename = $row->rulename ?? taskflow_stringmanager::get_string('bulkcheckdeletedrule', $row->ruleid);
            $items[] = html_writer::tag(
                'li',
                html_writer::tag('span', fullname($row)) . ' '
                . html_writer::tag('span', format_string($rulename), ['class' => 'text-muted small'])
            );
            $shown++;
        }

        $html = html_writer::tag('ul', implode('', $items), ['class' => 'list-unstyled mb-2']);

        if ($parked > $shown) {
            $html .= html_writer::div(
                taskflow_stringmanager::get_string('bulkcheckandxmore', $parked - $shown),
                'text-muted small mb-2'
            );
        }

        $html .= html_writer::div(
            taskflow_stringmanager::get_string('bulkcheckactsonall', $parked),
            'text-muted small mb-2'
        );

        $buttons = [];
        $buttons[] = [
            'label' => taskflow_stringmanager::get_string('bulkcheckreleaseall', $parked),
            'class' => 'btn btn-success btn-sm mr-2',
            'href' => '#',
            'iclass' => 'fa fa-paper-plane',
            'arialabel' => 'release',
            'id' => $messageid . '-' . $this->uniqueid,
            'name' => $this->uniqueid . '-release-' . $messageid,
            'methodname' => 'releasemessage',
            'nomodal' => false,
            'selectionmandatory' => false,
            'data' => [
                'id' => "$messageid",
                'messageid' => $messageid,
                'titlestring' => 'bulkcheckreleasetitle',
                'bodystring' => 'bulkcheckreleasebody',
                'submitbuttonstring' => 'bulkcheckreleasesubmit',
                'component' => 'local_taskflow',
                'labelcolumn' => 'messagename',
            ],
        ];
        $buttons[] = [
            'label' => taskflow_stringmanager::get_string('bulkcheckdismissall', $parked),
            'class' => 'btn btn-danger btn-sm',
            'href' => '#',
            'iclass' => 'fa fa-ban',
            'arialabel' => 'dismiss',
            'id' => $messageid . '-' . $this->uniqueid,
            'name' => $this->uniqueid . '-dismiss-' . $messageid,
            'methodname' => 'dismissmessage',
            'nomodal' => false,
            'selectionmandatory' => false,
            'data' => [
                'id' => "$messageid",
                'messageid' => $messageid,
                'titlestring' => 'bulkcheckdismisstitle',
                'bodystring' => 'bulkcheckdismissbody',
                'submitbuttonstring' => 'bulkcheckdismisssubmit',
                'component' => 'local_taskflow',
                'labelcolumn' => 'messagename',
            ],
        ];
        table::transform_actionbuttons_array($buttons);

        return $html . $OUTPUT->render_from_template(
            'local_wunderbyte_table/component_actionbutton',
            ['showactionbuttons' => $buttons]
        );
    }

    /**
     * Lets the parked sends of a message go out after all.
     *
     * @param int $id
     * @param string $data
     * @return array
     */
    public function action_releasemessage(int $id, string $data): array {
        require_capability('local/taskflow:editmessages', context_system::instance());

        $data = json_decode($data);
        $messageid = (int) ($data->messageid ?? $id);
        $count = bulk_check::release_message($messageid);

        return [
            'success' => 1,
            'message' => taskflow_stringmanager::get_string('bulkcheckreleasequeued', $count),
            // The library skips its own reload while a panel is open, so ask for a real one.
            'reload' => 1,
        ];
    }

    /**
     * Gives up on the parked sends of a message.
     *
     * @param int $id
     * @param string $data
     * @return array
     */
    public function action_dismissmessage(int $id, string $data): array {
        require_capability('local/taskflow:editmessages', context_system::instance());

        $data = json_decode($data);
        $messageid = (int) ($data->messageid ?? $id);
        $count = bulk_check::dismiss_message($messageid);

        return [
            'success' => 1,
            'message' => taskflow_stringmanager::get_string('bulkcheckdismissed', $count),
            'reload' => 1,
        ];
    }
}
