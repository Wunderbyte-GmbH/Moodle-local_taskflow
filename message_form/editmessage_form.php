<?php

use local_taskflow\local\messages_form\message_form_entity;
use local_taskflow\local\messages_form\message_tag_form_entity;
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
require_once($CFG->libdir . '/adminlib.php');
use local_taskflow\local\messages_form\editmessagesmanager;

require_login();

$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_context($context);
$PAGE->set_url('/local/taskflow/message_form/editmessage_form.php');
$PAGE->set_title(get_string('editmessage', 'local_taskflow'));

// Optional param to determine if this is an edit or create.
$id = optional_param('id', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

// Return to list page.
$returnurl = new moodle_url('/local/taskflow/message_form/editmessage.php');

// Instantiate the form.
$form = new editmessagesmanager();
$messageformentity = new message_form_entity();
$messagetagentity = new message_tag_form_entity();

// Handle cancel.
if ($form->is_cancelled()) {
    redirect($returnurl);
}

// Handle save.
if ($data = $form->get_data()) {
    $recordid = $messageformentity->prepare_message_from_form($data);
    $messagetagentity->save_message_tags($recordid, $data->tags);
    redirect($returnurl, get_string('messagesaved', 'local_taskflow'), null, \core\output\notification::NOTIFY_SUCCESS);
}

// Load data for editing.
if ($id) {
    $data = $messageformentity->prepare_record_for_form($id);
    if ($data) {
        $form->set_data($data);
    }
}

// Display form.
echo $OUTPUT->header();
echo $OUTPUT->heading($id ? get_string('editmessage', 'local_taskflow') : get_string('createmessage', 'local_taskflow'));
$form->display();
echo $OUTPUT->footer();
