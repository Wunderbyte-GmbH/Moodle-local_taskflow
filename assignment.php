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
use local_taskflow\output\singleassignment;
use context_system;

require('../../config.php');
require_login();

global $CFG, $PAGE, $OUTPUT, $USER;

$title = get_string('assignment', 'local_taskflow');

$assignmentid = optional_param('id', 0, PARAM_INT);

$PAGE->set_context(null);
$PAGE->set_title($title);
$PAGE->set_pagelayout('base');

$url = new moodle_url('/local/taskflow/assignment.php', ['id' => $assignmentid]);
$PAGE->set_url($url);

echo $OUTPUT->header();

try {
    $data = new singleassignment(['id' => $assignmentid]);
    if (
        has_capability('local/taskflow:viewassignment', context_system::instance())
        || $data->is_my_assignment()
        || $data->i_am_supervisor()
    ) {
        $renderer = $PAGE->get_renderer('local_taskflow');
        echo $renderer->render_singleassignment($data);
    } else {
        notification::error(get_string('nopermissions', 'error', ''));
    }
} catch (Exception $e) {
    if ($CFG->debug == E_ALL) {
            notification::error($e->getMessage() . $e->getTraceAsString());
    } else {
        notification::warning(get_string('assignmentnotfound', 'local_taskflow', $assignmentid));
    }
}

echo $OUTPUT->footer();
