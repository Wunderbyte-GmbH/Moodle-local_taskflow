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
 * Plugin version and other meta-data are defined here.
 *
 * @package     local_taskflow
 * @copyright   2025 Wunderbyte Gmbh <info@wunderbyte.at>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_taskflow\output\userassignment;

require('../../config.php');
require_login();
$title = get_string('modulename', 'local_taskflow');

$assignmentid = optional_param('id', 0, PARAM_INT);

$action = optional_param('action', '', PARAM_TEXT);

// This is the possibility to delete all data on a test system.
// It's only possible for admins AND in debug mode.
if (
    $CFG->debug > 0
    && has_capability('moodle/site:config', context_system::instance())
) {
    switch ($action) {
        case 'deleteall':
            $DB->delete_records('local_taskflow_assignment');
            $DB->delete_records('local_taskflow_history');
            $DB->delete_records('local_taskflow_unit_members');
            $DB->delete_records('local_taskflow_sent_messages');
            $DB->delete_records('local_taskflow_assignment_competency');
            $DB->delete_records('cohort_members');
            $DB->delete_records('local_taskflow_unit_rel');

            cache_helper::purge_by_event('changesinassignmentslist');
            cache_helper::purge_by_event('changesinhistorylist');
            break;
        case 'deleteallrules':
            $DB->delete_records('local_taskflow_rules');
            cache_helper::purge_by_event('changesinruleslist');
            break;
    }
}

$PAGE->set_context(null);
$PAGE->set_title($title);
$PAGE->set_heading($title);
$PAGE->set_pagelayout('base');

$url = new moodle_url('/local/taskflow/view.php');
$PAGE->set_url($url);

echo $OUTPUT->header();

$PAGE->requires->js_call_amd('local_taskflow/uploadusers', 'init');

echo html_writer::tag(
    'button',
    get_string('uploadusersmodal', 'local_taskflow'),
    [
        'type' => 'button',
        'id' => 'openuploadusersmodal',
        'class' => 'btn btn-primary',
    ]
);

$data = new userassignment(['id' => $assignmentid]);
$renderer = $PAGE->get_renderer('local_taskflow');
echo $renderer->render_userassignment($data);

echo $OUTPUT->footer();
