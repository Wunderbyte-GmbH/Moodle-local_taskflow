<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Demofile to see how wunderbyte_table works.
 *
 * @package     local_taskflow
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


require_once(__DIR__ . '/../../../config.php');
require_login();

$context = context_system::instance();
require_capability('local/taskflow:editmessages', $context);

$PAGE->set_context($context);
$PAGE->set_url('/local/taskflow/message_form/editmessage.php');
$PAGE->set_heading(\local_taskflow\taskflow_stringmanager::get_string('taskflowmessages'));
$PAGE->set_title(\local_taskflow\taskflow_stringmanager::get_string('taskflowmessages'));

// Handle deletion.
$deleteid = optional_param('delete', 0, PARAM_INT);
if ($deleteid) {
    require_sesskey();
    $DB->delete_records('tag_instance', [
        'component' => 'local_taskflow',
        'itemtype' => 'messages',
        'itemid' => $deleteid,
    ]);
    $DB->delete_records('local_taskflow_messages', ['id' => $deleteid]);
    redirect(
        new moodle_url('/local/taskflow/message_form/editmessage.php'),
        \local_taskflow\taskflow_stringmanager::get_string('messagedeleted'),
        null,
        \core\output\notification::NOTIFY_INFO
    );
}

echo $OUTPUT->header();

$messages = $DB->get_records('local_taskflow_messages');

echo $OUTPUT->single_button(
    new moodle_url('/local/taskflow/message_form/editmessage_form.php', ['action' => 'new']),
    \local_taskflow\taskflow_stringmanager::get_string('createmessage'),
    'get'
);

if ($messages) {
    echo html_writer::start_tag('table', ['class' => 'generaltable fullwidth']);
    echo html_writer::start_tag('thead');
    echo html_writer::tag(
        'tr',
        html_writer::tag('th', \local_taskflow\taskflow_stringmanager::get_string('messagename')) .
        html_writer::tag('th', \local_taskflow\taskflow_stringmanager::get_string('messagetype')) .
        html_writer::tag('th', \local_taskflow\taskflow_stringmanager::get_string('messageheading')) .
        html_writer::tag('th', \local_taskflow\taskflow_stringmanager::get_string('messagepriority')) .
        html_writer::tag('th', \local_taskflow\taskflow_stringmanager::get_string('messagetags')) .
        html_writer::tag('th', \local_taskflow\taskflow_stringmanager::get_string('actions'))
    );
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    foreach ($messages as $message) {
        $messagecontent = json_decode($message->message ?? '{}');
        $editurl = new moodle_url('/local/taskflow/message_form/editmessage_form.php', ['id' => $message->id]);
        $deleteurl = new moodle_url(
            '/local/taskflow/message_form/editmessage.php',
            ['delete' => $message->id, 'sesskey' => sesskey()]
        );

        $tags = \core_tag_tag::get_item_tags('local_taskflow', 'local_taskflow_messages', $message->id);
        $taglist = implode(', ', array_map(fn($tag) => $tag->rawname, $tags));

        echo html_writer::tag(
            'tr',
            html_writer::tag('td', $message->name) .
            html_writer::tag('td', $message->class) .
            html_writer::tag('td', $messagecontent->heading ?? '-') .
            html_writer::tag('td', $message->priority) .
            html_writer::tag('td', $taglist) .
            html_writer::tag(
                'td',
                html_writer::link($editurl, get_string('edit')) . ' | ' .
                html_writer::link(
                    $deleteurl,
                    get_string('delete'),
                    ['onclick' => "return confirm('" . \local_taskflow\taskflow_stringmanager::get_string('confirmdeletemessage') . "');"]
                )
            )
        );
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
} else {
    echo $OUTPUT->notification(\local_taskflow\taskflow_stringmanager::get_string('nomessagesfound'), 'info');
}

echo $OUTPUT->footer();
