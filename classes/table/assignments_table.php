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
use local_taskflow\local\rules\rules;
use local_taskflow\local\assignments\assignment_manual_update_service;
use stdClass;
use context_system;
use core_user;
use html_writer;
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\assignments\assignments_facade;
use local_taskflow\local\external_adapter\external_api_base;
use local_taskflow\local\supervisor\supervisor;
use local_taskflow\output\last_seen;
use local_taskflow\plugininfo\taskflowadapter;
use local_wunderbyte_table\wunderbyte_table;
use local_wunderbyte_table\output\table;
use moodle_url;
use local_taskflow\taskflow_stringmanager;

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
     * Store the return URL to be used in col_actions
     * @var string
     */
    public $returnurl = '';

    /**
     * Wrap the status in a span with a status class, so a page can style it as a badge.
     * @var bool
     */
    public $statusasbadge = false;

    /**
     * Set the return URL for this table
     * @param string $url The URL to return to
     */
    public function set_return_url(string $url): void {
        $this->returnurl = $url;
    }

    /**
     * Add column with actions.
     * @param mixed $values
     * @return string
     */
    public function col_actions($values) {
        global $OUTPUT, $USER, $PAGE;
        if ($this->is_downloading()) {
                return '';
        }
        $returnurl = $PAGE->url;
        $returnurlout = $returnurl->out(false);
        // Fallback if the returnurl is an AJAX URL, then we set it to the dashboard URL.
        if (strpos($returnurlout, '/lib/ajax/service.php') !== false) {
            $returnurlout = (new moodle_url('/local/taskflow/index.php'))->out(false);
        }

        $url = new moodle_url('/local/taskflow/assignment.php', [
            'id' => $values->id,
            'returnurl' => $returnurlout,
            'taskflow_multiblock' => 'taskflow_multiblock',
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
                $type = taskflow_stringmanager::get_string($item->targettype);
            } else {
                $type = $item->targettype;
            }
            $completionstatus = taskflow_stringmanager::get_string('notcompleted');
            if (
                isset($item->completionstatus) &&
                $item->completionstatus == 1
            ) {
                $completionstatus = taskflow_stringmanager::get_string('completed');
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
        if ($this->is_downloading()) {
                return '';
        }
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
        if ($this->is_downloading() || empty($this->statusasbadge)) {
            return $columnvalue;
        }
        // The class carries the status id, so pages can style it as a badge; without styles it stays plain text.
        $statusid = (int)$statuscounter[0];
        $statusclass = 'local-taskflow-status-' . ($statusid < 0 ? 'm' . abs($statusid) : $statusid);
        return html_writer::span($columnvalue, 'local-taskflow-status ' . $statusclass);
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
                'timestamp' => (int)$timestamp,
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
        if ($this->is_downloading()) {
            return '';
        }
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
                'data-bs-dismiss' => 'modal',
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
                'data-bs-dismiss' => 'modal',
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
                            taskflow_stringmanager::get_string('internalcommunication'),
                            ['class' => 'modal-title']
                        ) . $closex,
                        ['class' => 'modal-header']
                    ) .
                    html_writer::tag(
                        'div',
                        $modalbody,
                        ['class' => 'modal-body', 'style' => 'max-height: 60vh; overflow-y: auto;']
                    ) .
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
        global $USER, $DB;
        if (empty($values->lastinternalcommentblob)) {
            return taskflow_stringmanager::get_string('nocomments');
        }

        $parsed = $this->get_parsed_comments($values->lastinternalcommentblob);
        if (empty($parsed)) {
            return taskflow_stringmanager::get_string('nocomments');
        }

        $preview = $this->get_comments_preview($parsed[0]);

        if ($this->is_downloading()) {
            return strip_tags($preview);
        }

        static $modalcounter = 0;
        $modalid = 'lastcomment-modal-' . (int)$values->id . '-' . (++$modalcounter);

        $eye = html_writer::link(
            '#',
            html_writer::tag('i', '', ['class' => 'icon fa fa-eye']),
            [
                'data-toggle' => 'modal',
                'data-bs-toggle' => 'modal',
                'data-target' => '#' . $modalid,
                'data-bs-target' => '#' . $modalid,
                'class' => 'ml-2 text-decoration-none',
                'title' => get_string('view'),
                'aria-label' => get_string('view'),
            ]
        );

        $modal = $this->get_comment_modal($parsed, $modalid);

        $notificationicon = '';
        $hasunread = $DB->record_exists_sql(
            "
            SELECT 1
            FROM {local_taskflow_int_com} ic
            LEFT JOIN {local_taskflow_last_seen} ls
                ON ls.assignmentid = ic.assignmentid
                AND ls.userid = :assignmentuserid
            WHERE ic.assignmentid = :assignmentid
            AND ic.usermodified <> :userid
            AND (
                    ls.lastseen IS NULL
                OR ic.timecreated > ls.lastseen
            )
            ",
            [
                'userid'       => $USER->id,
                'assignmentuserid'       => $USER->id,
                'assignmentid' => $values->id,
            ]
        );
        if (
            $parsed[0]['senderid'] != (string)$USER->id &&
            $hasunread
        ) {
            $notificationicon = html_writer::tag('i', '', [
                'class' => 'icon fa fa-bell text-warning',
                'title' => taskflow_stringmanager::get_string('newinternalmessages'),
                'aria-label' => taskflow_stringmanager::get_string('newinternalmessages'),
                'data-toggle' => 'tooltip',
                'data-placement' => 'top',
            ]);
        }
        return $notificationicon . $preview . $eye . $modal;
    }

    /**
     * Rule Link
     * @param mixed $values
     * @return string
     */
    public function col_rulename($values): string {
        global $PAGE;
        $returnurl = $PAGE->url;
        $returnurlout = $returnurl->out(false);
        // Fallback if the returnurl is an AJAX URL, then we set it to the dashboard URL.
        if (strpos($returnurlout, '/lib/ajax/service.php') !== false) {
            $returnurlout = (new moodle_url('/local/taskflow/index.php'))->out(false);
        }
        $url = new moodle_url('/local/taskflow/assignment.php', [
            'id' => $values->id,
            'returnurl' => $returnurlout,
            'taskflow_multiblock' => 'taskflow_multiblock',
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
        if ($this->is_downloading()) {
            return [];
        }
        $state = assignments_facade::toggle_assignment_active($id);
        $dataobject = json_decode($data);
        $uncheckedmessage = taskflow_stringmanager::get_string('assignmentuncheckedmess', $dataobject);
        $checkedmessage = taskflow_stringmanager::get_string('assignmentcheckedmess', $dataobject);
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
        if ($this->is_downloading()) {
                return '';
        }
        $returnurl = $PAGE->url;
        $returnurlout = $returnurl->out(false);
        // Fallback if the returnurl is an AJAX URL, then we set it to the dashboard URL.
        if (strpos($returnurlout, '/lib/ajax/service.php') !== false) {
            $returnurlout = (new moodle_url('/local/taskflow/index.php'))->out(false);
        }
        $url = new moodle_url('/local/taskflow/assignment.php', [
            'id' => $values->id,
            'returnurl' => $returnurlout,
            'taskflow_multiblock' => 'taskflow_multiblock',
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

    /**
     * Whether the current user may change an assignment: the same rule as the edit icon of the actions column.
     *
     * @param stdClass $assignment
     * @return bool
     */
    public static function may_change_assignment(stdClass $assignment): bool {
        global $USER;
        if (has_capability('local/taskflow:editassignment', context_system::instance())) {
            return true;
        }
        $supervisor = supervisor::get_supervisor_for_user((int)$assignment->userid);
        return (int)($supervisor->id ?? -1) === (int)$USER->id;
    }

    /**
     * The assignments selected with the checkboxes that the current user may change.
     *
     * @param string $data json of the action button, carrying checkedids
     * @return array [stdClass[] allowed assignments, int number of skipped rows]
     */
    private function selected_assignments(string $data): array {
        global $DB;
        $payload = json_decode($data);
        $ids = array_filter(array_map('intval', (array)($payload->checkedids ?? [])));
        if (empty($ids)) {
            return [[], 0];
        }
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'a');
        $allowed = [];
        foreach ($DB->get_records_select('local_taskflow_assignment', "id $insql", $params) as $assignment) {
            if (self::may_change_assignment($assignment)) {
                $allowed[] = $assignment;
            }
        }
        // Rows without permission and ids that no longer exist count as skipped.
        return [$allowed, count($ids) - count($allowed)];
    }

    /**
     * Result array of a bulk action.
     *
     * @param string $stringkey
     * @param int $done
     * @param int $skipped
     * @return array
     */
    private static function bulk_result(string $stringkey, int $done, int $skipped): array {
        return [
            'success' => $done > 0 ? 1 : 0,
            'message' => taskflow_stringmanager::get_string($stringkey, (object)['done' => $done, 'skipped' => $skipped]),
        ];
    }

    /**
     * Bulk: extends the due date of the selected assignments by the extension period of their rule.
     *
     * @param int $id
     * @param string $data
     * @return array
     */
    public function action_extendduedate(int $id, string $data): array {
        global $USER;
        [$assignments, $skipped] = $this->selected_assignments($data);
        $service = new assignment_manual_update_service();
        $done = 0;
        foreach ($assignments as $assignment) {
            $rule = rules::instance((int)$assignment->ruleid);
            $rulejson = $rule ? json_decode((string)$rule->get_rulesjson()) : null;
            $period = (int)($rulejson->rulejson->rule->extensionperiod ?? 0);
            if ($period <= 0) {
                $skipped++;
                continue;
            }
            $base = max((int)$assignment->duedate, time());
            $service->apply((int)$assignment->id, ['duedate' => $base + $period], (int)$USER->id);
            $done++;
        }
        return self::bulk_result('bulk_extendduedate_done', $done, $skipped);
    }

    /**
     * Bulk: pauses the selected assignments.
     *
     * @param int $id
     * @param string $data
     * @return array
     */
    public function action_pauseassignments(int $id, string $data): array {
        return $this->bulk_set_status($data, 'paused', 'bulk_pauseassignments_done');
    }

    /**
     * Bulk: sets the selected assignments to "not relevant".
     *
     * @param int $id
     * @param string $data
     * @return array
     */
    public function action_setnotrelevant(int $id, string $data): array {
        return $this->bulk_set_status($data, 'notrelevant', 'bulk_setnotrelevant_done');
    }

    /**
     * Applies a status to the selected assignments through the manual update service.
     *
     * @param string $data
     * @param string $statuslabel
     * @param string $stringkey
     * @return array
     */
    private function bulk_set_status(string $data, string $statuslabel, string $stringkey): array {
        global $USER;
        [$assignments, $skipped] = $this->selected_assignments($data);
        $status = assignment_status_facade::get_status_identifier($statuslabel);
        $service = new assignment_manual_update_service();
        $done = 0;
        foreach ($assignments as $assignment) {
            $service->apply(
                (int)$assignment->id,
                ['status' => $status, 'runstatustransition' => true, 'respectexcluded' => true],
                (int)$USER->id
            );
            $done++;
        }
        return self::bulk_result($stringkey, $done, $skipped);
    }
}
