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

namespace local_taskflow\local\wizard\taskflow\preview;

use local_taskflow\local\wizard\taskflow\taskflow_result_link_builder;

/**
 * Side-pane preview of one rendered message (skill local_taskflow.preview_message).
 *
 * Renders the mail card of the concept mockup: template heading, To/CC line, subject and the
 * message body. The body arrives already sanitised from the skill (format_text on the rendered
 * template) and is therefore inserted as HTML; every other value is escaped here.
 *
 * Data shape: ['template' => {id, name, class, priority}, 'assignmentid' => int, 'subject' =>
 * string, 'body_html' => string, 'recipients' => [{userid, fullname, email, role}],
 * 'placeholders_used' => [string], 'sent' => bool].
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class taskflow_message_preview_preview_renderer extends taskflow_preview_renderer_base {
    /** Preview type. */
    public const PREVIEW_TYPE = taskflow_preview_renderer_factory::TYPE_MESSAGE_PREVIEW;

    /**
     * Template context: mail card or empty state.
     *
     * @param array $data
     * @return array|null
     */
    protected function build_context(array $data): ?array {
        $template = is_array($data['template'] ?? null) ? (array)$data['template'] : [];
        $messageid = (int)($template['id'] ?? ($data['messageid'] ?? 0));
        $assignmentid = (int)($data['assignmentid'] ?? 0);
        $subject = trim((string)($data['subject'] ?? ''));
        $bodyhtml = (string)($data['body_html'] ?? '');

        if ($messageid <= 0 && $subject === '' && trim($bodyhtml) === '') {
            return ['isempty' => true, 'emptyhtml' => $this->empty_state_html()];
        }

        $to = [];
        $cc = [];
        foreach ((array)($data['recipients'] ?? []) as $recipient) {
            if (!is_array($recipient)) {
                continue;
            }
            $row = [
                'userid' => (int)($recipient['userid'] ?? 0),
                'label' => $this->recipient_label($recipient),
                'url' => taskflow_result_link_builder::user_url((int)($recipient['userid'] ?? 0)),
            ];
            if ((string)($recipient['role'] ?? 'to') === 'cc') {
                $cc[] = $row;
            } else {
                $to[] = $row;
            }
        }

        $placeholders = [];
        foreach ((array)($data['placeholders_used'] ?? []) as $placeholder) {
            $placeholder = trim((string)$placeholder);
            if ($placeholder !== '') {
                $placeholders[] = ['label' => $this->esc($placeholder)];
            }
        }

        return [
            'isempty' => false,
            'title' => $this->esc($this->str('agent_preview_message_title', (object)[
                'name' => (string)($template['name'] ?? ''),
                'id' => $messageid,
            ])),
            'notice' => $this->esc($this->str('agent_preview_message_nosend')),
            'labels' => [
                'to' => $this->esc($this->str('agent_preview_message_to')),
                'cc' => $this->esc($this->str('agent_preview_message_cc')),
                'subject' => $this->esc($this->str('agent_preview_message_subject')),
                'body' => $this->esc($this->str('agent_preview_message_body')),
                'placeholders' => $this->esc($this->str('agent_preview_message_placeholders')),
                'none' => $this->esc($this->str('agent_preview_none')),
            ],
            'to' => $to,
            'hasto' => !empty($to),
            'cc' => $cc,
            'hascc' => !empty($cc),
            'subject' => $this->esc($subject),
            'bodyhtml' => $bodyhtml,
            'placeholders' => $placeholders,
            'hasplaceholders' => !empty($placeholders),
            'hasassignment' => $assignmentid > 0,
            'assignmentlink' => $this->link_assignment($assignmentid),
            'messagelink' => $this->link(
                taskflow_result_link_builder::edit_message_url($messageid),
                $this->str('agent_preview_open_messages')
            ),
        ];
    }

    /**
     * Message and assignment id of the preview.
     *
     * @param array $data
     * @return array<string,int[]>
     */
    protected function default_payload(array $data): array {
        $template = is_array($data['template'] ?? null) ? (array)$data['template'] : [];
        $messageid = (int)($template['id'] ?? ($data['messageid'] ?? 0));
        $assignmentid = (int)($data['assignmentid'] ?? 0);
        return [
            'messageids' => $messageid > 0 ? [$messageid] : [],
            'assignmentids' => $assignmentid > 0 ? [$assignmentid] : [],
        ];
    }

    /**
     * Escaped "Full Name <mail@example.org>" label of a recipient row.
     *
     * @param array $recipient
     * @return string
     */
    private function recipient_label(array $recipient): string {
        $fullname = trim((string)($recipient['fullname'] ?? ''));
        $email = trim((string)($recipient['email'] ?? ''));
        if ($email === '') {
            return $this->name($fullname);
        }
        return $this->name($fullname) . ' ' . $this->esc('<' . $email . '>');
    }
}
