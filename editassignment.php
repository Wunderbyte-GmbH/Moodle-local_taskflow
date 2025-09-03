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

use core\notification;
use local_taskflow\local\assignments\assignment;
use local_taskflow\local\supervisor\supervisor;
use context_system;
use local_taskflow\output\editassignment_template_data_factory;

require('../../config.php');
require_login();

global $CFG, $PAGE, $OUTPUT, $USER;

$title = get_string('editassignment', 'local_taskflow');

$assignmentid = optional_param('id', 0, PARAM_INT);

$PAGE->set_context(null);
$PAGE->set_title($title);
$PAGE->set_heading($title);
$PAGE->set_pagelayout('base');

$url = new moodle_url('/local/taskflow/editassignment.php');
$PAGE->set_url($url);

echo $OUTPUT->header();
$assignment = new assignment($assignmentid);
$supervisor = supervisor::get_supervisor_for_user($assignment->userid ?? 0);
$hascapability = has_capability('local/taskflow:viewassignment', context_system::instance());

if (
    !$hascapability &&
    $supervisor->id != $USER->id
) {
    notification::error(get_string('insufficientpermissions', 'local_taskflow'));
} else {
    try {
        $issupervisor = $supervisor->id == $USER->id;
        $admincapability = has_capability('local/taskflow:editassignment', context_system::instance());
        $data = editassignment_template_data_factory::get_data(['id' => $assignmentid], $issupervisor, $admincapability);
        /** @var \local_taskflow\output\renderer $renderer */
        $renderer = $PAGE->get_renderer('local_taskflow');
        echo $renderer->render_editassignment($data);
    } catch (Exception $e) {
        if ($CFG->debug == E_ALL) {
                notification::error($e->getMessage() . $e->getTraceAsString());
        } else {
            notification::warning(get_string('assignmentnotfound', 'local_taskflow', $assignmentid));
        }
    }
}

echo $OUTPUT->footer();
