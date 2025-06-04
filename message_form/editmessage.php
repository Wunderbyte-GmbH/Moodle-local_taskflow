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
require_capability('moodle/site:config', $context);

$PAGE->set_context($context);
$PAGE->set_url('/local/taskflow/message_form/editmessage.php');
$PAGE->set_heading(get_string('taskflowmessages', 'local_taskflow'));
$PAGE->set_title(get_string('taskflowmessages', 'local_taskflow'));

echo $OUTPUT->header();

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
        get_string('messagedeleted', 'local_taskflow'),
        null,
        \core\output\notification::NOTIFY_INFO
    );
}

$messages = $DB->get_records('local_taskflow_messages');

echo $OUTPUT->single_button(
    new moodle_url('/local/taskflow/message_form/editmessage_form.php', ['action' => 'new']),
    get_string('createmessage', 'local_taskflow'),
    'get'
);

if ($messages) {
    echo html_writer::start_tag('table', ['class' => 'generaltable fullwidth']);
    echo html_writer::start_tag('thead');
    echo html_writer::tag(
        'tr',
        html_writer::tag('th', get_string('messagetype', 'local_taskflow')) .
        html_writer::tag('th', get_string('messageheading', 'local_taskflow')) .
        html_writer::tag('th', get_string('messagepriority', 'local_taskflow')) .
        html_writer::tag('th', get_string('messagetags', 'local_taskflow')) .
        html_writer::tag('th', get_string('actions', 'local_taskflow'))
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

        $tags = \core_tag_tag::get_item_tags('local_taskflow', 'messages', $message->id);
        $taglist = implode(', ', array_map(fn($tag) => $tag->rawname, $tags));

        echo html_writer::tag(
            'tr',
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
                    ['onclick' => "return confirm('" . get_string('confirmdeletemessage', 'local_taskflow') . "');"]
                )
            )
        );
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
} else {
    echo $OUTPUT->notification(get_string('nomessagesfound', 'local_taskflow'), 'info');
}

echo $OUTPUT->footer();
