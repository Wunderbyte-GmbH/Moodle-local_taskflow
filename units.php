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
 * Organisation page: unit tree with members, rules per unit, inheritance and rule assignment.
 *
 * @package     local_taskflow
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_taskflow\form\person_switcher;
use local_taskflow\local\rules\unit_rule_assignment_service;
use local_taskflow\output\adapter_view_resolver;
use local_taskflow\taskflow_stringmanager;

require_once(__DIR__ . '/../../config.php');

$focusunitid = optional_param('id', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$ruleid = optional_param('ruleid', 0, PARAM_INT);
$unitid = optional_param('unitid', 0, PARAM_INT);

require_login();
$context = context_system::instance();
require_capability('local/taskflow:vieworganisation', $context);
$canassign = has_capability('local/taskflow:createrules', $context);

$pageurl = new moodle_url('/local/taskflow/units.php', $focusunitid ? ['id' => $focusunitid] : []);

if ($action === 'unassign' && !empty($ruleid) && !empty($unitid)) {
    require_sesskey();
    require_capability('local/taskflow:createrules', $context);
    (new unit_rule_assignment_service())->unassign($ruleid, $unitid, (int)$USER->id);
    redirect(
        new moodle_url('/local/taskflow/units.php', ['id' => $unitid]),
        taskflow_stringmanager::get_string('unassignruleunit_success'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$PAGE->set_context($context);
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('base');
$PAGE->set_title($SITE->fullname . ': ' . taskflow_stringmanager::get_string('organisationpage'));
$PAGE->set_heading(taskflow_stringmanager::get_string('organisationpage'));
$PAGE->navbar->add(taskflow_stringmanager::get_string('pluginname'), new moodle_url('/local/taskflow/index.php'));
$PAGE->navbar->add(taskflow_stringmanager::get_string('organisationpage'));

// The active adapter may replace the whole view (class and template); otherwise the core view is used.
$renderable = adapter_view_resolver::instance('unitspage', [$canassign, $focusunitid, $pageurl]);
$renderer = $PAGE->get_renderer('local_taskflow');
$data = $renderable->export_for_template($renderer);

// Person switcher of the navigation strip: the dashboard's scoped user search.
if (
    has_capability('local/taskflow:viewreports', $context)
    || has_capability('local/taskflow:issupervisor', $context)
) {
    $switcher = new person_switcher(
        new moodle_url('/local/taskflow/person.php'),
        null,
        'get',
        '',
        ['class' => 'local-taskflow-person-switcher']
    );
    $data['switcher'] = $switcher->render();
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template($renderable->get_template(), $data);
echo $OUTPUT->footer();
