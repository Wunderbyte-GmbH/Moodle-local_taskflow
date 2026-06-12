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
use local_taskflow\event\assignment_seen;
use local_taskflow\local\assignment_process\assignment_preprocessor;
use local_taskflow\output\singleassignment;
use context_system;

require('../../config.php');
require_login();

global $CFG, $PAGE, $OUTPUT, $USER;

$title = \local_taskflow\taskflow_stringmanager::get_string('assignment');

$assignmentid = optional_param('id', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_TEXT);
$returnurl = optional_param('returnurl', '', PARAM_LOCALURL);
$multiblockparam = optional_param('taskflow_multiblock', '', PARAM_ALPHANUMEXT);

if (!empty($returnurl) && !empty($multiblockparam)) {
    $returnurl .= '#' . $multiblockparam;
}

$PAGE->set_context(null);
$PAGE->set_title($title);
$PAGE->set_pagelayout('base');

$urlparams = ['id' => $assignmentid];
if (!empty($returnurl)) {
    $urlparams['returnurl'] = $returnurl;
}
$url = new moodle_url('/local/taskflow/assignment.php', $urlparams);
$PAGE->set_url($url);

echo $OUTPUT->header();

try {
    $assignment = new singleassignment([
        'id' => $assignmentid,
        'returnurl' => $returnurl,
    ]);
    if (
        has_capability('local/taskflow:viewassignment', context_system::instance())
        || $assignment->is_my_assignment()
        || $assignment->i_am_supervisor()
    ) {
        $renderer = $PAGE->get_renderer('local_taskflow');
        echo $renderer->render_singleassignment($assignment);
    } else {
        notification::error(get_string('nopermissions', 'error', ''));
    }

    switch ($action) {
        case 'checkstatus':
            $assignmentdata = $assignment->get_assignmentdata();
            $data = [
                'relateduserid' => $assignment->get_userid(),
                'rulejson' => $assignmentdata->rulejson,
                'other' => ['unitid' => $assignmentdata->unitid],
            ];
            $preprocessor = new assignment_preprocessor($data);
            $preprocessor->set_this_user($data['relateduserid']);
            $preprocessor->set_all_inheritance_unit_rules();
            $preprocessor->process_assignemnts();

            break;
        default:
            // No action.
            break;
    }
    $event = assignment_seen::create([
        'objectid' => $assignmentid,
        'context'  => context_system::instance(),
        'userid'   => $USER->id,
        'other'    => [
            'userid' => $USER->id,
            'assignmentid' => $assignmentid,
        ],
    ]);
    $event->trigger();
} catch (Exception $e) {
    if ($CFG->debug == E_ALL) {
            notification::error($e->getMessage() . $e->getTraceAsString());
    } else {
        notification::warning(\local_taskflow\taskflow_stringmanager::get_string('assignmentnotfound', $assignmentid));
    }
}

echo $OUTPUT->footer();
