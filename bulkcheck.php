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
 * Sends or gives up on messages that the bulk send checker parked.
 *
 * @package local_taskflow
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_taskflow\output\bulkcheck;
use local_taskflow\taskflow_stringmanager;

require_login();

$context = context_system::instance();
require_capability('local/taskflow:editmessages', $context);

$messageid = optional_param('messageid', 0, PARAM_INT);

$PAGE->set_context($context);
$PAGE->set_url('/local/taskflow/bulkcheck.php', empty($messageid) ? [] : ['messageid' => $messageid]);
$PAGE->set_heading(taskflow_stringmanager::get_string('bulkcheckparked'));
$PAGE->set_title(taskflow_stringmanager::get_string('bulkcheckparked'));

// Deliberately no check on bulkcheckenabled: switching the feature off must never hide
// messages that are already parked, or they would be abandoned with no way to reach them.

$renderer = $PAGE->get_renderer('local_taskflow');

echo $OUTPUT->header();
echo $OUTPUT->heading(taskflow_stringmanager::get_string('bulkcheckparked'));
echo html_writer::div(taskflow_stringmanager::get_string('bulkcheckparkedintro'), 'mb-3');

if (!empty($messageid)) {
    // Scoping the list also scopes the two buttons that act on everything, so it has to be
    // said plainly which message they are about.
    $name = $DB->get_field('local_taskflow_messages', 'name', ['id' => $messageid]);
    if (empty($name)) {
        $name = taskflow_stringmanager::get_string('bulkcheckdeletedmessage', $messageid);
    }
    echo html_writer::div(
        taskflow_stringmanager::get_string('bulkcheckscopedintro', format_string($name)) . ' ' .
        html_writer::link(
            new moodle_url('/local/taskflow/bulkcheck.php'),
            taskflow_stringmanager::get_string('bulkcheckshowallmessages')
        ),
        'alert alert-info'
    );
}

echo $renderer->render(new bulkcheck($messageid));
echo $OUTPUT->footer();
