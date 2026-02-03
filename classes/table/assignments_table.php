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
 * Rules table.
 *
 * @package     local_taskflow
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\table;
use context_system;
use core_user;
use html_writer;
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\assignments\assignments_facade;
use local_taskflow\local\external_adapter\external_api_base;
use local_taskflow\local\supervisor\supervisor;
use local_taskflow\plugininfo\taskflowadapter;
use local_wunderbyte_table\wunderbyte_table;
use local_wunderbyte_table\output\table;
use moodle_url;

/**
 * Assignments table
 *
 * @package     local_taskflow
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignments_table extends wunderbyte_table {
    /**
     * Add column with actions.
     * @param mixed $values
     * @return string
     */
    public function col_actions($values) {
        global $OUTPUT, $USER, $PAGE;

        $url = new moodle_url('/local/taskflow/assignment.php', [
            'id' => $values->id,
        ]);

        $html = html_writer::div(html_writer::link(
            $url->out(),
            '<i class="icon fa fa-info-circle"></i>'
        ));
        $data = [];
        $supervisor = supervisor::get_supervisor_for_user($values->userid ?? 0);
        $hascapability = has_capability('local/taskflow:editassignment', context_system::instance());
        if (
            $hascapability ||
            ($supervisor->id ?? -1) === $USER->id
        ) {
            $returnurl = $PAGE->url;
            $returnurlout = $returnurl->out(false);
            $url = new moodle_url('/local/taskflow/editassignment.php', [
                'id' => $values->id,
                'returnurl' => $returnurlout,
                'taskflow_multiblock' => 'taskflow_multiblock',
            ]);

            $html .= html_writer::div(html_writer::link(
                $url,
                "<i class='icon fa fa-edit'></i>"
            ));
            table::transform_actionbuttons_array($data);
        }
        return
            $html .
            $OUTPUT->render_from_template('local_wunderbyte_table/component_actionbutton', ['showactionbuttons' => $data]);
    }

    /**
     * Description.
     * @param mixed $values
     * @return string
     */
    public function col_targets($values) {
        $jsonobject = json_decode($values->targets) ?? [];
        $html = '';
        $stringmanager = get_string_manager();
        foreach ($jsonobject as $item) {
            if ($stringmanager->string_exists($item->targettype, 'local_taskflow')) {
                $type = get_string($item->targettype, 'local_taskflow');
            } else {
                $type = $item->targettype;
            }
            $completionstatus = get_string('notcompleted', 'local_taskflow');
            if (
                isset($item->completionstatus) &&
                $item->completionstatus == 1
            ) {
                $completionstatus = get_string('completed', 'local_taskflow');
            }
            $html .= "<b>$type:</b> $item->targetname ( $completionstatus)</br>";
        }
        return html_writer::div($html);
    }
    /**
     * Shows the latest comment.
     *
     * @param mixed $values
     *
     * @return string
     *
     */
    public function col_comment($values) {
        $jsonstring = !empty($values->data) ? $values->data : '[]';
        $jsonobject = json_decode($jsonstring) ?? [];
        if (!isset($jsonobject->data->comment)) {
            $comment = "-";
        } else {
            $comment = $jsonobject->data->comment;
        }
        $shortcomment = shorten_text($comment, 50);
        return html_writer::div($shortcomment, '', ['title' => $comment]);
    }
    /**
     * Timecreated.
     *
     * @param mixed $values
     *
     * @return string
     *
     */
    public function col_timecreated($values) {
        $readabletime = userdate($values->timecreated, '%d.%m.%Y %H:%M');
        return html_writer::div($readabletime);
    }
    /**
     * Timemodified.
     *
     * @param mixed $values
     *
     * @return string
     *
     */
    public function col_timemodified($values) {
        $readabletime = userdate($values->timemodified, '%d.%m.%Y %H:%M');
        return html_writer::div($readabletime);
    }

    /**
     * Status Label
     * @param mixed $values
     * @return string
     */
    public function col_statussortkey($values): string {
        $statuscounter = explode('_', $values->statussortkey);
        $columnvalue = assignment_status_facade::get_specific_names($statuscounter[0]);
        if (assignment_status_facade::get_status_identifier('prolonged') == $statuscounter[0]) {
            $columnvalue .= ' (' . $statuscounter[1] . ')';
        } else if (assignment_status_facade::get_status_identifier('overdue') == $statuscounter[0]) {
            $columnvalue .= ' (' . $statuscounter[1] . ')';
        }
        return $columnvalue;
    }

    /**
     * Return parsed comments for table.
     * @param string $lastinternalcomment
     * @return array
     */
    private function get_parsed_comments($lastinternalcomment): array {
        $parsed = [];
        $comments = explode('___', $lastinternalcomment);
        foreach ($comments as $comment) {
            [$userid, $sender, $timestamp, $message] = array_pad(explode('|', $comment, 4), 4, null);
            $userid = trim((string)$userid);
            $sender = trim((string)$sender);
            $message = trim((string)$message);
            $timestamp = trim((string)$timestamp);

            if ($message === '' || !is_numeric($timestamp)) {
                continue;
            }

            $parsed[] = [
                'date' => date('d.m.Y H:i:s', (int)$timestamp),
                'sender' => $sender,
                'senderid' => $userid,
                'message' => $message,
            ];
        }
        return $parsed;
    }

    /**
     * Build comments preview.
     * @param array $first
     * @return string
     */
    private function get_comments_preview($first): string {
        $maxpreviewlength = get_config('local_taskflow', 'internalcommunicationpreviewlength') ?? 100;
        $short = mb_strlen($first['message']) > $maxpreviewlength
            ? mb_substr($first['message'], 0, $maxpreviewlength) . '…'
            : $first['message'];

        $content = s(
            $first['date'] . ' - ' .
            $first['sender'] . ': ' .
            $short
        );
        return html_writer::span(
            $content,
            'last-comment-preview'
        );
    }

    /**
     * Build comments modal.
     * @param array $parsed
     * @param string $modalid
     * @return string
     */
    private function get_comment_modal($parsed, $modalid): string {
        $modalbody = '';
        foreach ($parsed as $entry) {
            $content = s(
                ' - ' .
                $entry['sender'] . ': ' .
                $entry['message']
            );
            $modalbody .= html_writer::tag(
                'div',
                html_writer::tag('strong', s($entry['date'])) . $content,
                ['class' => 'mb-2']
            );
        }
        $closex = html_writer::tag(
            'button',
            html_writer::span('&times;', '', ['aria-hidden' => 'true']),
            [
                'type' => 'button',
                'class' => 'close',
                'data-dismiss' => 'modal',
                'aria-label' => 'close',
            ]
        );

        $closebtn = html_writer::tag(
            'button',
            'close',
            [
                'type' => 'button',
                'class' => 'btn btn-secondary',
                'data-dismiss' => 'modal',
            ]
        );

        return html_writer::tag(
            'div',
            html_writer::tag(
                'div',
                html_writer::tag(
                    'div',
                    html_writer::tag(
                        'div',
                        html_writer::tag(
                            'h5',
                            'comments',
                            ['class' => 'modal-title']
                        ) . $closex,
                        ['class' => 'modal-header']
                    ) .
                    html_writer::tag('div', $modalbody, ['class' => 'modal-body']) .
                    html_writer::tag('div', $closebtn, ['class' => 'modal-footer']),
                    ['class' => 'modal-content']
                ),
                ['class' => 'modal-dialog modal-lg']
            ),
            [
                'class' => 'modal fade',
                'id' => $modalid,
                'tabindex' => '-1',
                'role' => 'dialog',
                'aria-hidden' => 'true',
            ]
        );
    }


    /**
     * Status Label
     * @param mixed $values
     * @return string
     */
    public function col_lastinternalcomment($values): string {
        global $USER;
        if (empty($values->lastinternalcomment)) {
            return get_string('nocomments', 'local_taskflow');
        }

        $parsed = $this->get_parsed_comments($values->lastinternalcomment);
        if (empty($parsed)) {
            return get_string('nocomments', 'local_taskflow');
        }

        $preview = $this->get_comments_preview($parsed[0]);

        $modalid = 'lastcomment-modal-' . (int)$values->id;

        $eye = html_writer::link(
            '#',
            html_writer::tag('i', '', ['class' => 'icon fa fa-eye']),
            [
                'data-toggle' => 'modal',
                'data-target' => '#' . $modalid,
                'class' => 'ml-2 text-decoration-none',
                'title' => get_string('view'),
                'aria-label' => get_string('view'),
            ]
        );

        $modal = $this->get_comment_modal($parsed, $modalid);

        $notificationicon = '';
        if ($values->usersseen != null) {
            if ($parsed[0]['senderid'] != (string)$USER->id) {
                $lastseentimes = $this->parse_usersseen($values->usersseen);
                if (
                    !isset($lastseentimes[$USER->id]) ||
                    $lastseentimes[$USER->id] < strtotime($parsed[0]['date'])
                ) {
                    $notificationicon = html_writer::tag('i', '', ['class' => 'icon fa fa-bell']);
                }
            }
        }
        return $notificationicon . $preview . $eye . $modal;
    }

    /**
     * Parse usersseen string
     * @param string $usersseen
     * @return array
     */
    private function parse_usersseen(?string $usersseen): array {
        if (empty($usersseen)) {
            return [];
        }

        $map = [];
        foreach (explode(',', $usersseen) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }

            $parts = explode('|', $entry, 2);
            if (count($parts) !== 2) {
                continue;
            }

            [$userid, $lastseen] = $parts;

            $userid = (int)trim($userid);
            $lastseen = (int)trim($lastseen);

            if ($userid > 0 && $lastseen > 0) {
                $map[$userid] = $lastseen;
            }
        }

        return $map;
    }

    /**
     * Rule Link
     * @param mixed $values
     * @return string
     */
    public function col_rulename($values): string {
        $url = new moodle_url('/local/taskflow/assignment.php', [
            'id' => $values->id,
        ]);
        return html_writer::link($url, $values->rulename, ['class' => 'assignment-rulename']);
    }

    /**
     * All other columns are here.
     *
     * @param mixed $column
     * @param mixed $values
     *
     * @return string
     *
     */
    public function other_cols($column, $values): string {

        $supervisorfield = external_api_base::return_shortname_for_functionname(
            taskflowadapter::TRANSLATOR_USER_SUPERVISOR
        );

        try {
            switch ($column) {
                // Cast userid to name of user.
                case "custom_$supervisorfield":
                    $user = core_user::get_user($values->$column);
                    if ($user) {
                        return core_user::get_fullname($user) ?? '';
                    }
                    return '';
                default:
                    return $values->$column ?? '';
            }
        } catch (\Throwable $e) {
            // If there is an error, we return an empty string.
            return $values->$column ?? '';
        }
    }

    /**
     * Toggle active state of assignement to active - unactive.
     *
     * @param int $id
     * @param string $data
     *
     * @return array
     *
     */
    public function action_toggleassigmentactive(int $id, string $data) {
        $state = assignments_facade::toggle_assignment_active($id);
        $dataobject = json_decode($data);
        $uncheckedmessage = get_string('assignmentuncheckedmess', 'local_taskflow', $dataobject);
        $checkedmessage = get_string('assignmentcheckedmess', 'local_taskflow', $dataobject);
        return [
           'success' => 1,
           'message' => $state > 0 ? $checkedmessage : $uncheckedmessage,
        ];
    }
    /**
     * Returns just the info button
     *
     * @param mixed $values
     *
     * @return string
     *
     */
    public function col_info($values) {
                global $OUTPUT, $USER, $PAGE;

        $url = new moodle_url('/local/taskflow/assignment.php', [
            'id' => $values->id,
        ]);

        $html = html_writer::div(html_writer::link(
            $url->out(),
            '<i class="icon fa fa-info-circle"></i>'
        ));
        $data = [];
            table::transform_actionbuttons_array($data);
        return
            $html .
            $OUTPUT->render_from_template('local_wunderbyte_table/component_actionbutton', ['showactionbuttons' => $data]);
    }

    /**
     * Transforms unixtimestamp to readable date for duedate.
     *
     * @param mixed $values
     * @return string
     */
    public function col_duedate($values) {
        $readabletime = userdate($values->duedate, '%d.%m.%Y %H:%M');
        return html_writer::div($readabletime);
    }
}
