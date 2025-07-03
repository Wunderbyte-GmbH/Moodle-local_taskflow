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

use local_taskflow\multistepform\editrulesmanager;

require_once(__DIR__ . '/../../config.php');
require_login();

$context = context_system::instance();

// Make sure only an admin can see this.
if (!has_capability('local/taskflow:createrules', $context)) {
    die;
}

$PAGE->set_context($context);
$PAGE->set_url('/local/taskflow/editrule.php');

// There might be a returnurl passed on. If not, we use this one.
$returnurl = optional_param('returnurl', '', PARAM_URL);
if (empty($returnurl)) {
    $returnurl = "$CFG->wwwroot";
}

// The id corresponds to a rule we want to edit.
$id = optional_param('id', 0, PARAM_INT);

echo $OUTPUT->header();

$stepcounter = 1;
$data = [
    $stepcounter => [
        'recordid' => $id,
        'label' => get_string('rule', 'local_taskflow'),
        'formclass' => 'local_taskflow\\form\\rules\\rule',
        'stepidentifier' => 'rule',
        'formdata' => [
        ],
    ],
];
$includedsteps = get_config('local_taskflow', 'includedsteps');
$includedsteps = empty($includedsteps) ? [] : explode(',', $includedsteps);
if (
    empty($includedsteps)
    || in_array('filter', $includedsteps)
) {
    $stepcounter++;
    $data[$stepcounter] = [
        'recordid' => $id,
        'label' => get_string('filter', 'local_taskflow'),
        'formclass' => 'local_taskflow\\form\\filters\\filter',
        'stepidentifier' => 'filter',
        'formdata' => [
        ],
    ];
};

if (
    empty($includedsteps)
    || in_array('target', $includedsteps)
) {
    $stepcounter++;
    $data[$stepcounter] = [
        'recordid' => $id,
        'label' => get_string('targets', 'local_taskflow'),
        'formclass' => 'local_taskflow\\form\\targets\\target',
        'stepidentifier' => 'targets',
        'formdata' => [
        ],
    ];
}
if (
    empty($includedsteps)
    || in_array('message', $includedsteps)
) {
    $stepcounter++;
    $data[$stepcounter] = [
        'recordid' => $id,
        'label' => get_string('messages', 'local_taskflow'),
        'formclass' => 'local_taskflow\\\\form\\\\messages\\\\messages',
        'stepidentifier' => 'messages',
        'formdata' => [
        ],
    ];
};

$uniqueid = 'taskflow_editrule';
$formmanager = new editrulesmanager($uniqueid, $data, $id, true, true, $returnurl);
$formmanager->render();

echo $OUTPUT->footer();
