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

use local_multistepform\manager;
use local_taskflow\multistepform\editrulesmanager;

require_once(__DIR__ . '/../../config.php');
require_login();

$context = context_system::instance();

// Make sure only an admin can see this.
if (!has_capability('moodle/site:config', $context)) {
    die;
}

$PAGE->set_context($context);
$PAGE->set_url('/local/taskflow/editrule.php');

// There might be a returnurl passed on. If not, we use this one.
$returnurl = optional_param('returnurl', '', PARAM_URL);
if (empty($returnurl)) {
    $returnurl = "$CFG->wwwroot/local/taskflow/editrule.php";
}

echo $OUTPUT->header();


$data = [
    1 => [
        'label' => get_string('rule', 'local_taskflow'),
        'formclass' => 'local_taskflow\\form\\rules\\rule',
        'stepidentifier' => 'rule',
        'formdata' => [
        ],
    ],
    2 => [
        'label' => get_string('filter', 'local_taskflow'),
        'formclass' => 'local_taskflow\\form\\filters\\filter',
        'stepidentifier' => 'filter',
        'formdata' => [
        ],
    ],
];

$uniqueid = 'taskflow_editrule';
$formmanager = new editrulesmanager($uniqueid, $data, 0, true, true, $returnurl);
$formmanager->render();

echo $OUTPUT->footer();
