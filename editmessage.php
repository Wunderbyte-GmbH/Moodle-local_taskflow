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

use local_taskflow\multistepform\editmessagesmanager;

require_once(__DIR__ . '/../../config.php');
require_login();

$context = context_system::instance();

// Make sure only an admin can see this.
if (!has_capability('moodle/site:config', $context)) {
    die;
}

$PAGE->set_context($context);
$PAGE->set_url('/local/taskflow/editmessage.php');

// There might be a returnurl passed on. If not, we use this one.
$returnurl = optional_param('returnurl', '', PARAM_URL);
if (empty($returnurl)) {
    $returnurl = "$CFG->wwwroot/local/taskflow/editmessage.php";
}

// The id corresponds to a rule we want to edit.
$id = optional_param('id', 0, PARAM_INT);

echo $OUTPUT->header();


$form = new editmessagesmanager();
if ($form->is_cancelled()) {
    redirect($returnurl);
} else if ($data = $form->get_data()) {
    // Save to DB, serialize body if needed.
    $record = new stdClass();
    $record->id = $data->id ?? 0;
    $record->class = $data->type;
    $record->message = json_encode([
        'heading' => $data->heading,
        'body' => $data->body,
    ]);
    $record->usermodified = $USER->id;
    $record->priority = $data->priority;
    $record->timemodified = time();
    if (empty($record->id)) {
        $record->timecreated = time();
        $record->id = $DB->insert_record('local_taskflow_messages', $record);
    } else {
        $DB->update_record('local_taskflow_messages', $record);
    }
    \core_tag_tag::set_item_tags(
        'local_taskflow',
        'messages',
        $record->id,
        \context_system::instance(),
        $data->tags
    );

    $tags = \core_tag_tag::get_item_tags('local_taskflow', 'messages', $record->id);
    foreach ($tags as $tag) {
        if (!$tag->record->isstandard) {
            $tagrecord = new stdClass();
            $tagrecord->id = $tag->id;
            $tagrecord->isstandard = 1;
            $DB->update_record('tag', $tagrecord);
        }
    }
    redirect($returnurl, get_string('messagesaved', 'local_taskflow'));
} else {
    $form->display();
}

echo $OUTPUT->footer();
